<?php
/**
 * Default DB connection (no password in repo).
 *
 * Start PHP with your password in the environment, for example:
 *
 *   export MUSICBOX_DB_PASSWORD='your_mysql_password'
 *   php -S 127.0.0.1:8080
 *
 * Optional: MUSICBOX_DB_USER, MUSICBOX_DB_DSN to override user / DSN.
 */
return [
    'dsn' => 'mysql:host=127.0.0.1;dbname=musicbox;charset=utf8mb4',
    'user' => 'root',
    'pass' => '',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
];
