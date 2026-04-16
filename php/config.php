<?php
/**
 * Copy values to match your MySQL/MariaDB setup.
 * For production, prefer environment variables.
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
