Task C - MusicBox Database Setup

Files included:
- create.sql: creates the MusicBox database and all tables
- load.sql: loads the sample data into the tables
- users.csv
- artists.csv
- albums.csv
- tracks.csv
- track_artists.csv
- album_artists.csv
- album_tracks.csv
- artist_genres.csv
- audio_features.csv

Database name:
- musicbox

How to run:

1. Open MySQL:
mysql -u root -p

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
- This task uses a small curated seed dataset to test the schema, foreign key relationships, and application logic.
- The dataset is intended for development, validation, and demonstration of the current MusicBox prototype.
- The USERS table only includes roles used by the current HTML design: admin and analyst.
- The column `key` in AUDIO_FEATURES is handled carefully in SQL because it is a reserved word.
