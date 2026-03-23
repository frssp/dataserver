<?
class Z_CONFIG {
	public static $API_ENABLED = true;
	public static $READ_ONLY = false;
	public static $MAINTENANCE_MESSAGE = '';
	public static $BACKOFF = 0;

	public static $TESTING_SITE = true;
	public static $DEV_SITE = true;

	public static $DEBUG_LOG = false;

	public static $BASE_URI = '';
	public static $API_BASE_URI = 'http://localhost:8080/';
	public static $WWW_BASE_URI = 'http://localhost:8080/';

	public static $AUTH_SALT = 'zotero_self_hosted_salt';
	public static $API_SUPER_USERNAME = 'admin';
	public static $API_SUPER_PASSWORD = 'Hfzr!0527';

	// AWS — dummy credentials (SDK factory created but never called for metadata sync)
	public static $AWS_REGION = 'us-east-1';
	public static $AWS_ACCESS_KEY = 'dummy';
	public static $AWS_SECRET_KEY = 'dummy';
	public static $S3_BUCKET = '';
	public static $S3_BUCKET_CACHE = '';
	public static $S3_BUCKET_FULLTEXT = '';
	public static $S3_BUCKET_ERRORS = '';
	public static $SNS_ALERT_TOPIC = '';

	// Redis
	public static $REDIS_HOSTS = [
		'default' => ['host' => 'redis:6379'],
		'request-limiter' => ['host' => 'redis:6379'],
		'notifications' => ['host' => 'redis:6379'],
	];
	public static $REDIS_PREFIX = 'zotero_';

	// Memcached
	public static $MEMCACHED_ENABLED = true;
	public static $MEMCACHED_SERVERS = ['memcached:11211:1'];

	public static $TRANSLATION_SERVERS = ['http://translation:1969'];
	public static $CITATION_SERVERS = [];
	public static $SEARCH_HOSTS = [''];

	public static $GLOBAL_ITEMS_URL = '';
	public static $ATTACHMENT_PROXY_URL = '';
	public static $ATTACHMENT_PROXY_SECRET = '';

	// TTS — disabled
	public static $TTS_TABLE = 'TTS';
	public static $S3_BUCKET_TTS = '';
	public static $TTS_AUDIO_DOMAIN = '';
	public static $TTS_CREDIT_LIMITS = [
		'standard' => ['free' => 0, 'personal' => 0, 'institutional' => 0],
		'premium' => ['free' => 0, 'personal' => 0, 'institutional' => 0],
	];
	public static $TTS_DAILY_LIMIT_MINUTES = 0;

	// Monitoring — disabled
	public static $STATSD_ENABLED = false;
	public static $STATSD_PREFIX = '';
	public static $STATSD_HOST = 'localhost';
	public static $STATSD_PORT = 8125;

	public static $LOG_TO_SCRIBE = false;
	public static $LOG_ADDRESS = '';
	public static $LOG_PORT = 1463;
	public static $LOG_TIMEZONE = 'UTC';
	public static $LOG_TARGET_DEFAULT = 'errors';

	public static $HTMLCLEAN_SERVER_URL = '';
	public static $CLI_PHP_PATH = '/usr/local/bin/php';

	public static $CACHE_VERSION_ATOM_ENTRY = 1;
	public static $CACHE_VERSION_BIB = 1;
	public static $CACHE_VERSION_RESPONSE_JSON_COLLECTION = 1;
	public static $CACHE_VERSION_RESPONSE_JSON_ITEM = 1;
	public static $CACHE_ENABLED_ITEM_RESPONSE_JSON = true;
}
?>
