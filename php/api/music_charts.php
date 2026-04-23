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

/**
 * Content-management list used by analytics_charts.html management panel.
 *
 * @return array{rows:array<int,array{name:string,entity:string,status:string,entity_id:string,cover_url:string}>,total:int}
 */
function content_management_rows(PDO $pdo, string $type, int $page, int $perPage): array
{
    $offset = ($page - 1) * $perPage;
    if ($type === 'songs') {
        $countSql = 'SELECT COUNT(*) FROM TRACKS';
        $sql = "
            SELECT
                t.track_id AS entity_id,
                t.track_name AS name,
                COALESCE(GROUP_CONCAT(DISTINCT ar.artist_name ORDER BY ar.artist_name SEPARATOR ', '), '—') AS entity,
                t.status AS status,
                COALESCE(MAX(al.album_image_url), '') AS cover_url
            FROM TRACKS t
            LEFT JOIN TRACK_ARTISTS ta ON ta.track_id = t.track_id
            LEFT JOIN ARTISTS ar ON ar.artist_id = ta.artist_id
            LEFT JOIN ALBUM_TRACKS at ON at.track_id = t.track_id
            LEFT JOIN ALBUMS al ON al.album_id = at.album_id
            GROUP BY t.track_id, t.track_name, t.status
            ORDER BY
                CASE LOWER(t.status)
                    WHEN 'pending' THEN 0
                    WHEN 'approved' THEN 1
                    WHEN 'rejected' THEN 2
                    ELSE 9
                END ASC,
                t.track_name ASC
            LIMIT :limit OFFSET :offset
        ";
    } elseif ($type === 'artists') {
        $countSql = 'SELECT COUNT(*) FROM ARTISTS';
        $sql = "
            SELECT
                ar.artist_id AS entity_id,
                ar.artist_name AS name,
                'Artist' AS entity,
                ar.status AS status,
                COALESCE(MAX(al.album_image_url), '') AS cover_url
            FROM ARTISTS ar
            LEFT JOIN ALBUM_ARTISTS aa ON aa.artist_id = ar.artist_id
            LEFT JOIN ALBUMS al ON al.album_id = aa.album_id
            GROUP BY ar.artist_id, ar.artist_name, ar.status
            ORDER BY
                CASE LOWER(ar.status)
                    WHEN 'pending' THEN 0
                    WHEN 'approved' THEN 1
                    WHEN 'rejected' THEN 2
                    ELSE 9
                END ASC,
                ar.artist_name ASC
            LIMIT :limit OFFSET :offset
        ";
    } else {
        $countSql = 'SELECT COUNT(*) FROM ALBUMS';
        $sql = "
            SELECT
                al.album_id AS entity_id,
                al.album_name AS name,
                COALESCE(GROUP_CONCAT(DISTINCT ar.artist_name ORDER BY ar.artist_name SEPARATOR ', '), '—') AS entity,
                al.status AS status,
                COALESCE(al.album_image_url, '') AS cover_url
            FROM ALBUMS al
            LEFT JOIN ALBUM_ARTISTS aa ON aa.album_id = al.album_id
            LEFT JOIN ARTISTS ar ON ar.artist_id = aa.artist_id
            GROUP BY al.album_id, al.album_name, al.status, al.album_image_url
            ORDER BY
                CASE LOWER(al.status)
                    WHEN 'pending' THEN 0
                    WHEN 'approved' THEN 1
                    WHEN 'rejected' THEN 2
                    ELSE 9
                END ASC,
                al.album_name ASC
            LIMIT :limit OFFSET :offset
        ";
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute();
    $total = (int) ($countStmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];
    $mappedRows = array_map(static function (array $r): array {
        return [
            'entity_id' => (string) ($r['entity_id'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
            'entity' => (string) ($r['entity'] ?? '—'),
            'status' => (string) ($r['status'] ?? ''),
            'cover_url' => (string) ($r['cover_url'] ?? ''),
        ];
    }, $rows);
    return ['rows' => $mappedRows, 'total' => $total];
}

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

        case 'content_management_list':
            $type = strtolower(trim((string) ($_GET['type'] ?? 'songs')));
            if (!in_array($type, ['songs', 'artists', 'albums'], true)) {
                json_response(['ok' => false, 'error' => 'invalid_type'], 400);
                break;
            }
            $page = (int) ($_GET['page'] ?? 1);
            if ($page < 1) {
                $page = 1;
            }
            $perPage = 50;
            $result = content_management_rows(db(), $type, $page, $perPage);
            $total = $result['total'];
            $pages = (int) max(1, (int) ceil($total / $perPage));
            if ($page > $pages) {
                $page = $pages;
                $result = content_management_rows(db(), $type, $page, $perPage);
                $total = $result['total'];
            }
            json_response([
                'ok' => true,
                'data' => [
                    'rows' => $result['rows'],
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'pages' => $pages,
                    ],
                ],
            ]);
            break;

        default:
            json_response([
                'ok' => false,
                'error' => 'unknown_action',
                'hint' => 'top_songs, top_artists, top_albums, search_artists, genres, explore, artist_search_result, style_analytics, content_management_list',
            ], 400);
    }
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage(),
    ], 500);
}
