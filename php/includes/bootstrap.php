<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = require dirname(__DIR__) . '/config.php';
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], $c['options']);
    }
    return $pdo;
}

/** Only surface moderated-approved catalogue rows (adjust if your seed uses other literals). */
const CATALOG_STATUS_APPROVED = 'approved';

/**
 * Maps UI decade keys to [startYear, endYear]. 'all' => null range (caller treats as no filter).
 * @return array{0:?int,1:?int}|null
 */
function decade_year_range(?string $decade): ?array
{
    if ($decade === null || $decade === '' || $decade === 'all') {
        return null;
    }
    $map = [
        '1980s' => [1980, 1989],
        '1990s' => [1990, 1999],
        '2000s' => [2000, 2009],
        '2010s' => [2010, 2019],
        '2020s' => [2020, 2029],
    ];
    return $map[$decade] ?? null;
}

function json_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
