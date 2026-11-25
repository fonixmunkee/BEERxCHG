<?php
// Copy to config.local.php (gitignored) and edit. Anything here overrides
// includes/config.php.
return [
    'admin_emails'  => ['organizer@example.com'],
    // Accepted answers to the sign-up challenge question (substring match on
    // letters only, case-insensitive). Leave empty to turn the challenge off.
    'challenge_keywords' => ['keyword1', 'keyword2'],
    // 'mail_reply_to' => 'organizer@example.com',
    // 'mail_mode'     => 'log',   // don't send real mail (local dev)
    // 'cookie_secure' => false,   // only for http://localhost dev
];
