<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('');
}
check_csrf();
logout_user();
redirect('?msg=out');
