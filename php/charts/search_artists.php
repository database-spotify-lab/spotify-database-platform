<?php
declare(strict_types=1);

/** Top bar - search artists (for autocomplete / redirect). */

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/lib/MusicChartsRepository.php';

$q = trim((string) ($_GET['q'] ?? ''));

try {
    $repo = new MusicChartsRepository(db());
    json_response(['ok' => true, 'data' => $q === '' ? [] : $repo->searchArtists($q)]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 500);
}
