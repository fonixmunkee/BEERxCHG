<?php
// API checks in ONE php process against a scratch DB.
// Usage: BX_DATA_DIR=/some/tmp BX_MAIL_MODE=log php tests/api.php
declare(strict_types=1);

if (!getenv('BX_DATA_DIR') || getenv('BX_MAIL_MODE') !== 'log') {
    fwrite(STDERR, "Refusing to run: set BX_DATA_DIR to a scratch dir and BX_MAIL_MODE=log\n");
    exit(2);
}
require __DIR__ . '/../includes/api.php';

$fails = 0;
function ok(bool $cond, string $what): void
{
    global $fails;
    echo ($cond ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$cond) $fails++;
}
function call(string $method, string $path, ?string $key, $body = null, array $query = []): array
{
    [$status, $payload] = api_handle($method, $path, $key ? "Bearer $key" : '', '',
        $body === null ? '' : (is_string($body) ? $body : json_encode($body)), $query);
    return [$status, $payload];
}

$GLOBALS['config']['admin_emails'] = ['boss@example.com'];
$admin = 'boss@example.com';
$ro = create_api_key('reader', 'read', $admin);
$rw = create_api_key('writer', 'write', $admin);

echo "keys\n";
ok(str_starts_with($ro, 'bxk_') && strlen($ro) === 52, 'key format bxk_ + 48 hex');
ok(!db()->query("SELECT COUNT(*) FROM api_keys WHERE key_hash = '$ro'")->fetchColumn(), 'plaintext key not stored');
try { create_api_key('x', 'admin', $admin); ok(false, 'bad scope rejected'); } catch (RuntimeException $e) { ok(true, 'bad scope rejected'); }
try { create_api_key('', 'read', $admin); ok(false, 'name required'); } catch (RuntimeException $e) { ok(true, 'name required'); }

echo "auth\n";
ok(call('GET', 'v1/status', null)[0] === 401, 'no key → 401');
ok(call('GET', 'v1/status', 'bxk_' . str_repeat('0', 48))[0] === 401, 'unknown key → 401');
ok(api_handle('GET', 'v1/status', '', $ro, '')[0] === 200, 'X-API-Key header works');
[$s, $p] = call('GET', 'v1', $ro);
ok($s === 200 && $p['key']['scope'] === 'read', 'GET /v1 reports key scope');
ok(call('PATCH', 'v1/settings', $ro, ['signups_open' => false])[0] === 403, 'read key cannot write → 403');
ok(db()->query("SELECT last_used_at FROM api_keys WHERE name = 'reader'")->fetchColumn() > 0, 'last_used_at recorded');

echo "routing & validation\n";
ok(call('GET', 'v1/nope', $ro)[0] === 404, 'unknown route → 404');
ok(call('GET', 'v2/status', $ro)[0] === 404, 'unknown version → 404');
[$s] = api_handle('DELETE', 'v1/status', "Bearer $rw", '', '');
ok($s === 405, 'wrong method → 405');
ok(call('POST', 'v1/participants', $rw, 'not json')[0] === 400, 'bad JSON → 400');
ok(call('POST', 'v1/participants', $rw, ['email' => 'nope'])[0] === 422, 'bad email → 422');

echo "settings\n";
[$s, $p] = call('PATCH', 'v1/settings', $rw, ['draw_when' => 'Dec 1, 8pm', 'ship_before' => '2026-12-19']);
ok($s === 200 && $p['draw_when'] === 'Dec 1, 8pm' && $p['ship_before'] === '2026-12-19', 'settings updated');
ok(call('PATCH', 'v1/settings', $rw, ['ship_before' => '2026-02-30'])[0] === 422, 'impossible date rejected');
ok(call('PATCH', 'v1/settings', $rw, ['signups_open' => 'yes'])[0] === 422, 'non-boolean rejected');
ok(call('PATCH', 'v1/settings', $rw, ['admin' => true])[0] === 422, 'unknown setting rejected');
[, $p] = call('GET', 'v1/rules', $ro);
ok(str_contains($p['rules'][1]['rule'], 'December 19'), 'rules reflect new ship date');

