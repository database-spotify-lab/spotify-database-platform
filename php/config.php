<?php
/**
 * Server DB connection.
 */
return [
    'dsn' => 'mysql:host=127.0.0.1;dbname=ywang506_1;charset=utf8mb4',
    'user' => 'ywang506',
    'pass' => 'GdJBA9XW',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
];
