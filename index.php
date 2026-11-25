<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/lib.php';

if (current_user()) {
    redirect('me.php');
}

$error = '';
$email = '';
$answer = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = post('email');
    $answer = post('heard');
    $norm = normalize_email($email);
    if (!$norm) {
        $error = "That's not an email address. That's a cry for help.";
    } else {
        switch (request_login_code($norm, $answer)) {
            case 'sent':
                set_cookie('bxpending', $norm, time() + (int)cfg('code_ttl_minutes') * 60);
                redirect('verify.php');
            case 'challenge':
                $error = $answer === ''
                    ? "New here? Answer the question. We don't let just anybody in. Well, we do, but they have to answer the question."
                    : "Nope. Wrong answer. Think harder about where you were when this came up…";
                break;
            case 'rate':
                $error = "Whoa there, speed racer. Too many tries. Go get a beer, then check your inbox (and spam).";
                break;
            case 'closed':
                $error = "Sign-ups are closed. The Bumpus hounds got the turkey. Maybe next year.";
                break;
            default:
                $error = "The email didn't go out. Not your fault. Probably. Try again in a minute.";
        }
    }
}

$oz = (int)cfg('min_ounces');
page_start('Sign in');
echo flash();
?>
<div class="split">
  <section class="hero">
    <p class="kicker"><?= h(cfg('tagline')) ?> · Est. <?= h(cfg('established')) ?></p>
    <h1><?= h(cfg('title')) ?></h1>
    <p class="subhead">Secret Santa, but it's <em>beer</em>.</p>
    <p class="lede">You buy beer for one person. One other person buys beer for you. Nobody knows who
       has who until the box shows up on the porch. It's <strong><?= $oz ?> oz minimum</strong>, it's not
       complicated, and somebody is still going to mess it up. Don't be that guy.</p>
  </section>

  <section class="tag signup" aria-labelledby="signup-h">
    <h2 id="signup-h">Get on the list</h2>
    <p class="hint">We'll email you a 6-digit secret code. It's like a decoder ring, except it actually does something.</p>
    <?php if ($error): ?><p class="flash err"><?= h($error) ?></p><?php endif; ?>
    <form method="post" action="<?= h(url('')) ?>">
      <?= csrf_field() ?>
      <label for="email">Your email</label>
      <input id="email" name="email" type="email" required autocomplete="email" autofocus
             maxlength="254" value="<?= h($email) ?>" placeholder="ralphie@example.com">
      <?php if (challenge_enabled()): ?>
        <label for="heard"><?= h(cfg('challenge_question')) ?></label>
        <input id="heard" name="heard" maxlength="100" autocomplete="off" value="<?= h($answer) ?>">
        <p class="hint small">First time only. Been here before? Leave it blank, you're already vetted. Mostly.</p>
      <?php endif; ?>
      <button type="submit">Email me a code</button>
    </form>
  </section>
</div>
<?php page_end();
