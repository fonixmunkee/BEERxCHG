<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    ob_start(); // lets pages set cookies/headers after rendering has begun
}

const STYLES = [
    'ipa'            => 'IPA (West Coast / American)',
    'hazy'           => 'Hazy / New England IPA',
    'dipa'           => 'Double / Imperial IPA',
    'pale'           => 'Pale Ale',
    'lager'          => 'Lager / Pilsner',
    'wheat'          => 'Wheat / Hefeweizen / Witbier',
    'belgian'        => 'Belgian (Dubbel, Tripel, Quad)',
    'saison'         => 'Saison / Farmhouse',
    'sour'           => 'Sour / Gose / Wild Ale',
    'fruit'          => 'Fruited / Fruit Beer',
    'amber'          => 'Amber / Red Ale',
    'brown'          => 'Brown Ale',
    'porter'         => 'Porter',
    'stout'          => 'Stout',
    'imperial_stout' => 'Imperial / Pastry Stout',
    'barrel'         => 'Barrel-Aged / Barleywine',
];
const HATE_NONE = 'none';

const PROFILE_FIELDS = ['name', 'addr1', 'city', 'state', 'postal', 'phone', 'fav_style', 'hate_style'];

// ---------------------------------------------------------------- basics

function cfg(string $key)
{
    global $config;
    return $config[$key] ?? null;
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return cfg('base_url') . ltrim($path, '/');
}

function abs_url(string $path = ''): string
{
    return rtrim(cfg('site_url'), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 303);
    exit;
}

function post(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Single-line text: strip control chars, cap length. */
function clean_line(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s) ?? '';
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, $max);
}

/** Multi-line text: keep newlines, strip other control chars. */
function clean_text(string $s, int $max): string
{
    $s = str_replace("\r\n", "\n", $s);
    $s = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

function style_label(?string $key): string
{
    if ($key === HATE_NONE) {
        return "Nothing. They'll drink anything";
    }
    return STYLES[$key] ?? '—';
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

// ---------------------------------------------------------------- database

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0700, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/beerxchg.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $db): void
{
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT NOT NULL UNIQUE,
    name          TEXT NOT NULL DEFAULT '',
    addr1         TEXT NOT NULL DEFAULT '',
    addr2         TEXT NOT NULL DEFAULT '',
    city          TEXT NOT NULL DEFAULT '',
    state         TEXT NOT NULL DEFAULT '',
    postal        TEXT NOT NULL DEFAULT '',
    phone         TEXT NOT NULL DEFAULT '',
    fav_style     TEXT NOT NULL DEFAULT '',
    hate_style    TEXT NOT NULL DEFAULT '',
    notes         TEXT NOT NULL DEFAULT '',
    agreed_at     INTEGER,
    approved      INTEGER NOT NULL DEFAULT 0,
    shipped_at    INTEGER,
    tracking      TEXT NOT NULL DEFAULT '',
    created_at    INTEGER NOT NULL,
    last_login_at INTEGER
);
CREATE TABLE IF NOT EXISTS sessions (
    token_hash TEXT PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS login_codes (
    email      TEXT PRIMARY KEY,
    code_hash  TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    attempts   INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS code_sends (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    email   TEXT NOT NULL,
    ip      TEXT NOT NULL,
    sent_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS assignments (
    giver_id     INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    recipient_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS settings (
    k TEXT PRIMARY KEY,
    v TEXT NOT NULL
);
SQL);
    // Columns added after the first release
    add_column_if_missing($db, 'users', 'heard_via', "TEXT NOT NULL DEFAULT ''");
    add_column_if_missing($db, 'login_codes', 'answer', "TEXT NOT NULL DEFAULT ''");
    add_column_if_missing($db, 'users', 'agreed_rules', "TEXT NOT NULL DEFAULT ''");
    add_column_if_missing($db, 'users', 'is_organizer', 'INTEGER NOT NULL DEFAULT 0');
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS api_keys (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT NOT NULL,
    prefix       TEXT NOT NULL,
    key_hash     TEXT NOT NULL UNIQUE,
    scope        TEXT NOT NULL CHECK (scope IN ('read', 'write')),
    created_by   TEXT NOT NULL,
    created_at   INTEGER NOT NULL,
    last_used_at INTEGER,
    revoked_at   INTEGER
);
SQL);
}

function add_column_if_missing(PDO $db, string $table, string $column, string $decl): void
{
    $cols = array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    if (!in_array($column, $cols, true)) {
        $db->exec("ALTER TABLE $table ADD COLUMN $column $decl");
    }
}

function setting(string $k): string
{
    $defaults = [
        'signups_open' => '1',
        'draw_when'    => 'To be determined',
        'ship_before'  => cfg('default_ship_before'),
        'drawn_at'     => '',
    ];
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? ($defaults[$k] ?? '') : (string)$v;
}

function set_setting(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')
        ->execute([$k, $v]);
}

function secret_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $file = DATA_DIR . '/secret.key';
    if (!is_file($file)) {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0700, true);
        }
        $fh = fopen($file, 'x'); // fails if another request created it first
        if ($fh) {
            fwrite($fh, bin2hex(random_bytes(32)));
            fclose($fh);
            chmod($file, 0600);
        }
    }
    $key = trim((string)file_get_contents($file));
    if (strlen($key) < 64) {
        throw new RuntimeException('secret.key is missing or too short');
    }
    return $key;
}

