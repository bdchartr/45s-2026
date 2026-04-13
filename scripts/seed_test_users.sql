INSERT INTO users (username, email, password_hash, role, auth_provider)
VALUES
  ('test_north', 'test_north@45s.local', NULL, 'owner', 'local'),
  ('test_east', 'test_east@45s.local', NULL, 'admin', 'local'),
  ('test_south', 'test_south@45s.local', NULL, 'player', 'local'),
  ('test_west', 'test_west@45s.local', NULL, 'player', 'local')
ON DUPLICATE KEY UPDATE
  role = VALUES(role),
  auth_provider = VALUES(auth_provider),
  updated_at = CURRENT_TIMESTAMP;

SELECT id, username, email
FROM users
WHERE username IN ('test_north', 'test_east', 'test_south', 'test_west')
ORDER BY id;
