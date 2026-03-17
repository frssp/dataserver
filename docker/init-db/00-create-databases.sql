-- Create all databases
CREATE DATABASE IF NOT EXISTS zotero_master CHARACTER SET utf8mb4;
CREATE DATABASE IF NOT EXISTS zotero_shard1 CHARACTER SET utf8mb4;
CREATE DATABASE IF NOT EXISTS zotero_ids CHARACTER SET utf8mb4;
CREATE DATABASE IF NOT EXISTS zotero_www_dev CHARACTER SET utf8mb4;

-- Create application user
CREATE USER IF NOT EXISTS 'zotero'@'%' IDENTIFIED BY 'zotero_app_pw';
GRANT ALL PRIVILEGES ON zotero_master.* TO 'zotero'@'%';
GRANT ALL PRIVILEGES ON zotero_shard1.* TO 'zotero'@'%';
GRANT ALL PRIVILEGES ON zotero_ids.* TO 'zotero'@'%';
GRANT ALL PRIVILEGES ON zotero_www_dev.* TO 'zotero'@'%';
FLUSH PRIVILEGES;
