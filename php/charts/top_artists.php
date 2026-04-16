<?php
declare(strict_types=1);

/** TOP CHARTS — Artist tab. */

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/lib/MusicChartsRepository.php';

$decade = $_GET['decade'] ?? 'all';

try {
    $repo = new MusicChartsRepository(db());
    json_response(['ok' => true, 'data' => $repo->topArtists(decade_year_range($decade))]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 500);
}
