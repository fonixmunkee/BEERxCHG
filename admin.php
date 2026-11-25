<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/lib.php';

$me = require_admin();
$error = '';
$newKey = null;   // plaintext of a just-created key, shown exactly once
$answersDraft = null;  // re-shown if the answer list didn't validate
$tested = null;        // result of "try an answer"

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $target = user_by_id((int)post('id'));

    try {
        switch ($action) {
            case 'settings':
                $ship = post('ship_before');
                $d = DateTime::createFromFormat('!Y-m-d', $ship);
                if (!$d || $d->format('Y-m-d') !== $ship) {
                    throw new RuntimeException('Ship-before date must be a real date.');
                }
                set_setting('ship_before', $ship);
                set_setting('draw_when', clean_line(post('draw_when'), 100) ?: 'To be determined');
                set_setting('signups_open', post('signups_open') === '1' ? '1' : '0');
                redirect('admin.php?msg=settings');

            case 'approve':
            case 'unapprove':
                if (!$target) throw new RuntimeException('No such participant.');
                if ($action === 'unapprove' && in_draw((int)$target['id'])) {
                    throw new RuntimeException("They're in the current draw. Reset the draw first.");
                }
                db()->prepare('UPDATE users SET approved = ? WHERE id = ?')
                    ->execute([$action === 'approve' ? 1 : 0, $target['id']]);
                redirect('admin.php?msg=updated');

            case 'remove':
                if (!$target) throw new RuntimeException('No such participant.');
                if (post('confirm') !== 'yes') throw new RuntimeException('Tick the box to confirm removal.');
                if ($why = delete_blocker($target, $me)) throw new RuntimeException($why);
                db()->prepare('DELETE FROM users WHERE id = ?')->execute([$target['id']]);
                redirect('admin.php?msg=removed');

            case 'answers':
                [$answers, $why] = parse_challenge_keywords(post('answers'));
                if ($why) {
                    $answersDraft = post('answers');
                    throw new RuntimeException($why);
                }
                set_setting('challenge_keywords', json_encode($answers));
                redirect('admin.php?msg=answers#challenge');

            case 'test_answer':
                $tested = ['answer' => clean_line(post('test'), 100), 'ok' => challenge_ok(post('test'))];
                break;

            case 'promote':
            case 'demote':
                if (!$target) throw new RuntimeException('No such participant.');
                set_organizer($target, $action === 'promote', $me);
                redirect('admin.php?msg=' . ($action === 'promote' ? 'promoted' : 'demoted'));

            case 'draw':
                run_draw();
                send_all_match_emails();
                redirect('admin.php?msg=drawn');

            case 'reset':
                if (post('confirm') !== 'yes') throw new RuntimeException('Tick the box to confirm the reset.');
                reset_draw();
                redirect('admin.php?msg=reset');

            case 'resend':
                send_all_match_emails();
                redirect('admin.php?msg=resent');

            case 'create_key':
                // Rendered right here (no redirect) so the key never sits in a URL or cookie.
                $newKey = ['token' => create_api_key(post('key_name'), post('key_scope'), $me['email']),
                           'name'  => clean_line(post('key_name'), 60),
                           'scope' => post('key_scope')];
                break;

            case 'revoke_key':
                revoke_api_key((int)post('key_id'));
                redirect('admin.php?msg=revoked#api-keys');
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$users = all_users();
$eligible = array_filter($users, 'is_eligible');
$drawn = draw_done();
$pairs = $drawn ? all_pairs() : [];
$shippedCount = count(array_filter($pairs, fn($p) => $p[0]['shipped_at']));

page_start('Organizer', $me);
echo flash();
if ($error) {
    echo '<p class="flash err">' . h($error) . '</p>';
}
?>
<h1 class="page-title">The Organizer’s Desk</h1>

<section class="panel">
  <h2>Settings</h2>
  <form method="post" action="<?= h(url('admin.php')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="settings">
    <label for="draw_when">When's the draw? (shown to everyone)</label>
    <input id="draw_when" name="draw_when" maxlength="100" value="<?= h(setting('draw_when')) ?>">
    <label for="ship_before">Beer must ship before</label>
    <input id="ship_before" name="ship_before" type="date" value="<?= h(setting('ship_before')) ?>">
    <label class="check"><input type="checkbox" name="signups_open" value="1"
      <?= signups_open() ? 'checked' : '' ?>> New sign-ups allowed</label>
    <button type="submit">Save settings</button>
  </form>
</section>

<section class="panel" id="challenge">
  <h2>The bouncer question</h2>
  <p>New sign-ups have to answer <strong>“<?= h(cfg('challenge_question')) ?>”</strong>
     An answer gets in if it <em>contains</em> any of these, ignoring capitals, spaces and punctuation.
     So <code>Leg Lamp</code> also lets in “leglamp” and “the LEG LAMP guy”. Returning people and organizers skip it.</p>
  <form method="post" action="<?= h(url('admin.php')) ?>#challenge">
    <?= csrf_field() ?><input type="hidden" name="action" value="answers">
    <label for="answers">Accepted answers, one per line</label>
    <textarea id="answers" name="answers" rows="8" class="answers"><?= h($answersDraft ?? implode("\n", challenge_keywords())) ?></textarea>
    <p class="hint small">At least 3 letters each. Watch out for short ones: <code>ski</code> would also let in “whiskey”.</p>
    <button type="submit">Save answers</button>
  </form>

  <form method="post" action="<?= h(url('admin.php')) ?>#challenge" class="tryanswer">
    <?= csrf_field() ?><input type="hidden" name="action" value="test_answer">
    <label for="test">Try an answer</label>
    <div class="inline-field">
      <input id="test" name="test" maxlength="100" autocomplete="off" value="<?= h($tested['answer'] ?? '') ?>">
      <button type="submit" class="secondary">Would it get in?</button>
    </div>
    <?php if ($tested): ?>
      <p class="flash <?= $tested['ok'] ? 'ok' : 'err' ?>"><?= $tested['ok'] ? 'Yep, that gets in.' : 'Nope. Bounced.' ?></p>
    <?php endif; ?>
  </form>
</section>

<section class="panel">
  <h2>Participants <span class="muted">(<?= count($users) ?> signed up, <?= count($eligible) ?> ready)</span></h2>
  <p class="muted">"Ready" = approved, profile complete, and agreed to the rules. Only ready people go into the draw.
     Click a name to edit their info, fix a typo in their address, or log them out.</p>
  <div class="tablewrap">
  <table>
    <thead><tr><th>Name / email</th><th>Profile</th><th>Agreed</th><th>Approved</th><th>Role</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr class="<?= is_eligible($u) ? 'ready' : '' ?>">
        <td><a class="who" href="<?= h(url('admin_user.php?id=' . (int)$u['id'])) ?>"><strong><?= h($u['name'] ?: '—') ?></strong></a><br><span class="muted"><?= h($u['email']) ?></span>
          <?php if ($u['heard_via'] !== ''): ?><br><span class="muted">heard via: “<?= h($u['heard_via']) ?>”</span><?php endif; ?>
          <?php if ($u['name'] !== ''): ?>
            <details><summary>details</summary>
              <?= h($u['addr1']) ?> <?= h($u['addr2']) ?>, <?= h($u['city']) ?>, <?= h($u['state']) ?> <?= h($u['postal']) ?><br>
              📱 <?= h($u['phone']) ?><br>
              ❤️ <?= h(style_label($u['fav_style'])) ?> · 🚫 <?= h(style_label($u['hate_style'])) ?>
              <?php if ($u['notes'] !== ''): ?><br>📝 <?= h($u['notes']) ?><?php endif; ?>
            </details>
          <?php endif; ?>
        </td>
        <td><?= profile_complete($u) ? '✅' : '…' ?></td>
        <td><?= has_agreed($u) ? '✅' : '—' ?></td>
        <td>
          <form method="post" action="<?= h(url('admin.php')) ?>" class="inline">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <?php if ((int)$u['approved']): ?>
              ✅ <button name="action" value="unapprove" class="linkish">revoke</button>
            <?php else: ?>
              <button name="action" value="approve">Approve</button>
            <?php endif; ?>
          </form>
        </td>
        <td>
          <?php $founder = is_founder_email($u['email']); $org = $founder || (int)$u['is_organizer'] === 1; ?>
          <?php if ($founder): ?><span class="badge founder">Founder</span>
          <?php else: ?>
          <form method="post" action="<?= h(url('admin.php')) ?>" class="inline">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <?php if ($org): ?>
              <span class="badge organizer">Organizer</span>
              <?php if ((int)$u['id'] !== (int)$me['id']): ?><button name="action" value="demote" class="linkish">demote</button><?php endif; ?>
            <?php else: ?>
              <button name="action" value="promote" class="linkish">make organizer</button>
            <?php endif; ?>
          </form>
          <?php endif; ?>
        </td>
        <td>
          <a href="<?= h(url('admin_user.php?id=' . (int)$u['id'])) ?>">edit</a>
          <?php if (!delete_blocker($u, $me)): ?>
          <details class="danger"><summary>remove</summary>
            <form method="post" action="<?= h(url('admin.php')) ?>">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="action" value="remove">
              <label class="check"><input type="checkbox" name="confirm" value="yes"> Really delete</label>
              <button type="submit" class="danger">Delete</button>
            </form>
          </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <h2>The draw</h2>
  <?php if (!$drawn): ?>
    <p>Draws <strong><?= count($eligible) ?></strong> ready participants into one secret loop and emails
       each person their match. This also closes new sign-ups.</p>
    <form method="post" action="<?= h(url('admin.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="draw">
      <button type="submit" <?= count($eligible) < 2 ? 'disabled' : '' ?>>🎲 Run the draw now</button>
    </form>
  <?php else: ?>
    <p>Drawn <?= h(date('M j, Y g:ia', (int)setting('drawn_at'))) ?> ·
       <strong><?= $shippedCount ?> / <?= count($pairs) ?></strong> shipped.</p>
    <details>
      <summary>Show who has whom (spoilers if you're playing too!)</summary>
      <table>
        <thead><tr><th>Sender</th><th>→ Recipient</th><th>Shipped</th></tr></thead>
        <tbody>
        <?php foreach ($pairs as [$g, $r]): ?>
          <tr><td><?= h($g['name']) ?></td><td><?= h($r['name']) ?></td>
              <td><?= $g['shipped_at'] ? '✅ ' . h($g['tracking']) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </details>
    <form method="post" action="<?= h(url('admin.php')) ?>" class="inline">
      <?= csrf_field() ?><input type="hidden" name="action" value="resend">
      <button type="submit" class="secondary">Re-send match emails</button>
    </form>
    <details class="danger"><summary>Reset the draw…</summary>
      <form method="post" action="<?= h(url('admin.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset">
        <p>Deletes every match and shipping status. Everyone already got an email, so you'd have to tell them.</p>
        <label class="check"><input type="checkbox" name="confirm" value="yes"> Yes, reset it</label>
        <button type="submit" class="danger">Reset draw</button>
      </form>
    </details>
  <?php endif; ?>
</section>
<section class="panel" id="api-keys">
  <h2>API keys</h2>
  <p class="muted">For scripts, bots and other questionable automation. <strong>Read</strong> keys can see
     everything, including addresses and who has whom. <strong>Read / write</strong> keys can also approve
     people, change settings and run the draw. Treat them like passwords, because they basically are.
     <a href="<?= h(url('docs/')) ?>">API docs</a>.</p>

  <?php if ($newKey): ?>
    <div class="flash ok newkey">
      <p><strong>Here's your new <?= h(API_SCOPES[$newKey['scope']]) ?> key, “<?= h($newKey['name']) ?>”.</strong>
         Copy it now. You will never see it again. We don't even have it, just a hash of it.</p>
      <input class="keybox" readonly value="<?= h($newKey['token']) ?>" aria-label="New API key" spellcheck="false">
    </div>
  <?php endif; ?>

  <form method="post" action="<?= h(url('admin.php')) ?>#api-keys" class="keyform">
    <?= csrf_field() ?><input type="hidden" name="action" value="create_key">
    <label for="key_name">What's it for?</label>
    <input id="key_name" name="key_name" maxlength="60" required placeholder="Shipping-status chat bot">
    <fieldset class="scope">
      <legend class="sr">Permissions</legend>
      <?php foreach (API_SCOPES as $k => $label): ?>
        <label class="chip love"><input type="radio" name="key_scope" value="<?= h($k) ?>" <?= $k === 'read' ? 'checked' : '' ?>><span><?= h($label) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <button type="submit">Generate key</button>
  </form>

  <?php $keys = list_api_keys(); if ($keys): ?>
  <div class="tablewrap">
  <table>
    <thead><tr><th>Name</th><th>Key</th><th>Access</th><th>Created</th><th>Last used</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($keys as $k): ?>
      <tr class="<?= $k['revoked_at'] ? 'revoked' : '' ?>">
        <td><?= h($k['name']) ?></td>
        <td><code><?= h($k['prefix']) ?>…</code></td>
        <td><?= h(API_SCOPES[$k['scope']] ?? $k['scope']) ?></td>
        <td><?= h(date('M j, Y', (int)$k['created_at'])) ?></td>
        <td><?= $k['last_used_at'] ? h(date('M j, g:ia', (int)$k['last_used_at'])) : 'never' ?></td>
        <td>
          <?php if ($k['revoked_at']): ?>
            revoked <?= h(date('M j', (int)$k['revoked_at'])) ?>
          <?php else: ?>
            <form method="post" action="<?= h(url('admin.php')) ?>" class="inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="revoke_key">
              <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
              <button type="submit" class="danger">Revoke</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php page_end();
