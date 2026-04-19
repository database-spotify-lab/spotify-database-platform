<?php
declare(strict_types=1);

/**
 * Unified JSON API for music_charts.html sections.
 *
 * GET params:
 *   action = top_songs | top_artists | top_albums | search_artists | genres | explore | artist_search_result | style_analytics
 *   decade = all | 1980s | 1990s | ... (optional)
 *   q      = search string (search_artists)
 *   genre  = genre label (explore)
 *   type   = songs | artists | albums (explore)
 *   artist_id / artist_name (artist_search_result)
 *   genre (style_analytics, optional; all by default)
 */

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/lib/MusicChartsRepository.php';

$action = $_GET['action'] ?? '';
$decade = $_GET['decade'] ?? 'all';
$range = decade_year_range($decade);

try {
    $repo = new MusicChartsRepository(db());
    switch ($action) {
        case 'top_songs':
            $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
            json_response(['ok' => true, 'data' => $repo->topSongs($range, $q !== '' ? $q : null)]);
            break;

        case 'top_artists':
            json_response(['ok' => true, 'data' => $repo->topArtists($range)]);
            break;

        case 'top_albums':
            json_response(['ok' => true, 'data' => $repo->topAlbums($range)]);
            break;

        case 'search_artists':
            $q = trim((string) ($_GET['q'] ?? ''));
            json_response(['ok' => true, 'data' => $q === '' ? [] : $repo->searchArtists($q)]);
            break;

        case 'genres':
            json_response(['ok' => true, 'data' => $repo->listGenres()]);
            break;

        case 'explore':
            $genre = trim((string) ($_GET['genre'] ?? ''));
            $type = trim((string) ($_GET['type'] ?? 'songs'));
            if ($genre === '') {
                json_response(['ok' => false, 'error' => 'missing_genre'], 400);
                break;
            }
            json_response(['ok' => true, 'data' => $repo->exploreByGenre($genre, $type, $range)]);
            break;

        case 'artist_search_result':
            $artistIdRaw = trim((string) ($_GET['artist_id'] ?? ''));
            $artistName = trim((string) ($_GET['artist_name'] ?? ''));
            $artistId = $artistIdRaw !== '' ? $artistIdRaw : null;
            $payload = $repo->artistSearchResult($artistId, $artistName !== '' ? $artistName : null);
            if ($payload === null) {
                json_response(['ok' => false, 'error' => 'artist_not_found'], 404);
                break;
            }
            json_response(['ok' => true, 'data' => $payload]);
            break;

        case 'style_analytics':
            $genre = trim((string) ($_GET['genre'] ?? 'all'));
            json_response(['ok' => true, 'data' => $repo->styleAnalytics($genre)]);
            break;

        default:
            json_response([
                'ok' => false,
                'error' => 'unknown_action',
                'hint' => 'top_songs, top_artists, top_albums, search_artists, genres, explore, artist_search_result, style_analytics',
            ], 400);
    }
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage(),
    ], 500);
}
