CREATE DATABASE IF NOT EXISTS musicbox;
USE musicbox;

CREATE TABLE IF NOT EXISTS USERS (
    user_id BIGINT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS ARTISTS (
    artist_id VARCHAR(64) PRIMARY KEY,
    artist_name VARCHAR(300) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    submitted_by BIGINT NOT NULL,
    reviewed_by BIGINT NULL,
    CONSTRAINT fk_artists_submitted_by
        FOREIGN KEY (submitted_by) REFERENCES USERS(user_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_artists_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES USERS(user_id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS ALBUMS (
    album_id VARCHAR(64) PRIMARY KEY,
    album_name VARCHAR(400) NOT NULL,
    release_date DATE NULL,
    album_image_url TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    submitted_by BIGINT NOT NULL,
    reviewed_by BIGINT NULL,
    CONSTRAINT fk_albums_submitted_by
        FOREIGN KEY (submitted_by) REFERENCES USERS(user_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_albums_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES USERS(user_id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS TRACKS (
    track_id VARCHAR(64) PRIMARY KEY,
    track_name VARCHAR(400) NOT NULL,
    popularity SMALLINT NULL,
    duration_ms INT NULL,
    explicit BOOLEAN NULL,
    preview_url TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    submitted_by BIGINT NOT NULL,
    reviewed_by BIGINT NULL,
    CONSTRAINT fk_tracks_submitted_by
        FOREIGN KEY (submitted_by) REFERENCES USERS(user_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_tracks_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES USERS(user_id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS TRACK_ARTISTS (
    track_id VARCHAR(64) NOT NULL,
    artist_id VARCHAR(64) NOT NULL,
    PRIMARY KEY (track_id, artist_id),
    CONSTRAINT fk_track_artists_track
        FOREIGN KEY (track_id) REFERENCES TRACKS(track_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_track_artists_artist
        FOREIGN KEY (artist_id) REFERENCES ARTISTS(artist_id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ALBUM_TRACKS (
    album_id VARCHAR(64) NOT NULL,
    track_id VARCHAR(64) NOT NULL,
    disc_number SMALLINT NULL,
    track_number SMALLINT NULL,
    PRIMARY KEY (album_id, track_id),
    CONSTRAINT fk_album_tracks_album
        FOREIGN KEY (album_id) REFERENCES ALBUMS(album_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_album_tracks_track
        FOREIGN KEY (track_id) REFERENCES TRACKS(track_id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ALBUM_ARTISTS (
    album_id VARCHAR(64) NOT NULL,
    artist_id VARCHAR(64) NOT NULL,
    PRIMARY KEY (album_id, artist_id),
    CONSTRAINT fk_album_artists_album
        FOREIGN KEY (album_id) REFERENCES ALBUMS(album_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_album_artists_artist
        FOREIGN KEY (artist_id) REFERENCES ARTISTS(artist_id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ARTIST_GENRES (
    artist_id VARCHAR(64) NOT NULL,
    genre VARCHAR(120) NOT NULL,
    PRIMARY KEY (artist_id, genre),
    CONSTRAINT fk_artist_genres_artist
        FOREIGN KEY (artist_id) REFERENCES ARTISTS(artist_id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS AUDIO_FEATURES (
    track_id VARCHAR(64) PRIMARY KEY,
    danceability DECIMAL(5,4) NULL,
    energy DECIMAL(5,4) NULL,
    valence DECIMAL(5,4) NULL,
    acousticness DECIMAL(7,6) NULL,
    instrumentalness DECIMAL(10,9) NULL,
    liveness DECIMAL(5,4) NULL,
    speechiness DECIMAL(5,4) NULL,
    tempo DECIMAL(7,3) NULL,
    `key` SMALLINT NULL,
    mode SMALLINT NULL,
    loudness DECIMAL(7,3) NULL,
    time_signature SMALLINT NULL,
    CONSTRAINT fk_audio_features_track
        FOREIGN KEY (track_id) REFERENCES TRACKS(track_id)
        ON DELETE CASCADE
);