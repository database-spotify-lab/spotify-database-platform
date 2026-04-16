<?php
declare(strict_types=1);

/** Style Analytics backend for analytics_charts.html (Style Analytics panel). */

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/lib/MusicChartsRepository.php';

$genre = isset($_GET['genre']) ? trim((string) $_GET['genre']) : 'all';

try {
    $repo = new MusicChartsRepository(db());
    json_response([
        'ok' => true,
        'data' => $repo->styleAnalytics($genre),
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage(),
    ], 500);
}