// ---------------------------------------------------------------- settings helpers

function signups_open(): bool
{
    return setting('signups_open') === '1';
}

function draw_done(): bool
{
    return setting('drawn_at') !== '';
}

function ship_before_label(): string
{
    $ts = strtotime(setting('ship_before') . ' 12:00:00');
    return $ts ? date('l, F j, Y', $ts) : setting('ship_before');
}

// ---------------------------------------------------------------- cookies & CSRF

function set_cookie(string $name, string $value, int $expires): void
{
    if (PHP_SAPI === 'cli') { // tests
        if ($expires !== 0 && $expires < time()) {
            unset($_COOKIE[$name]);
        } else {
            $_COOKIE[$name] = $value;
        }
        return;
    }
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => cfg('base_url'),
        'secure'   => (bool)cfg('cookie_secure'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function csrf_token(): string
{
    $t = $_COOKIE['bxcsrf'] ?? '';
    if (!is_string($t) || !preg_match('/^[a-f0-9]{64}$/', $t)) {
        $t = bin2hex(random_bytes(32));
        set_cookie('bxcsrf', $t, 0);
        $_COOKIE['bxcsrf'] = $t;
    }
    return $t;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Call at the top of every POST handler. */
function check_csrf(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    // Browsers send Origin on POSTs; if present it must be this site.
    $okOrigin = $origin === ''
        || strtolower((string)parse_url($origin, PHP_URL_HOST)) === strtolower(explode(':', $host)[0]);
    $cookie = $_COOKIE['bxcsrf'] ?? '';
    $field  = $_POST['csrf'] ?? '';
    if (!$okOrigin || !is_string($cookie) || !is_string($field) || $cookie === '' || !hash_equals($cookie, $field)) {
        http_response_code(400);
        exit('That form expired. Go back, reload the page and try again.');
    }
}

// ---------------------------------------------------------------- mail

function send_mail(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $body .= "\n\n-- \n" . cfg('title') . ' · ' . cfg('tagline') . "\n" . cfg('site_url') . "\n";

    if (cfg('mail_mode') === 'log') {
        $entry = "==== " . date('c') . "\nTo: $to\nSubject: $subject\n\n$body\n";
        return file_put_contents(DATA_DIR . '/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    $from = cfg('mail_from');
    $headers = [
        'From'           => mb_encode_mimeheader(cfg('mail_from_name'), 'UTF-8', 'Q') . " <$from>",
        'MIME-Version'   => '1.0',
        'Content-Type'   => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
        'Auto-Submitted' => 'auto-generated',
    ];
    if (cfg('mail_reply_to') && filter_var(cfg('mail_reply_to'), FILTER_VALIDATE_EMAIL)) {
        $headers['Reply-To'] = cfg('mail_reply_to');
    }
    return mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'B'), $body, $headers, '-f' . $from);
}

// ---------------------------------------------------------------- users

function normalize_email(string $e): ?string
{
    $e = strtolower(trim($e));
    return (strlen($e) <= 254 && filter_var($e, FILTER_VALIDATE_EMAIL)) ? $e : null;
}

/**
 * Founders are the organizers listed in config.local.php. They're permanent:
 * nobody can demote, delete or rename them from the site or the API, so the
 * exchange can never end up with zero organizers.
 */
function is_founder_email(string $email): bool
{
    return in_array(strtolower($email), cfg('admin_emails'), true);
}

/** Organizer = founder, or promoted on the Organizer page (users.is_organizer). */
function is_admin_email(string $email): bool
{
    if (is_founder_email($email)) {
        return true;
    }
    $st = db()->prepare('SELECT is_organizer FROM users WHERE email = ?');
    $st->execute([strtolower($email)]);
    return (int)$st->fetchColumn() === 1;
}

/** @return string[] every organizer's email (founders first). */
function organizer_emails(): array
{
    $promoted = db()->query('SELECT email FROM users WHERE is_organizer = 1')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_merge(cfg('admin_emails'), $promoted)));
}

/** Promote or demote. Promoting also approves them. Throws RuntimeException with a human message. */
function set_organizer(array $target, bool $on, array $actor): void
{
    if (!$on && is_founder_email($target['email'])) {
        throw new RuntimeException("They're a founder (listed in the config file). Can't demote them from here.");
    }
    if (!$on && (int)$target['id'] === (int)$actor['id']) {
        throw new RuntimeException("You can't demote yourself. Get another organizer to do it.");
    }
    db()->prepare('UPDATE users SET is_organizer = ?' . ($on ? ', approved = 1' : '') . ' WHERE id = ?')
        ->execute([$on ? 1 : 0, $target['id']]);
}

/** Change someone's login email. Throws RuntimeException with a human message. */
function change_user_email(array $target, string $newEmail): void
{
    $new = normalize_email($newEmail);
    if ($new === null) {
        throw new RuntimeException("That email address doesn't look real.");
    }
    if ($new === $target['email']) {
        return;
    }
    if (is_founder_email($target['email'])) {
        throw new RuntimeException("Founder emails live in the config file. Change it there.");
    }
    if (user_by_email($new)) {
        throw new RuntimeException('Someone already signed up with that email.');
    }
    db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$new, $target['id']]);
    // Old sessions stay valid (they follow the person, not the address); stale login codes don't.
    db()->prepare('DELETE FROM login_codes WHERE email = ?')->execute([$target['email']]);
}

