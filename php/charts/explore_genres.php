<?php
declare(strict_types=1);

/** EXPLORE BY GENRES — sidebar genre list + grid payload. */

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/lib/MusicChartsRepository.php';

$decade = $_GET['decade'] ?? 'all';
$genre = trim((string) ($_GET['genre'] ?? ''));
$type = trim((string) ($_GET['type'] ?? 'songs'));

try {
    $repo = new MusicChartsRepository(db());
    $range = decade_year_range($decade);

    if ($genre === '') {
        json_response([
            'ok' => true,
            'genres' => $repo->listGenres(),
            'items' => [],
        ]);
        exit;
    }

    json_response([
        'ok' => true,
        'genres' => $repo->listGenres(),
        'items' => $repo->exploreByGenre($genre, $type, $range),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 500);
}