echo "participants\n";
[$s, $p] = call('POST', 'v1/participants', $rw, ['email' => 'Ann@Example.com', 'approved' => true]);
ok($s === 201 && $p['email'] === 'ann@example.com' && $p['approved'] === true, 'create participant');
$annId = $p['id'];
ok(call('POST', 'v1/participants', $rw, ['email' => 'ann@example.com'])[0] === 409, 'duplicate → 409');
[$s, $p] = call('PATCH', "v1/participants/$annId", $rw, ['name' => 'Ann', 'address' => ['city' => 'Akron']]);
ok($s === 200 && $p['name'] === 'Ann' && $p['address']['city'] === 'Akron', 'partial profile patch on empty profile');
[$s, $p] = call('PATCH', "v1/participants/$annId", $rw, ['address' => ['postal' => '!']]);
ok($s === 422 && isset($p['error']['fields']['address.postal']), 'bad field reported by API name');
call('PATCH', "v1/participants/$annId", $rw, ['hated_style' => 'sour']);
[$s, $p] = call('PATCH', "v1/participants/$annId", $rw, ['favorite_style' => 'sour']);
ok($s === 422, "can't set favorite equal to hated");
ok(call('PATCH', "v1/participants/$annId", $rw, ['agreed_to_rules' => true])[0] === 422, "API can't agree to rules for someone");
ok(call('GET', 'v1/participants/999', $ro)[0] === 404, 'missing participant → 404');

// Make 4 ready people (rules must be agreed by the person, so do it via the lib)
$good = ['addr1' => '1 Main St', 'city' => 'Akron', 'state' => 'OH', 'postal' => '44301', 'phone' => '330-555-0100',
         'fav_style' => 'stout', 'hate_style' => 'sour', 'rules' => array_fill_keys(array_keys(cfg('rules')), '1')];
$ids = [];
foreach (['ann', 'ben', 'cat', 'dan'] as $n) {
    $u = user_by_email("$n@example.com") ?: login_user("$n@example.com");
    [$v] = validate_profile(['name' => ucfirst($n)] + $good, false);
    save_profile((int)$u['id'], $v, false);
    $ids[] = (int)$u['id'];
}
[, $p] = call('GET', 'v1/participants', $ro, null, ['ready' => 'true']);
ok(count($p['participants']) === 1, 'only approved people are ready');
foreach ($ids as $id) call('PATCH', "v1/participants/$id", $rw, ['approved' => true]);
[, $p] = call('GET', 'v1/participants', $ro, null, ['ready' => 'true']);
ok(count($p['participants']) === 4, 'approve via API → 4 ready');

echo "draw & shipping\n";
ok(call('POST', 'v1/draw/emails', $rw)[0] === 409, 'no emails before draw');
[$s, $p] = call('POST', 'v1/draw', $rw, ['notify' => false]);
ok($s === 201 && count($p['pairs']) === 4 && $p['emails_sent'] === 0, 'draw via API, emails suppressed');
ok(call('POST', 'v1/draw', $rw)[0] === 409, 'second draw → 409');
ok(call('DELETE', "v1/participants/$annId", $rw)[0] === 409, "can't delete someone in the draw");
ok(call('PATCH', "v1/participants/$annId", $rw, ['approved' => false])[0] === 409, "can't un-approve someone in the draw");
[$s, $p] = call('POST', "v1/participants/$annId/shipment", $rw, ['tracking' => '1Z1']);
ok($s === 200 && $p['tracking'] === '1Z1' && $p['shipped_at'] !== null, 'mark shipped via API');
[, $p] = call('GET', 'v1/status', $ro);
ok($p['counts']['shipped'] === 1 && $p['drawn'] === true, 'status counts shipments');
ok(call('POST', 'v1/draw/emails', $rw)[1]['emails_sent'] === 4, 're-send match emails');
ok(call('DELETE', 'v1/draw', $rw)[0] === 422, 'reset needs confirm');
ok(call('DELETE', 'v1/draw', $rw, null, ['confirm' => 'true'])[0] === 204, 'reset with confirm');
ok(call('DELETE', "v1/participants/$annId", $rw)[0] === 204, 'delete after reset');

