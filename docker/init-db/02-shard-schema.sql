-- Load shard schema and triggers
USE zotero_shard1;
SOURCE /docker-entrypoint-initdb.d/shard.schema;
SOURCE /docker-entrypoint-initdb.d/triggers.schema;
