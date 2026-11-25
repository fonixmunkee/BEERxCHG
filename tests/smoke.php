<?php
// End-to-end check of the library in ONE php process, against a scratch DB.
// Usage: make test   (or: BX_DATA_DIR=/some/tmp BX_MAIL_MODE=log php tests/smoke.php)
declare(strict_types=1);

if (!getenv('BX_DATA_DIR') || getenv('BX_MAIL_MODE') !== 'log') {
    fwrite(STDERR, "Refusing to run: set BX_DATA_DIR to a scratch dir and BX_MAIL_MODE=log\n");
    exit(2);
}
require __DIR__ . '/../includes/lib.php';

$fails = 0;
function ok(bool $cond, string $what): void
{
    global $fails;
    echo ($cond ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$cond) $fails++;
}
function last_code(): string
{
    preg_match_all('/login code: (\d{6})/', (string)file_get_contents(DATA_DIR . '/mail.log'), $m);
    return end($m[1]) ?: '';
}

$admin = cfg('admin_emails')[0] ?? 'admin@example.com';
$GLOBALS['config']['admin_emails'] = [$admin];
$GLOBALS['config']['challenge_keywords'] = ['leglamp', 'ovaltine', 'redryder', 'bumpus', 'milkandcookies'];
const OK_ANSWER = 'Ovaltine';

echo "challenge question\n";
foreach (['Ovaltine', 'drink more ovaltine', 'Leg Lamp', 'LEG-LAMP', 'Red Ryder', "red ryder's bb gun",
          'Milk & Cookies', 'milk + cookies', 'the Bumpus hounds ate it'] as $good) {
    ok(challenge_ok($good), "accepts: $good");
}
foreach (['', 'google', 'a friend', 'facebook', 'lamp'] as $bad) {
    ok(!challenge_ok($bad), "rejects: '$bad'");
}
ok(request_login_code('stranger@example.com', 'twitter') === 'challenge', 'new email with wrong answer gets no code');
ok(request_login_code('stranger@example.com') === 'challenge', 'new email with no answer gets no code');
ok(!file_exists(DATA_DIR . '/mail.log'), 'no email sent for failed challenge');
ok(request_login_code($admin) === 'sent', 'organizer skips the challenge');

echo "login codes\n";
ok(normalize_email('  Bob@Example.COM ') === 'bob@example.com', 'emails are normalized');
ok(normalize_email('nope') === null, 'bad email rejected');
ok(request_login_code('bob@example.com', "Red Ryder") === 'sent', 'code sent');
$code = last_code();
ok(strlen($code) === 6, 'code is 6 digits');
$wrong = $code === '000000' ? '111111' : '000000';
ok(verify_login_code('bob@example.com', $wrong) === null, 'wrong code rejected');
$heard = verify_login_code('bob@example.com', $code);
ok($heard === "Red Ryder", 'right code accepted, returns challenge answer');
ok(verify_login_code('bob@example.com', $code) === null, 'code is single-use');

request_login_code('carol@example.com', OK_ANSWER);
$c = last_code();
for ($i = 0; $i < 5; $i++) verify_login_code('carol@example.com', $c === '000000' ? '111111' : '000000');
ok(verify_login_code('carol@example.com', $c) === null, 'locked out after 5 wrong attempts');

for ($i = 0; $i < 5; $i++) request_login_code('spam@example.com', OK_ANSWER);
ok(request_login_code('spam@example.com', OK_ANSWER) === 'rate', 'per-email rate limit');

echo "sessions\n";
$bob = login_user('bob@example.com', (string)$heard);
ok($bob['heard_via'] === "Red Ryder", 'challenge answer saved on the user');
ok((int)$bob['approved'] === 0, 'new users need approval');
ok((current_user(true)['email'] ?? '') === 'bob@example.com', 'session cookie logs you in');
$st = db()->query('SELECT expires_at - created_at FROM sessions');
ok((int)$st->fetchColumn() === 7 * 86400, 'session lasts exactly 7 days');
logout_user();
ok(current_user(true) === null, 'logout ends the session');
$a = login_user($admin);
ok((int)$a['approved'] === 1, 'admins are auto-approved');
ok(str_contains((string)file_get_contents(DATA_DIR . '/mail.log'), 'new sign-up'), 'admin notified of sign-up');

echo "profile validation\n";
$good = ['name' => 'Bob', 'addr1' => '1 Main St', 'city' => 'Akron', 'state' => 'OH', 'postal' => '44301',
         'phone' => '(330) 555-0100', 'fav_style' => 'stout', 'hate_style' => 'sour', 'rules' => array_fill_keys(array_keys(cfg('rules')), '1')];
