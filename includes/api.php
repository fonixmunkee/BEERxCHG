<?php
// BEERxCHG JSON API (v1). Pure function of the request: api_handle() takes
// the method, path, credentials and body and returns [status, payload, headers],
// so tests can call it directly. api/index.php does the HTTP plumbing.
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

final class ApiError extends Exception
{
    public function __construct(public int $status, public string $errCode, string $message, public array $details = [])
    {
        parent::__construct($message);
    }
}

function api_iso(?int $ts): ?string
{
    return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
}

function api_participant(array $u): array
{
    return [
        'id'              => (int)$u['id'],
        'email'           => $u['email'],
        'name'            => $u['name'],
        'address'         => [
            'line1'  => $u['addr1'],
            'line2'  => $u['addr2'],
            'city'   => $u['city'],
            'state'  => $u['state'],
            'postal' => $u['postal'],
        ],
        'phone'           => $u['phone'],
        'favorite_style'  => $u['fav_style'] !== '' ? $u['fav_style'] : null,
        'hated_style'     => $u['hate_style'] !== '' ? $u['hate_style'] : null,
        'notes'           => $u['notes'],
        'heard_via'       => $u['heard_via'],
        'is_organizer'    => is_admin_email($u['email']),
        'is_founder'      => is_founder_email($u['email']),
        'approved'        => (int)$u['approved'] === 1,
        'profile_complete'=> profile_complete($u),
        'agreed_to_rules' => has_agreed($u),
        'agreed_at'       => has_agreed($u) ? api_iso((int)$u['agreed_at']) : null,
        'ready'           => is_eligible($u),
        'in_draw'         => in_draw((int)$u['id']),
        'shipped_at'      => api_iso($u['shipped_at'] !== null ? (int)$u['shipped_at'] : null),
        'tracking'        => $u['tracking'],
        'created_at'      => api_iso((int)$u['created_at']),
        'last_login_at'   => api_iso($u['last_login_at'] !== null ? (int)$u['last_login_at'] : null),
    ];
}

function api_settings(): array
{
    return [
        'draw_when'    => setting('draw_when'),
        'ship_before'  => setting('ship_before'),
        'signups_open' => signups_open(),
        'min_ounces'   => (int)cfg('min_ounces'),
    ];
}

function api_draw(): array
{
    $pairs = [];
    foreach (all_pairs() as [$g, $r]) {
        $pairs[] = [
            'giver'      => ['id' => (int)$g['id'], 'name' => $g['name']],
            'recipient'  => ['id' => (int)$r['id'], 'name' => $r['name']],
            'shipped_at' => api_iso($g['shipped_at'] !== null ? (int)$g['shipped_at'] : null),
            'tracking'   => $g['tracking'],
        ];
    }
    return [
        'drawn'    => draw_done(),
        'drawn_at' => draw_done() ? api_iso((int)setting('drawn_at')) : null,
        'pairs'    => $pairs,
    ];
}

function api_find_participant(string $id): array
{
    $u = ctype_digit($id) ? user_by_id((int)$id) : null;
    if (!$u) {
        throw new ApiError(404, 'not_found', "No participant with id $id.");
    }
    return $u;
}

function api_bool($v, string $field): bool
{
    if (!is_bool($v)) {
        throw new ApiError(422, 'validation_failed', "$field must be true or false.", [$field => 'must be a boolean']);
    }
    return $v;
}

