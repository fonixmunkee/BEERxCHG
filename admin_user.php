<?php
// Organizer view of one person: edit their info, role, approval, shipping, sessions.
declare(strict_types=1);
require_once __DIR__ . '/includes/lib.php';

$me = require_admin();
$id = (int)($_GET['id'] ?? 0);
$target = user_by_id($id);
if (!$target) {
    redirect('admin.php');
}
$self = 'admin_user.php?id=' . $id;
$errors = [];
$error = '';
$form = $target;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        switch (post('action')) {
            case 'profile':
                [$clean, $errors] = validate_profile_partial($_POST);
                $form = array_replace($form, $clean, ['email' => post('email')]);
                if (!$errors) {
                    $db = db();
                    $db->beginTransaction();
                    try {
                        change_user_email($target, post('email'));
                        save_profile($id, $clean, true);   // never touches the rules agreement
                        $db->commit();
                    } catch (Throwable $e) {
                        $db->rollBack();
                        throw $e;
                    }
                    redirect($self . '&msg=usersaved');
                }
                break;

            case 'approve':
            case 'unapprove':
                if (post('action') === 'unapprove' && in_draw($id)) {
                    throw new RuntimeException("They're in the current draw. Reset the draw first.");
                }
                db()->prepare('UPDATE users SET approved = ? WHERE id = ?')
                    ->execute([post('action') === 'approve' ? 1 : 0, $id]);
                redirect($self . '&msg=updated');

            case 'promote':
            case 'demote':
                set_organizer($target, post('action') === 'promote', $me);
                redirect($self . '&msg=' . (post('action') === 'promote' ? 'promoted' : 'demoted'));

            case 'shipped':
                if (!in_draw($id)) throw new RuntimeException("They're not in a draw, so there's nothing to ship.");
                mark_shipped($target, post('tracking'));
                redirect($self . '&msg=shipped');

            case 'unshipped':
                unmark_shipped($target);
                redirect($self . '&msg=unshipped');

            case 'kick':
                logout_everywhere($id);
                redirect($self . '&msg=kicked');
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    $target = user_by_id($id) ?? $target;
}

$founder   = is_founder_email($target['email']);
$organizer = is_admin_email($target['email']);
$isMe      = (int)$target['id'] === (int)$me['id'];
$drawnIn   = in_draw($id);
$sessions  = (int)db()->query('SELECT COUNT(*) FROM sessions WHERE user_id = ' . $id . ' AND expires_at > ' . time())->fetchColumn();
$fmt = fn($ts) => $ts ? date('M j, Y g:ia', (int)$ts) : 'never';

page_start('Edit ' . ($target['name'] ?: $target['email']), $me);
echo flash();
if ($error) {
    echo '<p class="flash err">' . h($error) . '</p>';
}
if ($errors) {
    echo '<p class="flash err">Some of that doesn\'t fly. Fix the red stuff.</p>';
}
?>
<p class="backlink"><a href="<?= h(url('admin.php')) ?>">← Back to the Organizer’s Desk</a></p>
<h1 class="page-title"><?= h($target['name'] ?: $target['email']) ?></h1>

<div class="badges">
  <?php if ($founder): ?><span class="badge founder">Founder</span>
  <?php elseif ($organizer): ?><span class="badge organizer">Organizer</span><?php endif; ?>
  <span class="badge <?= (int)$target['approved'] ? 'yes' : 'no' ?>"><?= (int)$target['approved'] ? 'Approved' : 'Not approved' ?></span>
  <span class="badge <?= profile_complete($target) ? 'yes' : 'no' ?>"><?= profile_complete($target) ? 'Profile complete' : 'Profile incomplete' ?></span>
  <span class="badge <?= has_agreed($target) ? 'yes' : 'no' ?>"><?= has_agreed($target) ? 'Agreed to rules' : 'Hasn’t agreed to rules' ?></span>
  <?php if (is_eligible($target)): ?><span class="badge ready">Ready for the draw</span><?php endif; ?>
  <?php if ($drawnIn): ?><span class="badge yes">In the draw</span><?php endif; ?>
</div>
<p class="muted">Signed up <?= h($fmt($target['created_at'])) ?> · last login <?= h($fmt($target['last_login_at'])) ?>
  <?php if ($target['heard_via'] !== ''): ?> · heard via “<?= h($target['heard_via']) ?>”<?php endif; ?></p>

