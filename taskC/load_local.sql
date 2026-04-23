USE musicbox;

LOAD DATA LOCAL INFILE '/Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/users.csv'
INTO TABLE USERS
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(user_id, email, password_hash, role, is_active);

LOAD DATA LOCAL INFILE '/Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/artists.csv'
INTO TABLE ARTISTS
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(artist_id, artist_name, status, submitted_by, reviewed_by);

LOAD DATA LOCAL INFILE '/Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/albums.csv'
INTO TABLE ALBUMS
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(album_id, album_name, release_date, album_image_url, status, submitted_by, reviewed_by);

LOAD DATA LOCAL INFILE '/Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/tracks.csv'
INTO TABLE TRACKS
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(track_id, track_name, popularity, duration_ms, explicit, preview_url, status, submitted_by, reviewed_by);

LOAD DATA LOCAL INFILE '/Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/track_artists.csv'
INTO TABLE TRACK_ARTISTS
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(track_id, artist_id);

LOAD DATA LOCAL INFILE '/Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/album_artists.csv'
INTO TABLE ALBUM_ARTISTS
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(album_id, artist_id);

LOAD DATA LOCAL INFILE '/Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/album_tracks.csv'
INTO TABLE ALBUM_TRACKS
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(album_id, track_id, disc_number, track_number);

LOAD DATA LOCAL INFILE '/Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/artist_genres.csv'
INTO TABLE ARTIST_GENRES
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(artist_id, genre);

LOAD DATA LOCAL INFILE '/Users/sherrywang/Desktop/Databases/spotify_database_coursework/taskC/audio_features.csv'
INTO TABLE AUDIO_FEATURES
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(track_id, danceability, energy, valence, acousticness, instrumentalness, liveness, speechiness, tempo, `key`, mode, loudness, time_signature);
