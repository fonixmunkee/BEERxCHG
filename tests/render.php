<?php
// Render one page as a logged-in user against a scratch DB and fail on any
// PHP warning/notice. Usage: BX_DATA_DIR=… BX_MAIL_MODE=log php tests/render.php me.php [admin|user] [drawn]
declare(strict_types=1);

if (!getenv('BX_DATA_DIR') || getenv('BX_MAIL_MODE') !== 'log') {
    fwrite(STDERR, "Refusing to run: set BX_DATA_DIR to a scratch dir and BX_MAIL_MODE=log\n");
    exit(2);
}
$page = $argv[1] ?? 'me.php';
$who  = $argv[2] ?? 'user';
$drawn = ($argv[3] ?? '') === 'drawn';

set_error_handler(function ($no, $str, $file, $line) {
    fwrite(STDERR, "PHP problem: $str at $file:$line\n");
    exit(1);
});
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'drunkencomputing.com';

require __DIR__ . '/../includes/lib.php';
$admin = cfg('admin_emails')[0];
$good = ['addr1' => '1 Main St', 'city' => 'Akron', 'state' => 'OH', 'postal' => '44301',
         'phone' => '330-555-0100', 'fav_style' => 'stout', 'hate_style' => 'none', 'rules' => array_fill_keys(array_keys(cfg('rules')), '1'),
         'notes' => "No glass\n<b>please</b>"];
foreach ([$admin => 'Organizer', 'x@example.com' => 'X <Ray>', 'y@example.com' => 'Yvonne'] as $email => $name) {
    if (!user_by_email($email)) {
        $u = login_user($email);
        [$v, ] = validate_profile(['name' => $name] + $good, false);
        save_profile((int)$u['id'], $v, false);
        db()->exec('UPDATE users SET approved = 1');
    }
}
if ($drawn && !draw_done()) {
    run_draw();
}
logout_user();
if ($who !== 'anon') {
    login_user($who === 'admin' ? $admin : 'x@example.com');
}

ob_start();
register_shutdown_function(function () {
    $html = ob_get_clean();
    if (preg_match('/(Warning|Notice|Fatal error|Deprecated)/', $html)) {
        echo "PROBLEM in output\n";
        exit(1);
    }
    $esc = !str_contains($html, '<Ray>') && !str_contains($html, '<b>please');
    printf("rendered %d bytes, user input escaped: %s\n", strlen($html), $esc ? 'yes' : 'NO');
    file_put_contents(getenv('BX_DATA_DIR') . '/last.html', $html);
});
if (str_contains($page, '..')) exit(2);
[$file, $qs] = explode('?', $page, 2) + [1 => ''];
parse_str($qs, $_GET);
require __DIR__ . '/../' . $file;