/** PATCH /participants/{id}: change approval and/or profile fields. */
function api_patch_participant(array $u, array $in): array
{
    $map = ['name' => 'name', 'phone' => 'phone', 'favorite_style' => 'fav_style',
            'hated_style' => 'hate_style', 'notes' => 'notes'];
    $addrMap = ['line1' => 'addr1', 'line2' => 'addr2', 'city' => 'city', 'state' => 'state', 'postal' => 'postal'];
    $allowed = array_merge(array_keys($map), ['address', 'approved', 'email', 'is_organizer']);
    if ($unknown = array_diff(array_keys($in), $allowed)) {
        throw new ApiError(422, 'validation_failed', 'Unknown field(s): ' . implode(', ', $unknown) . '.');
    }

    $merged = $u;
    $touched = [];
    foreach ($map as $api => $col) {
        if (array_key_exists($api, $in)) {
            if (!is_string($in[$api])) {
                throw new ApiError(422, 'validation_failed', "$api must be a string.", [$api => 'must be a string']);
            }
            $merged[$col] = $in[$api];
            $touched[$col] = $api;
        }
    }
    if (array_key_exists('address', $in)) {
        if (!is_array($in['address'])) {
            throw new ApiError(422, 'validation_failed', 'address must be an object.');
        }
        foreach ($in['address'] as $k => $v) {
            if (!isset($addrMap[$k]) || !is_string($v)) {
                throw new ApiError(422, 'validation_failed', "address.$k is not a valid string field.");
            }
            $merged[$addrMap[$k]] = $v;
            $touched[$addrMap[$k]] = "address.$k";
        }
    }

    if (array_key_exists('email', $in)) {
        if (!is_string($in['email']) || normalize_email($in['email']) === null) {
            throw new ApiError(422, 'validation_failed', 'Some fields are invalid.', ['email' => "That email address doesn't look real."]);
        }
        $new = normalize_email($in['email']);
        if ($new !== $u['email']) {
            if (is_founder_email($u['email'])) {
                throw new ApiError(409, 'founder', 'Founder emails live in the config file. Change it there.');
            }
            if (user_by_email($new)) {
                throw new ApiError(409, 'conflict', 'Someone already signed up with that email.');
            }
        }
    }
    if (array_key_exists('is_organizer', $in)) {
        $org = api_bool($in['is_organizer'], 'is_organizer');
        if (!$org && is_founder_email($u['email'])) {
            throw new ApiError(409, 'founder', "They're a founder (listed in the config file). Can't demote them.");
        }
    }

    if ($touched) {
        [$clean, $errors] = validate_profile($merged, true);
        // Only complain about fields this request actually sent.
        $relevant = [];
        foreach ($touched as $col => $api) {
            if (isset($errors[$col])) {
                $relevant[$api] = $errors[$col];
            }
        }
        // Love == hate is reported on hate_style, but either field can cause it.
        if ((isset($touched['fav_style']) || isset($touched['hate_style']))
            && $clean['fav_style'] !== '' && $clean['fav_style'] === $clean['hate_style']) {
            $relevant[$touched['fav_style'] ?? 'hated_style'] = "You can't love AND hate the same style. Pick a lane.";
        }
        if ($relevant) {
            throw new ApiError(422, 'validation_failed', 'Some fields are invalid.', $relevant);
        }
        save_profile((int)$u['id'], $clean, true);   // never touches the rules agreement
    }

    if (array_key_exists('approved', $in)) {
        $approved = api_bool($in['approved'], 'approved');
        if (!$approved && in_draw((int)$u['id'])) {
            throw new ApiError(409, 'in_draw', "They're in the current draw. Reset the draw first.");
        }
        db()->prepare('UPDATE users SET approved = ? WHERE id = ?')->execute([$approved ? 1 : 0, $u['id']]);
    }
    if (isset($org)) {
        set_organizer($u, $org, ['id' => 0]);   // an API key isn't a person, so no "can't demote yourself"
    }
    if (isset($new)) {
        change_user_email($u, $new);
    }
    return api_participant(user_by_id((int)$u['id']));
}

/**
 * @return array{0:int,1:array,2:array} [status, payload, extra headers]
 */
