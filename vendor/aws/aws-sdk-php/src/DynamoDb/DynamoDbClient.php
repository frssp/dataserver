<?php
namespace Aws\DynamoDb;

/**
 * Stub: DynamoDB not needed for metadata-only self-hosted sync.
 */
class DynamoDbClient {
    public function __construct(array $args = []) {}
    public function __call($name, array $args) { return null; }
}
