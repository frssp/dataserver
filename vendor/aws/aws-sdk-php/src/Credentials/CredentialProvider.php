<?php
namespace Aws\Credentials;

/**
 * Stub: AWS credentials not needed for metadata-only self-hosted sync.
 */
class CredentialProvider {
    public static function defaultProvider(array $config = []) { return function() {}; }
    public static function cache(callable $provider, $cache, $cacheKey = null) { return function() {}; }
}