<div class="manage-grid">
  <section class="panel">
    <h2>Role &amp; access</h2>
    <?php if ($founder): ?>
      <p>Founder. They're listed in the config file, so they're an organizer for life and can't be
         demoted, renamed or deleted from here. Like a tenured professor, but for beer.</p>
    <?php elseif ($organizer): ?>
      <p>Organizer. They can approve people, edit anyone, run the draw, make API keys and promote more organizers.</p>
      <?php if (!$isMe): ?>
      <form method="post" action="<?= h(url($self)) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="demote">
        <button type="submit" class="secondary">Demote to participant</button>
      </form>
      <?php else: ?><p class="muted">That's you. Another organizer has to demote you.</p><?php endif; ?>
    <?php else: ?>
      <p>Regular participant.</p>
      <form method="post" action="<?= h(url($self)) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="promote">
        <button type="submit">⭐ Promote to organizer</button>
      </form>
      <p class="hint small">Organizers can see everyone's address and who has whom. Pick someone who can keep a secret.</p>
    <?php endif; ?>

    <h3>Approval</h3>
    <form method="post" action="<?= h(url($self)) ?>" class="inline">
      <?= csrf_field() ?>
      <?php if ((int)$target['approved']): ?>
        <button name="action" value="unapprove" class="secondary" <?= $drawnIn ? 'disabled' : '' ?>>Un-approve</button>
        <?php if ($drawnIn): ?><span class="muted">(in the draw: reset it first)</span><?php endif; ?>
      <?php else: ?>
        <button name="action" value="approve">Approve</button>
      <?php endif; ?>
    </form>

    <h3>Sessions</h3>
    <p><?= $sessions ?> active login<?= $sessions === 1 ? '' : 's' ?>.</p>
    <?php if ($sessions): ?>
    <form method="post" action="<?= h(url($self)) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="kick">
      <button type="submit" class="secondary">Log them out everywhere</button>
    </form>
    <?php endif; ?>

    <?php if ($drawnIn): ?>
    <h3>Shipping</h3>
    <?php if ($target['shipped_at']): ?>
      <p>Shipped <?= h($fmt($target['shipped_at'])) ?><?= $target['tracking'] !== '' ? ' · tracking ' . h($target['tracking']) : '' ?>.</p>
      <form method="post" action="<?= h(url($self)) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="unshipped">
        <button type="submit" class="secondary">Mark not shipped</button>
      </form>
    <?php else: ?>
      <p>Hasn't shipped yet.</p>
      <form method="post" action="<?= h(url($self)) ?>" class="shipform">
        <?= csrf_field() ?><input type="hidden" name="action" value="shipped">
        <label for="tracking">Tracking number <span class="opt">(optional)</span></label>
        <input id="tracking" name="tracking" maxlength="60" autocomplete="off">
        <button type="submit">Mark shipped for them</button>
      </form>
      <p class="hint small">This emails their recipient that beer is on the way.</p>
    <?php endif; ?>
    <?php endif; ?>
  </section>

  <form method="post" action="<?= h(url($self)) ?>" class="panel profile" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="profile">
    <h2>Their info</h2>
    <p class="hint small">Blanks are fine. Whatever you fill in has to be real. You can't check the rules
       for them. They have to do that themselves.</p>

    <label for="email">Email (they log in with this)</label>
    <input id="email" name="email" type="email" maxlength="254" value="<?= h($form['email']) ?>"
      <?= $founder ? 'readonly' : '' ?>>
    <?php if ($founder): ?><p class="hint small">Founder email. Change it in the config file.</p><?php endif; ?>

    <label for="name">Full name</label>
    <input id="name" name="name" maxlength="80" value="<?= h($form['name']) ?>"<?= bad($errors, 'name') ?>>
    <?= field_error($errors, 'name') ?>

    <label for="addr1">Street address</label>
    <input id="addr1" name="addr1" maxlength="120" value="<?= h($form['addr1']) ?>"<?= bad($errors, 'addr1') ?>>
    <label for="addr2">Apt, suite, etc.</label>
    <input id="addr2" name="addr2" maxlength="120" value="<?= h($form['addr2']) ?>">
    <div class="row3">
      <div><label for="city">City</label>
        <input id="city" name="city" maxlength="60" value="<?= h($form['city']) ?>"<?= bad($errors, 'city') ?>></div>
      <div><label for="state">State</label>
        <input id="state" name="state" maxlength="40" value="<?= h($form['state']) ?>"<?= bad($errors, 'state') ?>></div>
      <div><label for="postal">ZIP</label>
        <input id="postal" name="postal" maxlength="15" value="<?= h($form['postal']) ?>"<?= bad($errors, 'postal') ?>>
        <?= field_error($errors, 'postal') ?></div>
    </div>

    <label for="phone">Mobile phone</label>
    <input id="phone" name="phone" type="tel" maxlength="25" value="<?= h($form['phone']) ?>"<?= bad($errors, 'phone') ?>>
    <?= field_error($errors, 'phone') ?>

    <label for="notes">Notes for their Santa</label>
    <textarea id="notes" name="notes" maxlength="500" rows="3"><?= h($form['notes']) ?></textarea>

    <fieldset>
      <legend>Favorite style</legend>
      <?= field_error($errors, 'fav_style') ?>
      <div class="chips"><?= style_chips('fav_style', (string)$form['fav_style'], false, 'love') ?></div>
    </fieldset>
    <fieldset>
      <legend>Hated style</legend>
      <?= field_error($errors, 'hate_style') ?>
      <div class="chips"><?= style_chips('hate_style', (string)$form['hate_style'], true, 'hate') ?></div>
    </fieldset>

    <button type="submit" class="big">Save their info</button>
  </form>
</div>

<?php if ($reason = delete_blocker($target, $me)): ?>
  <p class="muted">Can't delete: <?= h($reason) ?></p>
<?php else: ?>
<details class="danger panel">
  <summary>Delete this person…</summary>
  <form method="post" action="<?= h(url('admin.php')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="remove">
    <input type="hidden" name="id" value="<?= (int)$target['id'] ?>">
    <p>Removes them and everything they entered. They can sign up again later if they get approved.</p>
    <label class="check"><input type="checkbox" name="confirm" value="yes"> Really delete</label>
    <button type="submit" class="danger">Delete</button>
  </form>
</details>
<?php endif; ?>
<?php page_end();
