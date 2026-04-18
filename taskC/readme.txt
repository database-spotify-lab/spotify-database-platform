Task C - MusicBox Database Setup

Files included:
- create.sql: creates the MusicBox database and all tables
- load.sql: loads the final dataset into the tables
- users.csv: system seed data for application users
- artists.csv
- albums.csv
- tracks.csv
- track_artists.csv
- album_artists.csv
- album_tracks.csv
- artist_genres.csv
- audio_features.csv
- etl/etl.py: transforms the original spotify_top_10000.csv dataset into normalized CSV files
- etl/output/: intermediate/generated ETL outputs
- etl/output/artist_genre_categories.csv: helper output for mapping raw artist genres to broader frontend-friendly categories

Database name:
- musicbox

How to run:

1. Open MySQL with local infile enabled:
mysql --local-infile=1 -u root -p

2. Run create.sql:
source /Users/wmhjoy/Desktop/UR/Databases/project/milestone_3/spotify-database-platform/taskC/create.sql;

3. Use the database:
USE musicbox;

4. If needed, clear old data in dependency order before reloading:
DELETE FROM AUDIO_FEATURES;
DELETE FROM ARTIST_GENRES;
DELETE FROM ALBUM_TRACKS;
DELETE FROM TRACK_ARTISTS;
DELETE FROM ALBUM_ARTISTS;
DELETE FROM TRACKS;
DELETE FROM ALBUMS;
DELETE FROM ARTISTS;
DELETE FROM USERS;

5. Run load.sql:
source /Users/wmhjoy/Desktop/UR/Databases/project/milestone_3/spotify-database-platform/taskC/load.sql;

Tables populated:
- USERS
- ARTISTS
- ALBUMS
- TRACKS
- TRACK_ARTISTS
- ALBUM_ARTISTS
- ALBUM_TRACKS
- ARTIST_GENRES
- AUDIO_FEATURES

Notes:
- Music-related tables are populated from a full ETL transformation of the original spotify_top_10000.csv dataset.
- Track, album, and artist identifiers are based on Spotify URI-derived real IDs when available; stable fallback IDs are generated only when source URIs are missing.
- The USERS table remains system seed data for the prototype and only includes the roles used by the current HTML design: admin and analyst.
- Workflow fields such as status, submitted_by, and reviewed_by are assigned to support the prototype’s content management and review logic.
- Many-to-many artist relationships are preserved through TRACK_ARTISTS and ALBUM_ARTISTS.
- The file artist_genre_categories.csv is generated as a helper output for simplified genre mapping, but it is not loaded into the main 9-table schema.
- The column `key` in AUDIO_FEATURES is handled carefully in SQL because it is a reserved word.