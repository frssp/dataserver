<?php
namespace Aws;

/**
 * Stub: AWS SDK not needed for metadata-only self-hosted sync.
 */
class Sdk {
    public function __construct(array $args = []) {}
    public function createClient($name, array $args = []) { return null; }
    public function __call($name, array $args) { return null; }
}