echo "organizers & user management\n";
$boss = user_by_email('boss@example.com') ?: login_user('boss@example.com');
$ben = user_by_email('ben@example.com');
ok(!is_admin_email('ben@example.com'), 'ben starts as a participant');
db()->exec("UPDATE users SET approved = 0 WHERE email = 'ben@example.com'");
set_organizer($ben, true, $boss);
ok(is_admin_email('ben@example.com') && (int)user_by_id((int)$ben['id'])['approved'] === 1, 'promote makes organizer + approves');
ok(in_array('ben@example.com', organizer_emails(), true), 'promoted organizer gets sign-up notices');
try { set_organizer(user_by_id((int)$ben['id']), false, user_by_id((int)$ben['id'])); ok(false, "can't demote yourself"); }
catch (RuntimeException $e) { ok(true, "can't demote yourself"); }
try { set_organizer($boss, false, $ben); ok(false, "can't demote a founder"); }
catch (RuntimeException $e) { ok(true, "can't demote a founder"); }
ok(delete_blocker($boss) !== null, "can't delete a founder");
ok(delete_blocker(user_by_id((int)$ben['id']), user_by_id((int)$ben['id'])) !== null, "can't delete yourself");
set_organizer(user_by_id((int)$ben['id']), false, $boss);
ok(!is_admin_email('ben@example.com'), 'demote works');
change_user_email(user_by_id((int)$ben['id']), 'Benny@Example.com');
ok(user_by_id((int)$ben['id'])['email'] === 'benny@example.com', 'change email (normalized)');
try { change_user_email(user_by_id((int)$ben['id']), 'cat@example.com'); ok(false, 'email collision rejected'); }
catch (RuntimeException $e) { ok(true, 'email collision rejected'); }
try { change_user_email($boss, 'x@example.com'); ok(false, "founder email can't change"); }
catch (RuntimeException $e) { ok(true, "founder email can't change"); }
[$v, $e] = validate_profile_partial(['name' => 'Half', 'postal' => '']);
ok(!$e, 'partial profile with blanks is fine for organizers');
[$v, $e] = validate_profile_partial(['phone' => '12', 'fav_style' => 'stout', 'hate_style' => 'stout']);
ok(isset($e['phone'], $e['hate_style']), 'but filled-in junk is still rejected');

$benId = (int)$ben['id'];
[$s, $p] = call('PATCH', "v1/participants/$benId", $rw, ['is_organizer' => true]);
ok($s === 200 && $p['is_organizer'] === true && $p['is_founder'] === false, 'API promote');
$bossId = (int)$boss['id'];
ok(call('PATCH', "v1/participants/$bossId", $rw, ['is_organizer' => false])[0] === 409, 'API cannot demote founder');
ok(call('DELETE', "v1/participants/$bossId", $rw)[0] === 409, 'API cannot delete founder');
ok(call('PATCH', "v1/participants/$benId", $rw, ['email' => 'nope'])[0] === 422, 'API rejects bad email');
ok(call('PATCH', "v1/participants/$benId", $rw, ['email' => 'cat@example.com'])[0] === 409, 'API email collision → 409');
[$s, $p] = call('PATCH', "v1/participants/$benId", $rw, ['email' => 'ben@example.com', 'is_organizer' => false]);
ok($s === 200 && $p['email'] === 'ben@example.com' && $p['is_organizer'] === false, 'API change email + demote');

echo "revocation\n";
revoke_api_key((int)db()->query("SELECT id FROM api_keys WHERE name = 'writer'")->fetchColumn());
ok(call('GET', 'v1/status', $rw)[0] === 401, 'revoked key → 401');

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
