-- WWW database for authentication
-- This DB schema is NOT in the dataserver repo; it belongs to the Zotero website.
-- Minimal schema reverse-engineered from test_reset and Password.inc.php.
USE zotero_www_dev;

CREATE TABLE IF NOT EXISTS users (
  userID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  email VARCHAR(255),
  secondary_email VARCHAR(255),
  role ENUM('member','admin','deleted') DEFAULT 'member',
  col6 VARCHAR(255),
  col7 VARCHAR(255),
  col8 VARCHAR(255),
  active TINYINT(1) DEFAULT 1,
  dateAdded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  dateModified TIMESTAMP,
  col12 INT DEFAULT 0,
  slug VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users_email (
  userID INT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  PRIMARY KEY (userID, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users_meta (
  userID INT UNSIGNED NOT NULL,
  metaKey VARCHAR(255) NOT NULL,
  metaValue TEXT,
  PRIMARY KEY (userID, metaKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(255) NOT NULL PRIMARY KEY,
  userID INT UNSIGNED NOT NULL,
  modified INT UNSIGNED NOT NULL,
  lifetime INT UNSIGNED NOT NULL DEFAULT 86400,
  KEY (userID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vanilla Forums compatibility (banned-user check in Users.inc.php)
CREATE TABLE IF NOT EXISTS GDN_User (
  UserID INT UNSIGNED NOT NULL PRIMARY KEY,
  Banned TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
