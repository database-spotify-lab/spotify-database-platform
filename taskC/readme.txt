Task C - MusicBox Database Setup

Files included:
- create.sql: creates the MusicBox database and all tables (数据库名字ywang506_1)
- load.sql: loads the final dataset into the tables
- users.csv: system seed data for application users（所有表要大写）
- artists.csv
- albums.csv
- tracks.csv
- track_artists.csv
- album_artists.csv
- album_tracks.csv
- artist_genres.csv
- audio_features.csv
- etl/etl.py: transforms the original spotify_top_10000.csv dataset into normalized CSV files
- etl/output/: intermediate/generated ETL outputs（output删掉）

Database name:
- musicbox(数据库名字ywang506_1)

How to run:

1. Open MySQL with local infile enabled:
mysql --local-infile=1 -u ywang506 -p -h betaweb.csug.rochester.edu

2. Run create.sql:
source /Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/create.sql;

3. Use the database:
USE ywang506_1;

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
source /Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/load.sql;

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
- ARTIST_GENRES stores the frontend-aligned mapped genre categories (Pop, Rock, Hip-Hop, R&B, Jazz, Classical, Electronic, Country) rather than the original fine-grained Spotify genre strings.
- The column `key` in AUDIO_FEATURES is handled carefully in SQL because it is a reserved word.
