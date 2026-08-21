<?php
declare(strict_types=1);

return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'gost_relay',
        'user' => 'gost_relay',
        'password' => 'change-this-password',
    ],
    'app' => [
        'url' => 'https://relay.example.com',
        // Generate with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
        'key' => 'replace-with-a-base64-encoded-32-byte-key',
        'timezone' => 'Asia/Shanghai',
        'poll_interval' => 15,
    ],
];
