<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/lib.php';

$user = require_login();
$drawn = draw_done();
$recipient = $drawn ? recipient_of((int)$user['id']) : null;
$lockAgreement = $drawn && in_draw((int)$user['id']);

$errors = [];
$form = $user;
// Pre-check every rule for people who already agreed to the current rules.
$form['rules_checked'] = has_agreed($user) ? array_keys(cfg('rules')) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'profile') {
        [$clean, $errors] = validate_profile($_POST, $lockAgreement);
        if (!$errors) {
            save_profile((int)$user['id'], $clean, $lockAgreement);
            redirect('me.php?msg=saved');
        }
        $form = array_replace($form, $clean);
    } elseif ($action === 'shipped' && $recipient) {
        mark_shipped($user, post('tracking'));
        redirect('me.php?msg=shipped');
    } elseif ($action === 'unshipped' && $recipient) {
        unmark_shipped($user);
        redirect('me.php?msg=unshipped');
    }
}

$oz = (int)cfg('min_ounces');
$santa = $drawn ? santa_of((int)$user['id']) : null;

page_start('My exchange', $user);
echo flash();
if ($errors) {
    echo '<p class="flash err">Almost. Fix the stuff marked in red. We believe in you. Mostly.</p>';
}

// ------------------------------------------------------------ status
if ($recipient): ?>
<section class="tag match" aria-labelledby="match-h">
  <p class="tag-to">To:</p>
  <h1 id="match-h"><?= h($recipient['name']) ?></h1>
  <p class="tag-from">From: <em>Santa (that's you. Act natural.)</em></p>

  <div class="match-grid">
    <div>
      <h3>Ship it to</h3>
      <address>
        <?= h($recipient['name']) ?><br>
        <?= h($recipient['addr1']) ?><br>
        <?php if ($recipient['addr2'] !== ''): ?><?= h($recipient['addr2']) ?><br><?php endif; ?>
        <?= h($recipient['city']) ?>, <?= h($recipient['state']) ?> <?= h($recipient['postal']) ?>
      </address>
      <p>Phone: <?= h($recipient['phone']) ?></p>
    </div>
    <div>
      <h3>Their taste</h3>
      <p class="love-line">Loves: <strong><?= h(style_label($recipient['fav_style'])) ?></strong></p>
      <p class="hate-line">Hates: <strong><?= h(style_label($recipient['hate_style'])) ?></strong></p>
      <?php if ($recipient['notes'] !== ''): ?>
        <h3>Notes</h3><p><?= nl2br(h($recipient['notes'])) ?></p>
      <?php endif; ?>
    </div>
  </div>
  <p class="rule">At least <?= $oz ?> oz, shipped before <strong><?= h(ship_before_label()) ?></strong>.
     You agreed to this. There were checkboxes.</p>

  <?php if ($user['shipped_at']): ?>
    <p class="stamp">Shipped <?= h(date('M j', (int)$user['shipped_at'])) ?></p>
    <?php if ($user['tracking'] !== ''): ?><p>Tracking: <?= h($user['tracking']) ?></p><?php endif; ?>
    <form method="post" action="<?= h(url('me.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="unshipped">
      <button type="submit" class="quiet">Wait, no, undo that</button>
    </form>
  <?php else: ?>
    <form method="post" action="<?= h(url('me.php')) ?>" class="shipform">
      <?= csrf_field() ?><input type="hidden" name="action" value="shipped">
      <label for="tracking">Tracking number <span class="opt">(optional)</span></label>
      <input id="tracking" name="tracking" maxlength="60" autocomplete="off">
      <button type="submit">It's FRA-GEE-LAY! Mark it shipped</button>
    </form>
  <?php endif; ?>
</section>
<?php elseif ($drawn): ?>
<section class="note">
  <h1>You missed the draw</h1>
  <p>It already happened without you. Awkward. Talk to the organizer.</p>
</section>
<?php elseif (!(int)$user['approved']): ?>
<section class="note">
  <h1>The organizer is checking the list (twice)</h1>
  <p>You're signed up, but somebody has to vouch for you first. Fill out the form while you wait.
     It's not going to fill itself out.</p>
</section>
<?php elseif (!is_eligible($user)): ?>
<section class="note">
  <h1>Almost in</h1>
  <p>Finish the form and check every single rule. Yes, every one. We'll know.</p>
</section>
<?php else: ?>
<section class="note good">
  <h1>You're in!</h1>
  <p>Now sit there like it's Christmas Eve. Draw: <strong><?= h(setting('draw_when')) ?></strong>.
     You'll get an email with your match, so maybe check your spam folder for once.</p>
</section>
<?php endif; ?>

<?php if ($drawn && in_draw((int)$user['id'])): ?>
<section class="incoming">
  <h2>Your incoming beer</h2>
  <?php if ($santa && $santa['shipped_at']): ?>
    <p>Your Secret Santa shipped it on <?= h(date('M j', (int)$santa['shipped_at'])) ?>.
       <?= $santa['tracking'] !== '' ? 'Tracking: <strong>' . h($santa['tracking']) . '</strong>' : '' ?></p>
  <?php else: ?>
    <p>Not shipped yet. Somebody out there is standing in a beer aisle, overthinking you.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- ------------------------------------------------------------ profile form -->
<form method="post" action="<?= h(url('me.php')) ?>" class="profile" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="profile">

  <header class="section-head">
    <h2>Your wish list</h2>
    <p class="hint">Signed in as <?= h($user['email']) ?>. Only the organizer and whoever draws you
       can see your address and phone. Not the whole internet. Relax.</p>
  </header>

  <div class="cols">
    <fieldset class="address">
      <legend>Where Santa ships it</legend>
      <label for="name">Full name, as it goes on the label</label>
      <input id="name" name="name" maxlength="80" autocomplete="name" value="<?= h($form['name']) ?>"<?= bad($errors, 'name') ?>>
      <?= field_error($errors, 'name') ?>

      <label for="addr1">Street address</label>
      <input id="addr1" name="addr1" maxlength="120" autocomplete="address-line1" value="<?= h($form['addr1']) ?>"<?= bad($errors, 'addr1') ?>>
      <?= field_error($errors, 'addr1') ?>

      <label for="addr2">Apt, suite, etc. <span class="opt">(optional)</span></label>
      <input id="addr2" name="addr2" maxlength="120" autocomplete="address-line2" value="<?= h($form['addr2']) ?>">

      <div class="row3">
        <div>
          <label for="city">City</label>
          <input id="city" name="city" maxlength="60" autocomplete="address-level2" value="<?= h($form['city']) ?>"<?= bad($errors, 'city') ?>>
          <?= field_error($errors, 'city') ?>
        </div>
        <div>
          <label for="state">State</label>
          <input id="state" name="state" maxlength="40" autocomplete="address-level1" value="<?= h($form['state']) ?>"<?= bad($errors, 'state') ?>>
          <?= field_error($errors, 'state') ?>
        </div>
        <div>
          <label for="postal">ZIP</label>
          <input id="postal" name="postal" maxlength="15" autocomplete="postal-code" inputmode="numeric" value="<?= h($form['postal']) ?>"<?= bad($errors, 'postal') ?>>
          <?= field_error($errors, 'postal') ?>
        </div>
      </div>

      <label for="phone">Mobile phone</label>
      <input id="phone" name="phone" type="tel" maxlength="25" autocomplete="tel" value="<?= h($form['phone']) ?>"<?= bad($errors, 'phone') ?>>
      <?= field_error($errors, 'phone') ?>

      <label for="notes">Anything else your Santa should know? <span class="opt">(optional)</span></label>
      <textarea id="notes" name="notes" maxlength="500" rows="3"
        placeholder="Gluten-free, no glass, allergic to fun, whatever…"><?= h($form['notes']) ?></textarea>
    </fieldset>

    <div class="tastes">
      <fieldset>
        <legend>A major award! <small>The one style you love most. Just one. Pick.</small></legend>
        <?= field_error($errors, 'fav_style') ?>
        <div class="chips"><?= style_chips('fav_style', (string)$form['fav_style'], false, 'love') ?></div>
      </fieldset>

      <fieldset>
        <legend>Oh, fuuudge. <small>The one style you never want to see. Ever.</small></legend>
        <?= field_error($errors, 'hate_style') ?>
        <div class="chips"><?= style_chips('hate_style', (string)$form['hate_style'], true, 'hate') ?></div>
      </fieldset>
    </div>
  </div>

  <fieldset class="oath<?= isset($errors['rules']) ? ' bad' : '' ?>">
    <legend>The Rules <small>Check every one. This is the triple-dog-dare part.</small></legend>
    <ol class="rules">
      <?php foreach (rules() as $i => [$headline, $detail]): ?>
        <li>
          <label class="check">
            <input type="checkbox" name="rules[<?= (int)$i ?>]" value="1"
                   <?= in_array($i, $form['rules_checked'], true) ? 'checked' : '' ?>
                   <?= $lockAgreement ? 'disabled' : '' ?>>
            <span><strong><?= h($headline) ?></strong> <?= h($detail) ?></span>
          </label>
        </li>
      <?php endforeach; ?>
    </ol>
    <?= field_error($errors, 'rules') ?>
    <?php if ($lockAgreement): ?>
      <p class="hint small">You're locked in. The draw happened. No backing out now.</p>
    <?php else: ?>
      <p class="hint small">Want out? Uncheck any rule before the draw and you're out. No hard feelings. Some feelings.</p>
    <?php endif; ?>
  </fieldset>

  <button type="submit" class="big">Save my wish list</button>
</form>
<?php page_end();
