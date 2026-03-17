-- Load master schema and core data
USE zotero_master;
SOURCE /docker-entrypoint-initdb.d/master.schema;
SOURCE /docker-entrypoint-initdb.d/coredata.schema;

-- Register shard host — address must be reachable from PHP container
-- PHP connects to this address to reach the shard DB
INSERT INTO shardHosts VALUES (1, 'mysql', 3306, 'up');

-- Register shard 1
INSERT INTO shards VALUES (1, 1, 'zotero_shard1', 'up', 0);