/** Why this person can't be deleted right now, or null if they can. */
function delete_blocker(array $target, ?array $actor = null): ?string
{
    if (is_founder_email($target['email'])) return "They're a founder (listed in the config file). Can't delete them.";
    if ($actor && (int)$target['id'] === (int)$actor['id']) return "You can't delete yourself from here.";
    if (in_draw((int)$target['id'])) return "They're in the current draw. Reset the draw first.";
    return null;
}

function logout_everywhere(int $userId): void
{
    db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
}

/**
 * Organizer edit of someone's profile. Unlike the person's own form, blanks are
 * fine (they may not have filled it in yet); anything filled in must be valid.
 * Never touches the rules agreement. Returns [clean, errors].
 */
function validate_profile_partial(array $in): array
{
    [$v, $e] = validate_profile($in, true);
    foreach (array_keys($e) as $field) {
        if (($v[$field] ?? '') === '' && $field !== 'rules') {
            unset($e[$field]);
        }
    }
    if ($v['fav_style'] !== '' && $v['fav_style'] === $v['hate_style']) {
        $e['hate_style'] = "You can't love AND hate the same style. Pick a lane.";
    }
    return [$v, $e];
}

function user_by_email(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

function user_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function all_users(): array
{
    return db()->query('SELECT * FROM users ORDER BY created_at')->fetchAll();
}

function profile_complete(array $u): bool
{
    foreach (PROFILE_FIELDS as $f) {
        if (($u[$f] ?? '') === '') {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------- rules

/** Rules with placeholders filled in: [[headline, detail], ...]. */
function rules(): array
{
    $vars = ['{oz}' => (string)cfg('min_ounces'), '{ship_before}' => ship_before_label()];
    return array_map(fn($r) => [strtr($r[0], $vars), strtr($r[1] ?? '', $vars)], cfg('rules'));
}

/** Fingerprint of the rule text; if the organizer edits the rules, old agreements stop counting. */
function rules_hash(): string
{
    return substr(hash('sha256', json_encode(cfg('rules'))), 0, 16);
}

function has_agreed(array $u): bool
{
    return $u['agreed_at'] !== null && ($u['agreed_rules'] ?? '') === rules_hash();
}

function is_eligible(array $u): bool
{
    return (int)$u['approved'] === 1 && has_agreed($u) && profile_complete($u);
}

/**
 * Validate the profile form. Returns [cleanValues, errors].
 * $lockAgreement: after the draw the agreement can't be withdrawn.
 */
function validate_profile(array $in, bool $lockAgreement): array
{
    $v = [
        'name'       => clean_line((string)($in['name'] ?? ''), 80),
        'addr1'      => clean_line((string)($in['addr1'] ?? ''), 120),
        'addr2'      => clean_line((string)($in['addr2'] ?? ''), 120),
        'city'       => clean_line((string)($in['city'] ?? ''), 60),
        'state'      => clean_line((string)($in['state'] ?? ''), 40),
        'postal'     => clean_line((string)($in['postal'] ?? ''), 15),
        'phone'      => clean_line((string)($in['phone'] ?? ''), 25),
        'fav_style'  => (string)($in['fav_style'] ?? ''),
        'hate_style' => (string)($in['hate_style'] ?? ''),
        'notes'      => clean_text((string)($in['notes'] ?? ''), 500),
    ];
    // One checkbox per rule: rules[0]=1, rules[1]=1, …  All must be checked.
    $posted = is_array($in['rules'] ?? null) ? $in['rules'] : [];
    $v['rules_checked'] = [];
    foreach (array_keys(cfg('rules')) as $i) {
        if (($posted[$i] ?? '') === '1') {
            $v['rules_checked'][] = $i;
        }
    }
    $v['agree'] = count($v['rules_checked']) === count(cfg('rules'));

    $e = [];
    if ($v['name'] === '')   $e['name'] = 'We need a name. For the label. Come on.';
    if ($v['addr1'] === '')  $e['addr1'] = "Santa can't ship to \"somewhere.\" Street address, please.";
    if ($v['city'] === '')   $e['city'] = 'City. You live in one.';
    if ($v['state'] === '')  $e['state'] = 'State. Pick one.';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{2,14}$/', $v['postal'])) $e['postal'] = "That ZIP doesn't look real.";
    $digits = preg_replace('/\D/', '', $v['phone']) ?? '';
    if (strlen($digits) < 10 || strlen($digits) > 15 || !preg_match('/^[0-9 ()+.\-]+$/', $v['phone'])) {
        $e['phone'] = "Phone number, with area code. Nobody's calling you, it's for the shipping label.";
    }
    if (!isset(STYLES[$v['fav_style']])) $e['fav_style'] = "Pick the style you love. One. It's not a hard question.";
    if (!isset(STYLES[$v['hate_style']]) && $v['hate_style'] !== HATE_NONE) {
        $e['hate_style'] = 'Pick the style you hate, or "Nothing" if you\'re easy.';
    } elseif ($v['hate_style'] === $v['fav_style']) {
        $e['hate_style'] = "You can't love AND hate the same style. Pick a lane.";
    }
    if (!$lockAgreement && !$v['agree']) {
        $e['rules'] = 'Check every rule. Yes, all of them. That is the deal.';
    }
    return [$v, $e];
}

function save_profile(int $userId, array $v, bool $lockAgreement): void
{
    $sql = 'UPDATE users SET name=?, addr1=?, addr2=?, city=?, state=?, postal=?, phone=?,
            fav_style=?, hate_style=?, notes=?';
    $args = [$v['name'], $v['addr1'], $v['addr2'], $v['city'], $v['state'], $v['postal'],
             $v['phone'], $v['fav_style'], $v['hate_style'], $v['notes']];
    if (!$lockAgreement) {
        $current = user_by_id($userId);
        if (!$v['agree']) {
            $agreedAt = null;
        } elseif ($current && has_agreed($current)) {
            $agreedAt = (int)$current['agreed_at'];   // keep the original timestamp
        } else {
            $agreedAt = time();
        }
        $sql .= ', agreed_at = ?, agreed_rules = ?';
        $args[] = $agreedAt;
        $args[] = $v['agree'] ? rules_hash() : '';
    }
    $args[] = $userId;
    db()->prepare($sql . ' WHERE id = ?')->execute($args);
}

// ---------------------------------------------------------------- login codes

function code_hash(string $email, string $code): string
{
    return hash_hmac('sha256', "login|$email|$code", secret_key());
}

/**
 * New sign-ups must say how they heard about the exchange. An answer passes
 * if it contains any configured keyword once lowercased and stripped to
 * letters, so "Leg Lamp", "LEG-LAMP" and "a major award: the leg lamp" all work.
 * With no keywords configured the challenge is off.
 */
/** Lowercase letters only: "Leg Lamp!" → "leglamp", "Milk & Cookies" → "milkandcookies". */
function challenge_flatten(string $s): string
{
    return preg_replace('/[^a-z]/', '', str_replace(['&', '+'], 'and', strtolower($s))) ?? '';
}

/**
 * Accepted answers. The Organizer page saves its own list in the settings
 * table; until someone does, the list in config.local.php is used.
 */
function challenge_keywords(): array
{
    $saved = setting('challenge_keywords');
    if ($saved !== '') {
        $list = json_decode($saved, true);
        if (is_array($list)) {
            return $list;
        }
    }
    return cfg('challenge_keywords');
}

/**
 * Validate the organizer's list (one answer per line). Returns [answers, error|null].
 * Each answer needs 3+ letters, or it would match nearly anything.
 */
function parse_challenge_keywords(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = clean_line($line, 60);
        if ($line === '') continue;
        if (strlen(challenge_flatten($line)) < 3) {
            return [[], "“{$line}” is too short. Answers need at least 3 letters, or practically anything would match."];
        }
        $out[challenge_flatten($line)] ??= $line;   // de-dupe on the matching form; first spelling wins
    }
    if (!$out) {
        return [[], 'You need at least one answer, or nobody new can get in.'];
    }
    if (count($out) > 50) {
        return [[], "50 answers max. It's a beer exchange, not a password manager."];
    }
    return [array_values($out), null];
}

function challenge_enabled(): bool
{
    return (bool)challenge_keywords();
}

function challenge_ok(string $answer): bool
{
    if (!challenge_enabled()) {
        return true;
    }
    $flat = challenge_flatten($answer);
    foreach (challenge_keywords() as $kw) {
        $kw = challenge_flatten((string)$kw);
        if ($kw !== '' && str_contains($flat, $kw)) {
            return true;
        }
    }
    return false;
}

/** Returns 'sent' | 'challenge' | 'rate' | 'closed' | 'mailfail'. */
function request_login_code(string $email, string $answer = ''): string
{
    $db = db();
    $now = time();
    $db->prepare('DELETE FROM code_sends WHERE sent_at < ?')->execute([$now - 86400]);
    $db->prepare('DELETE FROM login_codes WHERE expires_at < ?')->execute([$now]);

    $isNew = !user_by_email($email) && !is_admin_email($email);
    if ($isNew && !signups_open()) {
        return 'closed';
    }
    if ($isNew) {
        // Limit guessing: wrong answers are logged against the IP.
        $st = $db->prepare("SELECT COUNT(*) FROM code_sends WHERE email = '!challenge' AND ip = ? AND sent_at > ?");
        $st->execute([client_ip(), $now - 3600]);
        if ((int)$st->fetchColumn() >= 10) {
            return 'rate';
        }
        if (!challenge_ok($answer)) {
            $db->prepare("INSERT INTO code_sends(email, ip, sent_at) VALUES('!challenge', ?, ?)")
                ->execute([client_ip(), $now]);
            return 'challenge';
        }
    }

    $st = $db->prepare('SELECT COUNT(*) FROM code_sends WHERE email = ? AND sent_at > ?');
    $st->execute([$email, $now - 3600]);
    if ((int)$st->fetchColumn() >= cfg('codes_per_email_hour')) {
        return 'rate';
    }
    $st = $db->prepare('SELECT COUNT(*) FROM code_sends WHERE ip = ? AND sent_at > ?');
    $st->execute([client_ip(), $now - 3600]);
    if ((int)$st->fetchColumn() >= cfg('codes_per_ip_hour')) {
        return 'rate';
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $ttl = (int)cfg('code_ttl_minutes');
    $db->prepare('INSERT OR REPLACE INTO login_codes(email, code_hash, expires_at, attempts, answer) VALUES(?, ?, ?, 0, ?)')
        ->execute([$email, code_hash($email, $code), $now + $ttl * 60, clean_line($answer, 100)]);
    $db->prepare('INSERT INTO code_sends(email, ip, sent_at) VALUES(?, ?, ?)')
        ->execute([$email, client_ip(), $now]);

    $body = "Your " . cfg('title') . " secret code is:\n\n    $code\n\n"
          . "It's good for $ttl minutes, so don't go make a sandwich.\n\n"
          . "Didn't ask for this? Then somebody typed your email into a beer website. "
          . "Ignore it. They can't do anything without the code.";
    return send_mail($email, cfg('title') . " login code: $code", $body) ? 'sent' : 'mailfail';
}

/** On success returns the challenge answer given with the code request ('' if none); null on failure. */
function verify_login_code(string $email, string $code): ?string
{
    $db = db();
    $code = preg_replace('/\D/', '', $code) ?? '';
    $st = $db->prepare('SELECT * FROM login_codes WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();
    if (!$row || $row['expires_at'] < time() || $row['attempts'] >= cfg('code_max_attempts')) {
        return null;
    }
    $db->prepare('UPDATE login_codes SET attempts = attempts + 1 WHERE email = ?')->execute([$email]);
    if (strlen($code) === 6 && hash_equals($row['code_hash'], code_hash($email, $code))) {
        $db->prepare('DELETE FROM login_codes WHERE email = ?')->execute([$email]);
        return (string)$row['answer'];
    }
    return null;
}

// ---------------------------------------------------------------- sessions

/** Log in (creating the user on first login). Returns the user row. */
function login_user(string $email, string $heardVia = ''): array
{
    $db = db();
    $now = time();
    $user = user_by_email($email);
    if (!$user) {
        $db->prepare('INSERT INTO users(email, approved, created_at, heard_via) VALUES(?, ?, ?, ?)')
            ->execute([$email, is_admin_email($email) ? 1 : 0, $now, clean_line($heardVia, 100)]);
        $user = user_by_email($email);
        notify_admins_new_signup($email, $heardVia);
    }
    $db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([$now, $user['id']]);
    $db->prepare('DELETE FROM sessions WHERE expires_at < ?')->execute([$now]);

    $token = bin2hex(random_bytes(32));
    $expires = $now + (int)cfg('session_days') * 86400;
    $db->prepare('INSERT INTO sessions(token_hash, user_id, created_at, expires_at) VALUES(?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $user['id'], $now, $expires]);
    set_cookie('bxsess', $token, $expires);
    current_user(true);
    return $user;
}

function current_user(bool $refresh = false): ?array
{
    static $cache = false;
    if ($cache !== false && !$refresh) {
        return $cache;
    }
    $cache = null;
    $token = $_COOKIE['bxsess'] ?? '';
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
        $st = db()->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id
                             WHERE s.token_hash = ? AND s.expires_at > ?');
        $st->execute([hash('sha256', $token), time()]);
        $cache = $st->fetch() ?: null;
    }
    return $cache;
}

function logout_user(): void
{
    $token = $_COOKIE['bxsess'] ?? '';
    if (is_string($token) && $token !== '') {
        db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
    set_cookie('bxsess', '', time() - 3600);
    current_user(true);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('');
    }
    return $u;
}

/** Logged in AND approved (organizers always count as approved). */
function is_approved(array $u): bool
{
    return (int)$u['approved'] === 1 || is_admin_email($u['email']);
}

function require_approved(): array
{
    $u = require_login();
    if (!is_approved($u)) {
        redirect('me.php?msg=notapproved');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (!is_admin_email($u['email'])) {
        http_response_code(403);
        exit('Organizers only.');
    }
    return $u;
}

function notify_admins_new_signup(string $email, string $heardVia = ''): void
{
    $how = $heardVia !== '' ? "They heard about it via: " . clean_line($heardVia, 100) . "\n\n" : '';
    foreach (organizer_emails() as $admin) {
        if ($admin !== $email) {
            send_mail($admin, cfg('title') . ': new sign-up waiting for approval',
                "$email just signed up for the beer exchange. Friend or freeloader? You decide.\n\n{$how}Approve them here: " . abs_url('admin.php'));
        }
    }
}

// ---------------------------------------------------------------- API keys

const API_SCOPES = ['read' => 'Read only', 'write' => 'Read / write'];

/** Creates a key and returns the plaintext token. It is shown once and only its hash is stored. */
function create_api_key(string $name, string $scope, string $createdBy): string
{
    if (!isset(API_SCOPES[$scope])) {
        throw new RuntimeException('Scope must be read or write.');
    }
    $name = clean_line($name, 60);
    if ($name === '') {
        throw new RuntimeException('Give the key a name so you know what it is later.');
    }
    $token = 'bxk_' . bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO api_keys(name, prefix, key_hash, scope, created_by, created_at) VALUES(?, ?, ?, ?, ?, ?)')
        ->execute([$name, substr($token, 0, 12), hash('sha256', $token), $scope, $createdBy, time()]);
    return $token;
}

function list_api_keys(): array
{
    return db()->query('SELECT * FROM api_keys ORDER BY revoked_at IS NOT NULL, created_at DESC')->fetchAll();
}

function revoke_api_key(int $id): void
{
    db()->prepare('UPDATE api_keys SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([time(), $id]);
}

/** Returns the active key row for a plaintext token, or null. */
function api_key_lookup(string $token): ?array
{
    if (!preg_match('/^bxk_[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL');
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    db()->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ?')->execute([time(), $row['id']]);
    return $row;
}

// ---------------------------------------------------------------- the draw

function eligible_users(): array
{
    return array_values(array_filter(all_users(), 'is_eligible'));
}

/**
 * Shuffle everyone into one big loop (A→B→C→…→A): nobody draws themselves
 * and, with 3+ people, no two people just swap gifts.
 * Returns [[giver, recipient], ...].
 */
function run_draw(): array
{
    if (draw_done()) {
        throw new RuntimeException('The draw already happened. Reset it first.');
    }
    $people = eligible_users();
    $n = count($people);
    if ($n < 2) {
        throw new RuntimeException('Need at least 2 approved participants with complete profiles.');
    }
    for ($i = $n - 1; $i > 0; $i--) { // Fisher–Yates with a CSPRNG
        $j = random_int(0, $i);
        [$people[$i], $people[$j]] = [$people[$j], $people[$i]];
    }
    $pairs = [];
    $db = db();
    $db->beginTransaction();
    try {
        $ins = $db->prepare('INSERT INTO assignments(giver_id, recipient_id) VALUES(?, ?)');
        for ($i = 0; $i < $n; $i++) {
            $giver = $people[$i];
            $recipient = $people[($i + 1) % $n];
            $ins->execute([$giver['id'], $recipient['id']]);
            $pairs[] = [$giver, $recipient];
        }
        set_setting('drawn_at', (string)time());
        set_setting('signups_open', '0');
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $pairs;
}

function reset_draw(): void
{
    $db = db();
    $db->beginTransaction();
    $db->exec('DELETE FROM assignments');
    $db->exec("UPDATE users SET shipped_at = NULL, tracking = ''");
    $db->exec("DELETE FROM settings WHERE k = 'drawn_at'");
    $db->commit();
}

/** [[giver, recipient], ...] for the current draw. */
function all_pairs(): array
{
    $rows = db()->query('SELECT giver_id, recipient_id FROM assignments')->fetchAll();
    $pairs = [];
    foreach ($rows as $r) {
        $pairs[] = [user_by_id((int)$r['giver_id']), user_by_id((int)$r['recipient_id'])];
    }
    return $pairs;
}

function recipient_of(int $giverId): ?array
{
    $st = db()->prepare('SELECT u.* FROM assignments a JOIN users u ON u.id = a.recipient_id WHERE a.giver_id = ?');
    $st->execute([$giverId]);
    return $st->fetch() ?: null;
}

function santa_of(int $recipientId): ?array
{
    $st = db()->prepare('SELECT u.* FROM assignments a JOIN users u ON u.id = a.giver_id WHERE a.recipient_id = ?');
    $st->execute([$recipientId]);
    return $st->fetch() ?: null;
}

function in_draw(int $userId): bool
{
    $st = db()->prepare('SELECT 1 FROM assignments WHERE giver_id = ? OR recipient_id = ?');
    $st->execute([$userId, $userId]);
    return (bool)$st->fetchColumn();
}

function send_match_email(array $giver, array $recipient): bool
{
    $oz = cfg('min_ounces');
    $body = "The robot has spoken. No take-backs.\n\n"
          . "You're on the hook for at least $oz oz of beer for: {$recipient['name']}\n\n"
          . "  Loves: " . style_label($recipient['fav_style']) . "\n"
          . "  Hates: " . style_label($recipient['hate_style']) . "  (seriously, don't)\n\n"
          . "Their address and phone number are behind the login, because we're not animals:\n"
          . abs_url('me.php') . "\n\n"
          . "It ships BEFORE " . ship_before_label() . ". Before. Not on.\n"
          . "Hit \"Mark it shipped\" on the site when it's out the door so they know it's coming.\n\n"
          . "Keep it secret. Keep it cold.";
    return send_mail($giver['email'], cfg('title') . ": you've got a match. Try not to blow it.", $body);
}

/** Returns how many emails went out. */
function send_all_match_emails(): int
{
    $sent = 0;
    foreach (all_pairs() as [$giver, $recipient]) {
        $sent += send_match_email($giver, $recipient) ? 1 : 0;
    }
    return $sent;
}

function mark_shipped(array $giver, string $tracking): void
{
    db()->prepare('UPDATE users SET shipped_at = ?, tracking = ? WHERE id = ?')
        ->execute([time(), clean_line($tracking, 60), $giver['id']]);
    $recipient = recipient_of((int)$giver['id']);
    if ($recipient) {
        $t = clean_line($tracking, 60);
        send_mail($recipient['email'], cfg('title') . ': your beer is on the way. Act surprised.',
            "Your Secret Santa actually shipped your beer. Christmas miracles are real.\n\n"
            . ($t !== '' ? "Tracking: $t\n\n" : '')
            . "Details: " . abs_url('me.php'));
    }
}

function unmark_shipped(array $giver): void
{
    db()->prepare("UPDATE users SET shipped_at = NULL, tracking = '' WHERE id = ?")->execute([$giver['id']]);
}

// ---------------------------------------------------------------- layout

function send_page_headers(): void
{
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; "
         . "script-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

function page_start(string $title, ?array $user = null): void
{
    send_page_headers();
    csrf_token(); // make sure the cookie is set before any form renders
    $app  = h(cfg('title'));
    $t    = h($title);
    $css  = h(url('assets/style.css')) . '?v=7';
    $font = h(url('assets/fonts/mountains-of-christmas-700.woff2'));
    $home = h(url($user ? 'me.php' : ''));
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#6f2620">
<title>{$t} · {$app}</title>
<link rel="preload" href="{$font}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{$css}">
</head>
<body>
<header class="top">
  <a class="brand" href="{$home}">BEER<span class="x">x</span>CHG</a>
HTML;
    if ($user) {
        echo '<nav>';
        echo '<a href="' . h(url('me.php')) . '">My exchange</a>';
        if (is_approved($user)) {
            echo '<a href="' . h(url('docs/')) . '">API docs</a>';
        }
        if (is_admin_email($user['email'])) {
            echo '<a href="' . h(url('admin.php')) . '">Organizer</a>';
        }
        echo '<form method="post" action="' . h(url('logout.php')) . '" class="inline">'
           . csrf_field() . '<button type="submit" class="linkish">Log out</button></form>';
        echo '</nav>';
    }
    echo "<div class=\"lights\" aria-hidden=\"true\"></div>\n</header>\n<main>\n";
}

function page_end(): void
{
    echo "</main>\n<footer><p class=\"quip\">“You’ll shoot your liver out, kid.”</p><p class=\"credit\">"
       . h(cfg('title')) . ' · ' . h(cfg('tagline')) . ' · est. ' . h(cfg('established')) . "</p></footer>\n</body>\n</html>\n";
}

/** Fixed flash messages keyed by ?msg= so nothing user-supplied is echoed. */
function flash(): string
{
    $messages = [
        'welcome'   => ['ok',  "You're in the door. Now fill out the form below or you're not in the draw. That's how forms work."],
        'hello'     => ['ok',  'Oh, it\'s you again. Welcome back.'],
        'saved'     => ['ok',  'Saved. Look at you, following directions.'],
        'shipped'   => ['ok',  "Shipped! Fra-GEE-lay. Your giftee's been told it's coming, so no backsies."],
        'unshipped' => ['ok',  'Un-shipped. Hope that was a misclick and not a confession.'],
        'out'       => ['ok',  "Logged out. Go drink your Ovaltine."],
        'drawn'     => ['ok',  'The draw is done and match emails went out. No take-backs.'],
        'reset'     => ['ok',  "Draw reset. Hope you've got a good explanation for everybody."],
        'resent'    => ['ok',  'Match emails re-sent, for everyone who "never got it."'],
        'settings'  => ['ok',  'Settings saved.'],
        'updated'   => ['ok',  'Participant updated.'],
        'removed'   => ['ok',  'Gone. Like they were never here.'],
        'promoted'  => ['ok',  'Promoted to organizer. With great power comes great responsibility, or whatever.'],
        'demoted'   => ['ok',  'Back to the rank and file.'],
        'usersaved' => ['ok',  'Saved. Hope you spelled it right.'],
        'answers'   => ['ok',  'Challenge answers saved. The bouncer has the new list.'],
        'notapproved' => ['err', "The API docs are for approved people. Sit tight until an organizer vouches for you."],
        'kicked'    => ['ok',  'Logged out on every device. They will need a new code.'],
        'revoked'   => ['ok',  'Key revoked. Whatever was using it just got a 401 to the face.'],
        'expired'   => ['err', 'That code request expired. Put your email in again. It happens.'],
    ];
    $key = $_GET['msg'] ?? '';
    if (!is_string($key) || !isset($messages[$key])) {
        return '';
    }
    [$kind, $text] = $messages[$key];
    return '<p class="flash ' . $kind . '">' . h($text) . '</p>';
}

/** Radio buttons styled as tappable chips. */
function style_chips(string $name, string $selected, bool $withNone, string $kind): string
{
    $opts = STYLES;
    if ($withNone) {
        $opts = [HATE_NONE => "Nothing. I'm not picky."] + $opts;
    }
    $out = '';
    foreach ($opts as $key => $label) {
        $out .= '<label class="chip ' . $kind . '"><input type="radio" name="' . h($name) . '" value="' . h($key) . '"'
              . ($selected === $key ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
    }
    return $out;
}

function bad(array $errors, string $k): string
{
    return isset($errors[$k]) ? ' class="bad"' : '';
}

function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<span class="err-text">' . h($errors[$key]) . '</span>' : '';
}
