<?php
declare(strict_types=1);

// Router for PHP's built-in development/test server. Nginx uses try_files.
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = is_string($path) ? __DIR__ . $path : '';
if ($file !== '' && $path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
