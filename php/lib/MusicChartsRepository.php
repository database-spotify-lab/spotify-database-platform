<?php
declare(strict_types=1);

/**
 * SQL aligned with schema: users, artists, albums, tracks,
 * TRACK_ARTISTS, ALBUM_TRACKS, ALBUM_ARTISTS, ARTIST_GENRES, AUDIO_FEATURES.
 */
final class MusicChartsRepository
{
    /** Rows returned for TOP CHARTS (songs / artists / albums) on the main charts page. */
    private const TOP_CHARTS_LIMIT = 5;

    /** Rows per tab in EXPLORE BY GENRES (songs / artists / albums cards). */
    private const EXPLORE_BY_GENRE_LIMIT = 5;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * TOP CHARTS - Song: rank by tracks.popularity.
     * Cover: first album image (by earliest release_date among albums containing the track).
     */
    public function topSongs(?array $decadeRange, ?string $search): array
    {
        $params = [':st' => CATALOG_STATUS_APPROVED];
        $decadeSql = $this->albumReleaseDecadeSql('t.track_id', $decadeRange, $params);
        $searchSql = '';
        if ($search !== null && $search !== '') {
            $searchSql = ' AND (t.track_name LIKE :sq OR EXISTS (
                SELECT 1 FROM TRACK_ARTISTS ta_s
                JOIN ARTISTS ar_s ON ar_s.artist_id = ta_s.artist_id
                WHERE ta_s.track_id = t.track_id AND ar_s.artist_name LIKE :sq
            ))';
            $params[':sq'] = '%' . $search . '%';
        }

        $sql = "
            SELECT
                t.track_id,
                t.track_name,
                t.popularity,
                t.preview_url,
                (
                    SELECT alx.album_image_url
                    FROM ALBUM_TRACKS atx
                    JOIN ALBUMS alx ON alx.album_id = atx.album_id
                    WHERE atx.track_id = t.track_id AND alx.status = :st
                    ORDER BY alx.release_date ASC, alx.album_id ASC
                    LIMIT 1
                ) AS album_image_url,
                (
                    SELECT GROUP_CONCAT(DISTINCT arn.artist_name ORDER BY arn.artist_name SEPARATOR ', ')
                    FROM TRACK_ARTISTS tan
                    JOIN ARTISTS arn ON arn.artist_id = tan.artist_id
                    WHERE tan.track_id = t.track_id AND arn.status = :st
                ) AS artist_names
            FROM TRACKS t
            WHERE t.status = :st
            {$decadeSql}
            {$searchSql}
            ORDER BY t.popularity DESC
            LIMIT " . self::TOP_CHARTS_LIMIT . "
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $rank = 1;
        foreach ($rows as &$row) {
            $row['rank'] = $rank++;
        }
        unset($row);
        return $rows;
    }

    /**
     * TOP CHARTS - Artist: aggregate track popularity per artist.
     * Each `tracks.track_id` counts once toward SUM (remix / alternate cut = another row
     * with another id, so both popularities add). No merge by track_name.
     * When a decade is set, only tracks that appear on an album released in that range
     * contribute to the sum (same rule as top songs). Otherwise the chart matches
     * "all time" but artists with only other-decade catalogue would wrongly rank high.
     */
    public function topArtists(?array $decadeRange): array
    {
        $params = [':st' => CATALOG_STATUS_APPROVED];
        $decadeTrackSql = $this->albumReleaseDecadeSql('t.track_id', $decadeRange, $params);
        $decadeTopTrackSql = $this->albumReleaseDecadeSql('t2.track_id', $decadeRange, $params);

        $sql = "
            SELECT
                ar.artist_id,
                ar.artist_name,
                SUM(t.popularity) AS popularity_sum,
                (
                    SELECT t2.track_name
                    FROM TRACK_ARTISTS ta2
                    JOIN TRACKS t2 ON t2.track_id = ta2.track_id AND t2.status = :st
                    WHERE ta2.artist_id = ar.artist_id
                    {$decadeTopTrackSql}
                    ORDER BY t2.popularity DESC
                    LIMIT 1
                ) AS top_track_name,
                (
                    SELECT t2.preview_url
                    FROM TRACK_ARTISTS ta2
                    JOIN TRACKS t2 ON t2.track_id = ta2.track_id AND t2.status = :st
                    WHERE ta2.artist_id = ar.artist_id
                    {$decadeTopTrackSql}
                    ORDER BY t2.popularity DESC
                    LIMIT 1
                ) AS top_track_preview_url
            FROM ARTISTS ar
            JOIN TRACK_ARTISTS ta ON ta.artist_id = ar.artist_id
            JOIN TRACKS t ON t.track_id = ta.track_id AND t.status = :st
            WHERE ar.status = :st
            {$decadeTrackSql}
            GROUP BY ar.artist_id, ar.artist_name
            ORDER BY popularity_sum DESC
            LIMIT " . self::TOP_CHARTS_LIMIT . "
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $rank = 1;
        foreach ($rows as &$row) {
            $row['rank'] = $rank++;
        }
        unset($row);
        return $rows;
    }

    /**
     * TOP CHARTS - Album: sum of `tracks.popularity` over `ALBUM_TRACKS` for that album.
     * Each distinct `track_id` on the album contributes one term; different versions are
     * different ids and all count toward the album total.
     */
    public function topAlbums(?array $decadeRange): array
    {
        $params = [':st' => CATALOG_STATUS_APPROVED];
        $having = '';
        if ($decadeRange !== null) {
            $having = ' HAVING YEAR(MIN(al.release_date)) BETWEEN :dy0 AND :dy1 ';
            $params[':dy0'] = $decadeRange[0];
            $params[':dy1'] = $decadeRange[1];
        }

        $sql = "
            SELECT
                al.album_id,
                al.album_name,
                al.release_date,
                al.album_image_url,
                COALESCE(SUM(t.popularity), 0) AS popularity_sum,
                (
                    SELECT GROUP_CONCAT(DISTINCT arx.artist_name ORDER BY arx.artist_name SEPARATOR ', ')
                    FROM ALBUM_ARTISTS aax
                    JOIN ARTISTS arx ON arx.artist_id = aax.artist_id
                    WHERE aax.album_id = al.album_id AND arx.status = :st
                ) AS artist_names
            FROM ALBUMS al
            JOIN ALBUM_TRACKS at ON at.album_id = al.album_id
            JOIN TRACKS t ON t.track_id = at.track_id AND t.status = :st
            WHERE al.status = :st
            GROUP BY al.album_id, al.album_name, al.release_date, al.album_image_url
            {$having}
            ORDER BY popularity_sum DESC
            LIMIT " . self::TOP_CHARTS_LIMIT . "
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $rank = 1;
        foreach ($rows as &$row) {
            $row['rank'] = $rank++;
        }
        unset($row);
        return $rows;
    }

    /** Top bar search: match artist_name. */
    public function searchArtists(string $q, int $limit = 40): array
    {
        $sql = "
            SELECT artist_id, artist_name
            FROM ARTISTS
            WHERE status = :st AND artist_name LIKE :q
            ORDER BY artist_name ASC
            LIMIT " . max(1, min(100, $limit));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':st' => CATALOG_STATUS_APPROVED,
            ':q' => '%' . $q . '%',
        ]);
        return $stmt->fetchAll();
    }

    /** EXPLORE BY GENRES - list distinct genres for sidebar. */
    public function listGenres(): array
    {
        $sql = "
            SELECT DISTINCT ag.genre
            FROM ARTIST_GENRES ag
            JOIN ARTISTS ar ON ar.artist_id = ag.artist_id AND ar.status = :st
            ORDER BY ag.genre ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':st' => CATALOG_STATUS_APPROVED]);
        return array_column($stmt->fetchAll(), 'genre');
    }

    /**
     * Style analytics payload for analytics_charts "Style Analytics".
     * Genre filter is optional; pass null or "all" to disable filtering.
     */
    public function styleAnalytics(?string $genre): array
    {
        $genreFilter = $this->normalizeGenreFilter($genre);
        $avg = $this->styleFeatureAverages($genreFilter);
        $trendFeatures = ['danceability', 'energy', 'valence', 'acousticness', 'tempo', 'loudness'];

        $featureTrends = [];
        foreach ($trendFeatures as $feature) {
            $featureTrends[$feature] = $this->featureTrendByDecade($feature, $genreFilter);
        }

        return [
            'style_map' => [
                'genre_filter' => $genreFilter ?? 'all',
                'feature_averages' => $avg,
            ],
            'style_evolution' => [
                'genre_mix' => $this->genreMixByDecade(),
                'feature_trends' => $featureTrends,
            ],
        ];
    }

    /**
     * EXPLORE - cards for a genre: type = songs | artists | albums.
     */
    public function exploreByGenre(string $genre, string $type, ?array $decadeRange, int $limit = self::EXPLORE_BY_GENRE_LIMIT): array
    {
        $limit = max(1, min(self::EXPLORE_BY_GENRE_LIMIT, $limit));
        $type = strtolower($type);
        return match ($type) {
            'songs' => $this->exploreSongsByGenre($genre, $decadeRange, $limit),
            'artists' => $this->exploreArtistsByGenre($genre, $decadeRange, $limit),
            'albums' => $this->exploreAlbumsByGenre($genre, $decadeRange, $limit),
            default => [],
        };
    }

    /**
     * Artist search result page payload:
     * - artist basic profile
     * - top tracks
     * - albums
     *
     * @return array<string,mixed>|null
     */
    public function artistSearchResult(?string $artistId, ?string $artistName): ?array
    {
        $artist = $this->findArtist($artistId, $artistName);
        if ($artist === null) {
            return null;
        }

        $artistIdKey = (string) $artist['artist_id'];
        $tracks = $this->artistTopTracks($artistIdKey);
        $albums = $this->artistAlbums($artistIdKey);
        $popularitySum = $this->artistPopularitySum($artistIdKey);
        $artistRank = $this->artistRankByPopularity($artistIdKey, $popularitySum);

        $artist['popularity_sum'] = $popularitySum;
        $artist['artist_rank'] = $artistRank;
        $artist['hero_cover'] = $albums[0]['album_image_url'] ?? null;

        return [
            'artist' => $artist,
            'top_tracks' => $tracks,
            'albums' => $albums,
        ];
    }

    private function exploreSongsByGenre(string $genre, ?array $decadeRange, int $limit): array
    {
        $params = [':st' => CATALOG_STATUS_APPROVED, ':genre' => $genre];
        $decadeSql = $this->albumReleaseDecadeSql('t.track_id', $decadeRange, $params);

        $sql = "
            SELECT
                t.track_id,
                t.track_name,
                t.popularity,
                t.preview_url,
                (
                    SELECT alx.album_image_url
                    FROM ALBUM_TRACKS atx
                    JOIN ALBUMS alx ON alx.album_id = atx.album_id
                    WHERE atx.track_id = t.track_id AND alx.status = :st
                    ORDER BY alx.release_date ASC, alx.album_id ASC
                    LIMIT 1
                ) AS album_image_url,
                (
                    SELECT GROUP_CONCAT(DISTINCT arn.artist_name ORDER BY arn.artist_name SEPARATOR ', ')
                    FROM TRACK_ARTISTS tan
                    JOIN ARTISTS arn ON arn.artist_id = tan.artist_id
                    WHERE tan.track_id = t.track_id AND arn.status = :st
                ) AS artist_names
            FROM TRACKS t
            JOIN TRACK_ARTISTS ta ON ta.track_id = t.track_id
            JOIN ARTISTS ar ON ar.artist_id = ta.artist_id AND ar.status = :st
            JOIN ARTIST_GENRES ag ON ag.artist_id = ar.artist_id AND ag.genre = :genre
            WHERE t.status = :st
            {$decadeSql}
            GROUP BY t.track_id, t.track_name, t.popularity, t.preview_url
            ORDER BY t.popularity DESC
            LIMIT " . max(1, min(100, $limit));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $rank = 1;
        foreach ($rows as &$row) {
            $row['rank'] = $rank++;
        }
        unset($row);
        return $rows;
    }

    /** Same per-`track_id` SUM semantics as {@see topArtists()}. */
    private function exploreArtistsByGenre(string $genre, ?array $decadeRange, int $limit): array
    {
        $params = [':st' => CATALOG_STATUS_APPROVED, ':genre' => $genre];
        $decadeSql = $this->albumReleaseDecadeSql('t.track_id', $decadeRange, $params);

        $sql = "
            SELECT
                ar.artist_id,
                ar.artist_name,
                SUM(t.popularity) AS popularity_sum,
                COALESCE(
                    (
                        SELECT alx.album_image_url
                        FROM ALBUM_ARTISTS aai
                        JOIN ALBUMS alx ON alx.album_id = aai.album_id AND alx.status = :st
                        WHERE aai.artist_id = ar.artist_id
                            AND alx.album_image_url IS NOT NULL
                            AND TRIM(alx.album_image_url) <> ''
                        ORDER BY alx.release_date DESC, alx.album_id DESC
                        LIMIT 1
                    ),
                    (
                        SELECT alx2.album_image_url
                        FROM TRACK_ARTISTS ta2
                        JOIN ALBUM_TRACKS at2 ON at2.track_id = ta2.track_id
                        JOIN ALBUMS alx2 ON alx2.album_id = at2.album_id AND alx2.status = :st
                        WHERE ta2.artist_id = ar.artist_id
                            AND alx2.album_image_url IS NOT NULL
                            AND TRIM(alx2.album_image_url) <> ''
                        ORDER BY alx2.release_date DESC, alx2.album_id DESC
                        LIMIT 1
                    )
                ) AS album_image_url
            FROM ARTISTS ar
            JOIN ARTIST_GENRES ag ON ag.artist_id = ar.artist_id AND ag.genre = :genre
            JOIN TRACK_ARTISTS ta ON ta.artist_id = ar.artist_id
            JOIN TRACKS t ON t.track_id = ta.track_id AND t.status = :st
            WHERE ar.status = :st
            {$decadeSql}
            GROUP BY ar.artist_id, ar.artist_name
            ORDER BY popularity_sum DESC
            LIMIT " . max(1, min(100, $limit));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $rank = 1;
        foreach ($rows as &$row) {
            $row['rank'] = $rank++;
        }
        unset($row);
        return $rows;
    }

    private function exploreAlbumsByGenre(string $genre, ?array $decadeRange, int $limit): array
    {
        $params = [':st' => CATALOG_STATUS_APPROVED, ':genre' => $genre];
        $decadeWhere = '';
        if ($decadeRange !== null) {
            $decadeWhere = ' AND YEAR(al.release_date) BETWEEN :dy0 AND :dy1 ';
            $params[':dy0'] = $decadeRange[0];
            $params[':dy1'] = $decadeRange[1];
        }

        $sql = "
            SELECT
                al.album_id,
                al.album_name,
                al.release_date,
                al.album_image_url,
                ts.popularity_sum,
                (
                    SELECT GROUP_CONCAT(DISTINCT arx.artist_name ORDER BY arx.artist_name SEPARATOR ', ')
                    FROM ALBUM_ARTISTS aax
                    JOIN ARTISTS arx ON arx.artist_id = aax.artist_id
                    WHERE aax.album_id = al.album_id AND arx.status = :st
                ) AS artist_names
            FROM ALBUMS al
            INNER JOIN (
                SELECT at2.album_id, COALESCE(SUM(t2.popularity), 0) AS popularity_sum
                FROM ALBUM_TRACKS at2
                INNER JOIN TRACKS t2 ON t2.track_id = at2.track_id AND t2.status = :st
                GROUP BY at2.album_id
            ) ts ON ts.album_id = al.album_id
            WHERE al.status = :st
            {$decadeWhere}
            AND EXISTS (
                SELECT 1
                FROM ALBUM_ARTISTS aa
                JOIN ARTISTS ar ON ar.artist_id = aa.artist_id AND ar.status = :st
                JOIN ARTIST_GENRES ag ON ag.artist_id = ar.artist_id AND ag.genre = :genre
                WHERE aa.album_id = al.album_id
            )
            ORDER BY ts.popularity_sum DESC
            LIMIT " . max(1, min(100, $limit));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $rank = 1;
        foreach ($rows as &$row) {
            $row['rank'] = $rank++;
        }
        unset($row);
        return $rows;
    }

    private function findArtist(?string $artistId, ?string $artistName): ?array
    {
        if ($artistId !== null && $artistId !== '') {
            $sql = "
                SELECT ar.artist_id, ar.artist_name
                FROM ARTISTS ar
                WHERE ar.status = :st AND ar.artist_id = :artist_id
                LIMIT 1
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':st' => CATALOG_STATUS_APPROVED,
                ':artist_id' => $artistId,
            ]);
            $row = $stmt->fetch();
            if ($row !== false) {
                return $row;
            }
        }

        if ($artistName !== null && $artistName !== '') {
            $sql = "
                SELECT ar.artist_id, ar.artist_name
                FROM ARTISTS ar
                WHERE ar.status = :st AND ar.artist_name = :artist_name
                ORDER BY ar.artist_id ASC
                LIMIT 1
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':st' => CATALOG_STATUS_APPROVED,
                ':artist_name' => $artistName,
            ]);
            $row = $stmt->fetch();
            if ($row !== false) {
                return $row;
            }
        }

        return null;
    }

    private function artistTopTracks(string $artistId, int $limit = 10): array
    {
        $sql = "
            SELECT
                t.track_id,
                t.track_name,
                t.popularity,
                t.preview_url,
                (
                    SELECT GROUP_CONCAT(DISTINCT arn.artist_name ORDER BY arn.artist_name SEPARATOR ', ')
                    FROM TRACK_ARTISTS tan
                    JOIN ARTISTS arn ON arn.artist_id = tan.artist_id AND arn.status = :st
                    WHERE tan.track_id = t.track_id
                ) AS artist_names,
                (
                    SELECT alx.album_name
                    FROM ALBUM_TRACKS atx
                    JOIN ALBUMS alx ON alx.album_id = atx.album_id AND alx.status = :st
                    WHERE atx.track_id = t.track_id
                    ORDER BY alx.release_date ASC, alx.album_id ASC
                    LIMIT 1
                ) AS album_name,
                (
                    SELECT alx.album_image_url
                    FROM ALBUM_TRACKS atx
                    JOIN ALBUMS alx ON alx.album_id = atx.album_id AND alx.status = :st
                    WHERE atx.track_id = t.track_id
                    ORDER BY alx.release_date ASC, alx.album_id ASC
                    LIMIT 1
                ) AS album_image_url
            FROM TRACK_ARTISTS ta
            JOIN TRACKS t ON t.track_id = ta.track_id AND t.status = :st
            WHERE ta.artist_id = :artist_id
            ORDER BY t.popularity DESC, t.track_id ASC
            LIMIT " . max(1, min(100, $limit));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':st' => CATALOG_STATUS_APPROVED,
            ':artist_id' => $artistId,
        ]);
        $rows = $stmt->fetchAll();
        $rank = 1;
        foreach ($rows as &$row) {
            $row['rank'] = $rank++;
        }
        unset($row);
        return $rows;
    }

    private function artistAlbums(string $artistId, int $limit = 30): array
    {
        $sql = "
            SELECT
                al.album_id,
                al.album_name,
                al.release_date,
                al.album_image_url,
                COALESCE(SUM(t.popularity), 0) AS popularity_sum
            FROM ALBUM_ARTISTS aa
            JOIN ALBUMS al ON al.album_id = aa.album_id AND al.status = :st
            LEFT JOIN ALBUM_TRACKS at ON at.album_id = al.album_id
            LEFT JOIN TRACKS t ON t.track_id = at.track_id AND t.status = :st
            WHERE aa.artist_id = :artist_id
            GROUP BY al.album_id, al.album_name, al.release_date, al.album_image_url
            ORDER BY al.release_date DESC, popularity_sum DESC
            LIMIT " . max(1, min(200, $limit));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':st' => CATALOG_STATUS_APPROVED,
            ':artist_id' => $artistId,
        ]);
        return $stmt->fetchAll();
    }

    private function artistPopularitySum(string $artistId): int
    {
        $sql = "
            SELECT COALESCE(SUM(t.popularity), 0) AS popularity_sum
            FROM TRACK_ARTISTS ta
            JOIN TRACKS t ON t.track_id = ta.track_id AND t.status = :st
            JOIN ARTISTS ar ON ar.artist_id = ta.artist_id AND ar.status = :st
            WHERE ta.artist_id = :artist_id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':st' => CATALOG_STATUS_APPROVED,
            ':artist_id' => $artistId,
        ]);
        $row = $stmt->fetch();
        return (int) ($row['popularity_sum'] ?? 0);
    }

    private function artistRankByPopularity(string $artistId, int $popularitySum): int
    {
        $sql = "
            SELECT COUNT(*) + 1 AS artist_rank
            FROM (
                SELECT ar2.artist_id, COALESCE(SUM(t2.popularity), 0) AS popularity_sum
                FROM ARTISTS ar2
                JOIN TRACK_ARTISTS ta2 ON ta2.artist_id = ar2.artist_id
                JOIN TRACKS t2 ON t2.track_id = ta2.track_id AND t2.status = :st
                WHERE ar2.status = :st
                GROUP BY ar2.artist_id
            ) ranked
            WHERE ranked.popularity_sum > :popularity_sum
               OR (ranked.popularity_sum = :popularity_sum AND ranked.artist_id < :artist_id)
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':st' => CATALOG_STATUS_APPROVED,
            ':popularity_sum' => $popularitySum,
            ':artist_id' => $artistId,
        ]);
        $row = $stmt->fetch();
        return (int) ($row['artist_rank'] ?? 0);
    }

    private function normalizeGenreFilter(?string $genre): ?string
    {
        if ($genre === null) {
            return null;
        }
        $g = trim($genre);
        if ($g === '' || strtolower($g) === 'all') {
            return null;
        }
        return $g;
    }

    private function styleFeatureAverages(?string $genre): array
    {
        $params = [':st' => CATALOG_STATUS_APPROVED];
        $genreSql = '';
        if ($genre !== null) {
            $genreSql = "
                AND EXISTS (
                    SELECT 1
                    FROM TRACK_ARTISTS tag
                    JOIN ARTISTS ar_g ON ar_g.artist_id = tag.artist_id AND ar_g.status = :st
                    JOIN ARTIST_GENRES ag_g ON ag_g.artist_id = ar_g.artist_id AND ag_g.genre = :genre
                    WHERE tag.track_id = t.track_id
                )
            ";
            $params[':genre'] = $genre;
        }

        $sql = "
            SELECT
                AVG(af.danceability) AS danceability,
                AVG(af.energy) AS energy,
                AVG(af.valence) AS valence,
                AVG(af.acousticness) AS acousticness,
                AVG(af.tempo) AS tempo,
                AVG(af.loudness) AS loudness,
                AVG(t.duration_ms) AS avg_duration_ms
            FROM TRACKS t
            JOIN AUDIO_FEATURES af ON af.track_id = t.track_id
            WHERE t.status = :st
            {$genreSql}
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        $durationMs = (int) round((float) ($row['avg_duration_ms'] ?? 0));
        $durationMin = intdiv($durationMs, 60000);
        $durationSec = intdiv($durationMs % 60000, 1000);

        return [
            'danceability' => round((float) ($row['danceability'] ?? 0), 3),
            'energy' => round((float) ($row['energy'] ?? 0), 3),
            'valence' => round((float) ($row['valence'] ?? 0), 3),
            'acousticness' => round((float) ($row['acousticness'] ?? 0), 3),
            'tempo' => round((float) ($row['tempo'] ?? 0), 2),
            'loudness' => round((float) ($row['loudness'] ?? 0), 2),
            'avg_duration_ms' => $durationMs,
            'avg_duration_label' => sprintf('%d:%02d', $durationMin, $durationSec),
        ];
    }

    private function genreMixByDecade(): array
    {
        $params = [':st' => CATALOG_STATUS_APPROVED];
        $sql = "
            SELECT
                CASE
                    WHEN YEAR(al.release_date) BETWEEN 1980 AND 1989 THEN '1980s'
                    WHEN YEAR(al.release_date) BETWEEN 1990 AND 1999 THEN '1990s'
                    WHEN YEAR(al.release_date) BETWEEN 2000 AND 2009 THEN '2000s'
                    WHEN YEAR(al.release_date) BETWEEN 2010 AND 2019 THEN '2010s'
                    WHEN YEAR(al.release_date) BETWEEN 2020 AND 2029 THEN '2020s'
                    ELSE NULL
                END AS decade_label,
                ag.genre,
                COUNT(DISTINCT t.track_id) AS track_count
            FROM TRACKS t
            JOIN ALBUM_TRACKS at ON at.track_id = t.track_id
            JOIN ALBUMS al ON al.album_id = at.album_id AND al.status = :st
            JOIN TRACK_ARTISTS ta ON ta.track_id = t.track_id
            JOIN ARTISTS ar ON ar.artist_id = ta.artist_id AND ar.status = :st
            JOIN ARTIST_GENRES ag ON ag.artist_id = ar.artist_id
            WHERE t.status = :st
            GROUP BY decade_label, ag.genre
            HAVING decade_label IS NOT NULL
            ORDER BY decade_label ASC, track_count DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $mix = [];
        foreach ($rows as $row) {
            $d = (string) $row['decade_label'];
            if (!isset($mix[$d])) {
                $mix[$d] = [];
            }
            $mix[$d][] = [
                'genre' => (string) $row['genre'],
                'track_count' => (int) $row['track_count'],
            ];
        }
        return $mix;
    }

    private function featureTrendByDecade(string $feature, ?string $genre): array
    {
        $allowed = ['danceability', 'energy', 'valence', 'acousticness', 'tempo', 'loudness'];
        if (!in_array($feature, $allowed, true)) {
            return [];
        }

        $params = [':st' => CATALOG_STATUS_APPROVED];
        $genreSql = '';
        if ($genre !== null) {
            $genreSql = " AND ag.genre = :genre ";
            $params[':genre'] = $genre;
        }

        $sql = "
            SELECT
                CASE
                    WHEN YEAR(al.release_date) BETWEEN 1980 AND 1989 THEN '1980s'
                    WHEN YEAR(al.release_date) BETWEEN 1990 AND 1999 THEN '1990s'
                    WHEN YEAR(al.release_date) BETWEEN 2000 AND 2009 THEN '2000s'
                    WHEN YEAR(al.release_date) BETWEEN 2010 AND 2019 THEN '2010s'
                    WHEN YEAR(al.release_date) BETWEEN 2020 AND 2029 THEN '2020s'
                    ELSE NULL
                END AS decade_label,
                AVG(af.{$feature}) AS feature_avg
            FROM TRACKS t
            JOIN AUDIO_FEATURES af ON af.track_id = t.track_id
            JOIN ALBUM_TRACKS at ON at.track_id = t.track_id
            JOIN ALBUMS al ON al.album_id = at.album_id AND al.status = :st
            JOIN TRACK_ARTISTS ta ON ta.track_id = t.track_id
            JOIN ARTISTS ar ON ar.artist_id = ta.artist_id AND ar.status = :st
            JOIN ARTIST_GENRES ag ON ag.artist_id = ar.artist_id
            WHERE t.status = :st
            {$genreSql}
            GROUP BY decade_label
            HAVING decade_label IS NOT NULL
            ORDER BY decade_label ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $series = [];
        foreach ($rows as $row) {
            $series[] = [
                'decade' => (string) $row['decade_label'],
                'value' => round((float) $row['feature_avg'], 4),
            ];
        }
        return $series;
    }

    /**
     * Track is included if any linked album has release_date year in range.
     * Appends :dy0 / :dy1 to $params when range is set.
     */
    private function albumReleaseDecadeSql(string $trackIdExpr, ?array $decadeRange, array &$params): string
    {
        if ($decadeRange === null) {
            return '';
        }
        $params[':dy0'] = $decadeRange[0];
        $params[':dy1'] = $decadeRange[1];
        return " AND EXISTS (
            SELECT 1 FROM ALBUM_TRACKS atd
            JOIN ALBUMS ald ON ald.album_id = atd.album_id AND ald.status = :st
            WHERE atd.track_id = {$trackIdExpr}
            AND YEAR(ald.release_date) BETWEEN :dy0 AND :dy1
        ) ";
    }
}
