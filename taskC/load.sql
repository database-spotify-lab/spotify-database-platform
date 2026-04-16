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
