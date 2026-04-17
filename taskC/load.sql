USE musicbox;

INSERT INTO USERS (user_id, email, password_hash, role, is_active) VALUES
(1, 'admin@musicbox.com', 'hash_admin_001', 'admin', TRUE),
(2, 'analyst1@musicbox.com', 'hash_analyst_001', 'analyst', TRUE),
(3, 'admin2@musicbox.com', 'hash_admin_002', 'admin', TRUE),
(4, 'analyst2@musicbox.com', 'hash_analyst_002', 'analyst', TRUE);
INSERT INTO ARTISTS (artist_id, artist_name, status, submitted_by, reviewed_by) VALUES
('a1', 'Taylor Swift', 'approved', 2, 1),
('a2', 'The Weeknd', 'approved', 2, 1),
('a3', 'Olivia Rodrigo', 'pending', 4, NULL),
('a4', 'SZA', 'approved', 4, 3),
('a5', 'Drake', 'rejected', 2, 1);

INSERT INTO ALBUMS (album_id, album_name, release_date, album_image_url, status, submitted_by, reviewed_by) VALUES
('al1', '1989', '2014-10-27', 'https://example.com/1989.jpg', 'approved', 2, 1),
('al2', 'After Hours', '2020-03-20', 'https://example.com/afterhours.jpg', 'approved', 2, 1),
('al3', 'GUTS', '2023-09-08', 'https://example.com/guts.jpg', 'pending', 4, NULL),
('al4', 'SOS', '2022-12-09', 'https://example.com/sos.jpg', 'approved', 4, 3),
('al5', 'Take Care', '2011-11-15', 'https://example.com/takecare.jpg', 'rejected', 2, 1);

INSERT INTO TRACKS (track_id, track_name, popularity, duration_ms, explicit, preview_url, status, submitted_by, reviewed_by) VALUES
('t1', 'Blank Space', 95, 231000, FALSE, 'https://example.com/blankspace.mp3', 'approved', 2, 1),
('t2', 'Blinding Lights', 98, 200040, FALSE, 'https://example.com/blindinglights.mp3', 'approved', 2, 1),
('t3', 'vampire', 92, 219724, TRUE, 'https://example.com/vampire.mp3', 'pending', 4, NULL),
('t4', 'Kill Bill', 94, 153947, TRUE, 'https://example.com/killbill.mp3', 'approved', 4, 3),
('t5', 'Marvins Room', 88, 347133, TRUE, 'https://example.com/marvinsroom.mp3', 'rejected', 2, 1);

INSERT INTO TRACK_ARTISTS (track_id, artist_id) VALUES
('t1', 'a1'),
('t2', 'a2'),
('t3', 'a3'),
('t4', 'a4'),
('t5', 'a5');

INSERT INTO ALBUM_ARTISTS (album_id, artist_id) VALUES
('al1', 'a1'),
('al2', 'a2'),
('al3', 'a3'),
('al4', 'a4'),
('al5', 'a5');

INSERT INTO ALBUM_TRACKS (album_id, track_id, disc_number, track_number) VALUES
('al1', 't1', 1, 1),
('al2', 't2', 1, 1),
('al3', 't3', 1, 1),
('al4', 't4', 1, 1),
('al5', 't5', 1, 1);

INSERT INTO ARTIST_GENRES (artist_id, genre) VALUES
('a1', 'Pop'),
('a2', 'R&B'),
('a3', 'Pop Rock'),
('a4', 'R&B'),
('a5', 'Hip-Hop');

INSERT INTO AUDIO_FEATURES (track_id, danceability, energy, valence, acousticness, instrumentalness, liveness, speechiness, tempo, `key`, mode, loudness, time_signature) VALUES
('t1', 0.75, 0.68, 0.58, 0.12, 0.00001, 0.09, 0.04, 96.5, 2, 1, -5.2, 4),
('t2', 0.80, 0.73, 0.62, 0.09, 0.00000, 0.10, 0.05, 171.0, 1, 1, -4.8, 4),
('t3', 0.60, 0.70, 0.30, 0.15, 0.00002, 0.11, 0.06, 138.2, 9, 0, -6.1, 4),
('t4', 0.83, 0.55, 0.40, 0.20, 0.00000, 0.12, 0.07, 89.3, 6, 1, -7.0, 4),
('t5', 0.50, 0.45, 0.25, 0.30, 0.00010, 0.08, 0.09, 80.0, 11, 0, -8.3, 4);
