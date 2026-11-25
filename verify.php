<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/lib.php';

if (current_user()) {
    redirect('me.php');
}

$email = normalize_email((string)($_COOKIE['bxpending'] ?? ''));
if (!$email) {
    redirect('?msg=expired');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $heardVia = verify_login_code($email, post('code'));
    if ($heardVia !== null) {
        $isNew = !user_by_email($email);
        login_user($email, $heardVia);
        set_cookie('bxpending', '', time() - 3600);
        redirect('me.php?msg=' . ($isNew ? 'welcome' : 'hello'));
    }
    $error = "Nope. Wrong code, or it expired while you were doing whatever you were doing. "
           . (int)cfg('code_max_attempts') . " wrong tries and you need a new one.";
}

page_start('Enter your code');
?>
<section class="tag decoder" aria-labelledby="dec-h">
  <h1 id="dec-h">Decoder ring time</h1>
  <p>We sent a 6-digit secret code to <strong><?= h($email) ?></strong>.
     It's good for <?= (int)cfg('code_ttl_minutes') ?> minutes, so don't go make a sandwich.</p>
  <?php if ($error): ?><p class="flash err"><?= h($error) ?></p><?php endif; ?>
  <form method="post" action="<?= h(url('verify.php')) ?>">
    <?= csrf_field() ?>
    <label for="code">Secret code</label>
    <input id="code" name="code" class="code" inputmode="numeric" autocomplete="one-time-code"
           pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
    <button type="submit">Decode it</button>
  </form>
  <p class="hint small">It will not say “Be sure to drink your Ovaltine.” We checked.<br>
     Nothing in your inbox? Check spam, then <a href="<?= h(url('')) ?>">send a new code</a>.
     You'll stay logged in on this device for <?= (int)cfg('session_days') ?> days, because security.</p>
</section>
<?php page_end();
