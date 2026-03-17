-- Load ID server schema (Flickr ticket server pattern)
USE zotero_ids;
SOURCE /docker-entrypoint-initdb.d/ids.schema;
