<?php
// Shared configuration. Committed to git — no secrets or personal data here.
// Per-server overrides (admin emails, etc.) go in config.local.php, which is
// gitignored; see config.local.example.php.

define('APP_ROOT', dirname(__DIR__));
// Tests point this at a scratch directory so they never touch live data.
define('DATA_DIR', getenv('BX_DATA_DIR') ?: APP_ROOT . '/data');

$config = [
    'app_name'     => 'BEERxCHG',                // the logo / short name
    'title'        => 'Snakes on a Sleigh',      // the show's name: tab, headline, emails
    'tagline'      => "Jimmy Snakes' Annual Holiday Beer Exchange",
    'established'  => '2022',
    'base_url'     => '/BEERxCHG/',
    'site_url'     => 'https://drunkencomputing.com/BEERxCHG/',

    // People who can approve participants and run the draw (lowercase).
    'admin_emails' => [],

    // Outgoing mail. The From address must be on a DreamHost-hosted domain.
    'mail_from'      => 'beerxchg@drunkencomputing.com',
    'mail_from_name' => 'Snakes on a Sleigh',
    'mail_reply_to'  => '',
    'mail_mode'      => 'mail',      // 'mail' = really send, 'log' = append to data/mail.log

    // Question new sign-ups must answer. Accepted keywords live in the
    // gitignored config.local.php (empty list = challenge off).
    'challenge_question' => 'How did you find out about the beer exchange?',
    'challenge_keywords' => [],

    // The rules. Each one gets its own checkbox, and all of them must be
    // checked to be in the draw. {oz} and {ship_before} are filled in.
    // Editing this list un-agrees everyone (they have to re-check it),
    // so nobody is held to a rule they never saw.
    'rules' => [
        ['I will ship at least {oz} oz of beer.',
         "That's three 32 oz crowlers. Not two crowlers and an apology."],
        ['It ships before {ship_before}.',
         "Before. Not on. Not \"it's been in my trunk since Tuesday.\""],
        ['I will not send the style my giftee hates.',
         'They told you. In writing. On a website. You have no excuse.'],
        ['I will keep my match a secret until the beer lands.',
         'Yes, even if they ask nicely. Especially if they ask nicely.'],
        ["I'll pack it like it's FRA-GEE-LAY.",
         'Bubble wrap, a real box, no exploding cans. Nobody wants a soggy porch.'],
    ],

    'min_ounces'          => 96,
    'default_ship_before' => '2026-12-20',   // must ship BEFORE this date

    // Auth
    'session_days'         => 7,     // absolute: log in again after this
    'code_ttl_minutes'     => 15,
    'code_max_attempts'    => 5,
    'codes_per_email_hour' => 5,
    'codes_per_ip_hour'    => 20,
    'cookie_secure'        => true,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace($config, require __DIR__ . '/config.local.php');
}
if (getenv('BX_MAIL_MODE')) {
    $config['mail_mode'] = getenv('BX_MAIL_MODE');
}
$config['admin_emails'] = array_map('strtolower', $config['admin_emails']);