function api_handle(string $method, string $path, string $authHeader, string $apiKeyHeader, string $rawBody, array $query = []): array
{
    try {
        $method = strtoupper($method);
        $path = trim($path, '/');

        // ---- auth
        $token = '';
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authHeader), $m)) {
            $token = $m[1];
        } elseif ($apiKeyHeader !== '') {
            $token = trim($apiKeyHeader);
        }
        if ($token === '') {
            throw new ApiError(401, 'unauthorized', 'Missing API key. Send "Authorization: Bearer bxk_…".');
        }
        $key = api_key_lookup($token);
        if (!$key) {
            throw new ApiError(401, 'unauthorized', "That API key is invalid or revoked. Nice try, though.");
        }
        $writing = !in_array($method, ['GET', 'HEAD'], true);
        if ($writing && $key['scope'] !== 'write') {
            throw new ApiError(403, 'forbidden', 'This key is read-only. Look, but don\'t touch.');
        }

        // ---- body
        $in = [];
        if ($writing && trim($rawBody) !== '') {
            if (strlen($rawBody) > 65536) {
                throw new ApiError(413, 'too_large', 'Request body over 64 KB. What are you sending?');
            }
            $in = json_decode($rawBody, true);
            if (!is_array($in) || array_is_list($in) && $in !== []) {
                throw new ApiError(400, 'bad_json', 'Body must be a JSON object.');
            }
        }

        $seg = $path === '' ? [] : explode('/', $path);
        if (($seg[0] ?? '') !== 'v1') {
            throw new ApiError(404, 'not_found', 'Unknown API version. Try /api/v1/.');
        }
        array_shift($seg);
        $route = implode('/', array_map(fn($s) => ctype_digit($s) ? '{id}' : $s, $seg));
        $id = null;
        foreach ($seg as $s) {
            if (ctype_digit($s)) $id = $s;
        }
        $allow = fn(array $methods) => in_array($method, $methods, true)
            ? null : throw new ApiError(405, 'method_not_allowed', "$method isn't allowed here.", ['allow' => $methods]);

        switch ($route) {
            case '':
                $allow(['GET']);
                return [200, [
                    'name'    => cfg('app_name') . ' API',
                    'version' => 'v1',
                    'key'     => ['name' => $key['name'], 'scope' => $key['scope']],
                    'docs'    => abs_url('docs/'),
                ], []];

            case 'status':
                $allow(['GET']);
                $users = all_users();
                return [200, [
                    'settings' => api_settings(),
                    'counts'   => [
                        'participants' => count($users),
                        'approved'     => count(array_filter($users, fn($u) => (int)$u['approved'] === 1)),
                        'ready'        => count(array_filter($users, 'is_eligible')),
                        'shipped'      => count(array_filter($users, fn($u) => $u['shipped_at'] !== null)),
                    ],
                    'drawn'    => draw_done(),
                ], []];

            case 'rules':
                $allow(['GET']);
                return [200, ['rules' => array_map(
                    fn($r, $i) => ['number' => $i + 1, 'rule' => $r[0], 'detail' => $r[1]],
                    rules(), array_keys(rules())
                )], []];

            case 'styles':
                $allow(['GET']);
                $styles = [];
                foreach (STYLES as $k => $label) $styles[] = ['id' => $k, 'label' => $label];
                return [200, ['styles' => $styles, 'hated_style_extra' => ['id' => HATE_NONE, 'label' => style_label(HATE_NONE)]], []];

            case 'settings':
                $allow(['GET', 'PATCH']);
                if ($method === 'PATCH') {
                    if ($unknown = array_diff(array_keys($in), ['draw_when', 'ship_before', 'signups_open'])) {
                        throw new ApiError(422, 'validation_failed', 'Unknown field(s): ' . implode(', ', $unknown) . '.');
                    }
                    if (array_key_exists('ship_before', $in)) {
                        $d = is_string($in['ship_before']) ? DateTime::createFromFormat('!Y-m-d', $in['ship_before']) : false;
                        if (!$d || $d->format('Y-m-d') !== $in['ship_before']) {
                            throw new ApiError(422, 'validation_failed', 'ship_before must be a YYYY-MM-DD date.', ['ship_before' => 'invalid date']);
                        }
                    }
                    if (array_key_exists('draw_when', $in) && (!is_string($in['draw_when']) || clean_line($in['draw_when'], 100) === '')) {
                        throw new ApiError(422, 'validation_failed', 'draw_when must be a non-empty string.', ['draw_when' => 'required']);
                    }
                    $open = array_key_exists('signups_open', $in) ? api_bool($in['signups_open'], 'signups_open') : null;
                    if (isset($in['ship_before'])) set_setting('ship_before', $in['ship_before']);
                    if (isset($in['draw_when'])) set_setting('draw_when', clean_line($in['draw_when'], 100));
                    if ($open !== null) set_setting('signups_open', $open ? '1' : '0');
                }
                return [200, api_settings(), []];

            case 'participants':
                $allow(['GET', 'POST']);
                if ($method === 'POST') {
                    $email = normalize_email(is_string($in['email'] ?? null) ? $in['email'] : '');
                    if (!$email) {
                        throw new ApiError(422, 'validation_failed', 'email is required and must be valid.', ['email' => 'invalid']);
                    }
                    if (user_by_email($email)) {
                        throw new ApiError(409, 'exists', 'That email is already signed up.');
                    }
                    $approved = array_key_exists('approved', $in) ? api_bool($in['approved'], 'approved') : false;
                    db()->prepare("INSERT INTO users(email, approved, created_at, heard_via) VALUES(?, ?, ?, 'api')")
                        ->execute([$email, $approved || is_admin_email($email) ? 1 : 0, time()]);
                    return [201, api_participant(user_by_email($email)), []];
                }
                $users = all_users();
                if (($query['ready'] ?? '') === 'true')  $users = array_filter($users, 'is_eligible');
                if (($query['ready'] ?? '') === 'false') $users = array_filter($users, fn($u) => !is_eligible($u));
                return [200, ['participants' => array_values(array_map('api_participant', $users))], []];

            case 'participants/{id}':
                $allow(['GET', 'PATCH', 'DELETE']);
                $u = api_find_participant($id);
                if ($method === 'PATCH') {
                    return [200, api_patch_participant($u, $in), []];
                }
                if ($method === 'DELETE') {
                    if ($why = delete_blocker($u)) {
                        throw new ApiError(409, in_draw((int)$u['id']) ? 'in_draw' : 'founder', $why);
                    }
                    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]);
                    return [204, [], []];
                }
                return [200, api_participant($u), []];

            case 'participants/{id}/shipment':
                $allow(['POST', 'DELETE']);
                $u = api_find_participant($id);
                if (!recipient_of((int)$u['id'])) {
                    throw new ApiError(409, 'not_in_draw', "They don't have a match, so they can't ship anything.");
                }
                if ($method === 'POST') {
                    $tracking = $in['tracking'] ?? '';
                    if (!is_string($tracking)) {
                        throw new ApiError(422, 'validation_failed', 'tracking must be a string.');
                    }
                    mark_shipped($u, $tracking);
                } else {
                    unmark_shipped($u);
                }
                return [200, api_participant(user_by_id((int)$u['id'])), []];

            case 'draw':
                $allow(['GET', 'POST', 'DELETE']);
                if ($method === 'POST') {
                    $notify = array_key_exists('notify', $in) ? api_bool($in['notify'], 'notify') : true;
                    try {
                        run_draw();
                    } catch (RuntimeException $e) {
                        throw new ApiError(409, 'draw_conflict', $e->getMessage());
                    }
                    $draw = api_draw();
                    $draw['emails_sent'] = $notify ? send_all_match_emails() : 0;
                    return [201, $draw, []];
                }
                if ($method === 'DELETE') {
                    if (($query['confirm'] ?? '') !== 'true') {
                        throw new ApiError(422, 'confirm_required', 'Add ?confirm=true. This wipes every match and shipping status.');
                    }
                    reset_draw();
                    return [204, [], []];
                }
                return [200, api_draw(), []];

            case 'draw/emails':
                $allow(['POST']);
                if (!draw_done()) {
                    throw new ApiError(409, 'not_drawn', 'There is no draw yet. Nothing to email.');
                }
                return [200, ['emails_sent' => send_all_match_emails()], []];
        }
        throw new ApiError(404, 'not_found', 'No such endpoint. Check the docs.');
    } catch (ApiError $e) {
        $headers = [];
        if ($e->status === 405) {
            $headers['Allow'] = implode(', ', $e->details['allow']);
            $e->details = [];
        }
        if ($e->status === 401) {
            $headers['WWW-Authenticate'] = 'Bearer realm="BEERxCHG"';
        }
        $err = ['code' => $e->errCode, 'message' => $e->getMessage()];
        if ($e->details) {
            $err['fields'] = $e->details;
        }
        return [$e->status, ['error' => $err], $headers];
    }
}