[, $e] = validate_profile($good, false);
ok($e === [], 'valid profile passes');
[, $e] = validate_profile(['hate_style' => 'stout'] + $good, false);
ok(isset($e['hate_style']), "can't love and hate the same style");
[, $e] = validate_profile(['fav_style' => '<script>'] + $good, false);
ok(isset($e['fav_style']), 'unknown style rejected');
[, $e] = validate_profile(['rules' => []] + $good, false);
ok(isset($e['rules']), 'rules required');
$missingOne = $good; unset($missingOne['rules'][count(cfg('rules')) - 1]);
[, $e] = validate_profile($missingOne, false);
ok(isset($e['rules']), 'every single rule must be checked');
[, $e] = validate_profile(['rules' => []] + $good, true);
ok(!isset($e['rules']), 'rules not re-required after the draw locks them');
ok(count(rules()) >= 2 && str_contains(implode(' ', array_merge(...rules())), (string)cfg('min_ounces')), 'rules fill in the ounces');
[, $e] = validate_profile(['phone' => '555'] + $good, false);
ok(isset($e['phone']), 'short phone rejected');
[$v, ] = validate_profile(['name' => "Evil\r\nBcc: x@y.z"] + $good, false);
ok(!str_contains($v['name'], "\n"), 'newlines stripped from single-line fields');

echo "draw\n";
$names = ['Ann', 'Ben', 'Cat', 'Dan', 'Eve', 'Fay'];
foreach ($names as $n) {
    $u = login_user(strtolower($n) . '@example.com');
    [$v, ] = validate_profile(['name' => $n] + $good, false);
    save_profile((int)$u['id'], $v, false);
    db()->prepare('UPDATE users SET approved = 1 WHERE id = ?')->execute([$u['id']]);
}
// bob: complete but not approved → excluded
[$v, ] = validate_profile($good, false);
save_profile((int)$bob['id'], $v, false);

// editing the rules un-agrees everyone until they re-check
$saved = $GLOBALS['config']['rules'];
$GLOBALS['config']['rules'][] = ['A brand new rule.', ''];
ok(count(eligible_users()) === 0, 'changing the rules makes everyone re-agree');
$GLOBALS['config']['rules'] = $saved;
ok(count(eligible_users()) === 6, 'restoring the rules restores eligibility');

$pairs = run_draw();
ok(count($pairs) === 6, '6 eligible people drawn (unapproved + incomplete excluded)');
$givers = array_map(fn($p) => $p[0]['id'], $pairs);
$recips = array_map(fn($p) => $p[1]['id'], $pairs);
ok(count(array_unique($givers)) === 6 && count(array_unique($recips)) === 6, 'everyone gives once and gets once');
ok(!array_filter($pairs, fn($p) => $p[0]['id'] === $p[1]['id']), 'nobody draws themselves');
$map = array_combine($givers, $recips);
$seen = []; $cur = $givers[0];
while (!isset($seen[$cur])) { $seen[$cur] = 1; $cur = $map[$cur]; }
ok(count($seen) === 6, 'it is one single loop (no mutual swaps)');
ok(!signups_open(), 'draw closes sign-ups');
ok(request_login_code('newbie@example.com', OK_ANSWER) === 'closed', 'strangers blocked after draw');
ok(request_login_code('ann@example.com') === 'sent', 'participants can still log in');
try { run_draw(); ok(false, 'second draw blocked'); } catch (RuntimeException $e) { ok(true, 'second draw blocked'); }

ok(send_all_match_emails() === 6, 'match emails sent');
$log = (string)file_get_contents(DATA_DIR . '/mail.log');
ok(!str_contains($log, '1 Main St'), 'addresses never go out by email');

[$g, $r] = $pairs[0];
mark_shipped($g, '1Z999');
ok((santa_of((int)$r['id'])['tracking'] ?? '') === '1Z999', 'recipient sees tracking');
ok(str_contains((string)file_get_contents(DATA_DIR . '/mail.log'), 'on the way'), 'recipient emailed on ship');

reset_draw();
ok(!draw_done() && all_pairs() === [], 'reset clears the draw');

echo "organizer-edited challenge answers\n";
[$a, $why] = parse_challenge_keywords("Ovaltine\n  Leg Lamp \n\nLEGLAMP\nRalphie");
ok($why === null && $a === ['Ovaltine', 'Leg Lamp', 'Ralphie'], 'parse: trims, skips blanks, de-dupes on matching form');
ok(parse_challenge_keywords("ok\nOvaltine")[1] !== null, 'parse: rejects answers under 3 letters');
ok(parse_challenge_keywords(" \n ")[1] !== null, 'parse: rejects an empty list');
set_setting('challenge_keywords', json_encode(['Fudge', 'Scut Farkus']));
ok(challenge_ok('oh FUDGE') && challenge_ok('scut farkus') && !challenge_ok('Ovaltine'), 'saved list replaces the config list');
set_setting('challenge_keywords', '');
ok(challenge_ok('Ovaltine'), 'no saved list → config list again');

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
