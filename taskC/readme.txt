Task C - MusicBox Database Setup

Files included:
- create.sql: creates the ywang506_1 database and all tables
- load.sql: loads the final dataset into the tables
- users.csv: system seed data for USERS
- artists.csv: data for ARTISTS
- albums.csv: data for ALBUMS
- tracks.csv: data for TRACKS
- track_artists.csv: data for TRACK_ARTISTS
- album_artists.csv: data for ALBUM_ARTISTS
- album_tracks.csv: data for ALBUM_TRACKS
- artist_genres.csv: data for ARTIST_GENRES
- audio_features.csv: data for AUDIO_FEATURES
- etl/etl.py: transforms the original dataset into normalized CSV files

Database name:
- ywang506_1

How to run:

1. Open MySQL with local infile enabled:
mysql --local-infile=1 -u ywang506 -p -h betaweb.csug.rochester.edu

2. Run create.sql:
source /Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/create.sql;

3. Use the database:
USE ywang506_1;


4. Run load.sql:
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
- Data source: The original dataset used for this project is the Kaggle dataset "Top 10000 Songs on Spotify 1950-Now" by Joe Beach Capital:
  https://www.kaggle.com/datasets/joebeachcapital/top-10000-spotify-songs-1960-now
  The selected source file is top_10000_1950-now.csv.
- - Music-related tables are populated from a full ETL transformation of this Spotify dataset into normalized relational tables.
- Track, album, and artist identifiers are based on Spotify URI-derived real IDs when available; stable fallback IDs are generated only when source URIs are missing.
- The USERS table remains system seed data for the prototype and only includes the roles used by the current HTML design: admin and analyst.
- Workflow fields such as status, submitted_by, and reviewed_by are assigned to support the prototype’s content management and review logic.
- Relationship tables such as TRACK_ARTISTS, ALBUM_ARTISTS, ALBUM_TRACKS, and ARTIST_GENRES preserve artist relationships, album-track ordering, and mapped genre information from the normalized dataset.
- ARTIST_GENRES stores the frontend-aligned mapped genre categories (Pop, Rock, Hip-Hop, R&B, Jazz, Classical, Electronic, Country) rather than the original fine-grained Spotify genre strings.
- The column `key` in AUDIO_FEATURES is handled carefully in SQL because it is a reserved word.
