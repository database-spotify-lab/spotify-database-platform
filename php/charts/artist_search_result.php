<?php
declare(strict_types=1);

/** Artist search result page payload by artist_id (or artist_name fallback). */

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/lib/MusicChartsRepository.php';

$artistIdRaw = trim((string) ($_GET['artist_id'] ?? ''));
$artistName = trim((string) ($_GET['artist_name'] ?? ''));
$artistId = ctype_digit($artistIdRaw) ? (int) $artistIdRaw : null;

try {
    $repo = new MusicChartsRepository(db());
    $payload = $repo->artistSearchResult($artistId, $artistName !== '' ? $artistName : null);
    if ($payload === null) {
        json_response(['ok' => false, 'error' => 'artist_not_found'], 404);
        exit;
    }
    json_response(['ok' => true, 'data' => $payload]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 500);
}
