-- Create initial user: testuser / test123
-- Following the exact pattern from misc/test_reset

-- Master DB: library + user
USE zotero_master;
INSERT INTO libraries VALUES (1, 'user', '0000-00-00 00:00:00', 0, 1, 0);
INSERT INTO users VALUES (1, 1, 'testuser');

-- Shard: register library
USE zotero_shard1;
INSERT INTO shardLibraries VALUES (1, 'user', '0000-00-00 00:00:00', 1, 0);

-- WWW DB: user with salted SHA1 password for 'test123'
-- Hash = sha1(AUTH_SALT + password) = sha1('zotero_self_hosted_salt' + 'test123')
USE zotero_www_dev;
INSERT INTO users VALUES (
  1, 'testuser',
  'e30c44d36bba706c6dfc4475cf8289fbd8a87893',
  'test@example.com', NULL, 'member', NULL, NULL, NULL, 1,
  NOW(), NOW(), 0, 'testuser'
);

-- Master DB: API key with full access
USE zotero_master;
INSERT INTO `keys` (`keyID`, `key`, `userID`, `name`, `dateAdded`, `lastUsed`)
VALUES (1, 'GmYMvkzxnJFeCKfDhBBD4ONv', 1, 'Full Access Key', NOW(), NOW());

INSERT INTO keyPermissions VALUES (1, 1, 'library', 1);
INSERT INTO keyPermissions VALUES (1, 1, 'notes', 1);
INSERT INTO keyPermissions VALUES (1, 1, 'write', 1);
INSERT INTO keyPermissions VALUES (1, 0, 'library', 1);
INSERT INTO keyPermissions VALUES (1, 0, 'notes', 1);
INSERT INTO keyPermissions VALUES (1, 0, 'write', 1);
