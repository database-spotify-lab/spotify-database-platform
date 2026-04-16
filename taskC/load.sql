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
