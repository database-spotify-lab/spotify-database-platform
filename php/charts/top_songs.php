<?php
declare(strict_types=1);

/** TOP CHARTS - Song tab (same logic as api/music_charts.php?action=top_songs). */

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/lib/MusicChartsRepository.php';

$decade = $_GET['decade'] ?? 'all';
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

try {
    $repo = new MusicChartsRepository(db());
    json_response([
        'ok' => true,
        'data' => $repo->topSongs(decade_year_range($decade), $q !== '' ? $q : null),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 500);
}
