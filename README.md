# Zotero 사내 동기화 서버

Zotero 메타데이터(아이템, 컬렉션, 태그, 노트) 동기화를 위한 사내 자체 호스팅 서버입니다.
파일/PDF 동기화는 지원하지 않습니다.

---

## 사전 준비

- Docker & Docker Compose
- 사내 프록시 인증서 (`corporate-proxy-ca.pem`)

## 빠른 시작 (Docker)

### 1. 인증서 배치

사내 프록시 CA 인증서를 `docker/` 디렉토리에 복사합니다.

```bash
cp /path/to/corporate-proxy-ca.pem docker/corporate-proxy-ca.pem
```

### 2. 설정 파일 생성

두 개의 설정 파일을 생성합니다.

**`include/config/config.inc.php`**

```bash
cp include/config/config.inc.php-sample include/config/config.inc.php
```

아래 항목을 수정합니다:

```php
public static $BASE_URI = 'http://localhost:8080/';
public static $API_BASE_URI = 'http://localhost:8080/';
public static $WWW_BASE_URI = 'http://localhost:8080/';

public static $AUTH_SALT = 'zotero_self_hosted_salt';
public static $API_SUPER_USERNAME = 'admin';
public static $API_SUPER_PASSWORD = 'admin_password';  // 반드시 변경

public static $REDIS_HOSTS = [
    'default' => ['host' => 'redis:6379'],
    'request-limiter' => ['host' => 'redis:6379'],
    'notifications' => ['host' => 'redis:6379'],
    'fulltext-migration' => ['host' => 'redis:6379', 'cluster' => false]
];

public static $MEMCACHED_ENABLED = true;
public static $MEMCACHED_SERVERS = ['memcached:11211:1'];
```

**`include/config/dbconnect.inc.php`**

```bash
cp include/config/dbconnect.inc.php-sample include/config/dbconnect.inc.php
```

아래 완전한 예시를 참고하여 설정합니다. Docker 환경 기준 값입니다:

```php
<?
function Zotero_dbConnectAuth($db) {
	$charset = 'utf8mb4';

	// 공통 인증 정보 (모든 DB가 같은 MySQL 인스턴스에 있을 때)
	$commonHost = 'mysql';    // Docker 서비스명. 외부 MySQL이면 IP/호스트명으로 변경
	$commonPort = 3306;
	$commonUser = 'zotero';           // docker/init-db/00-create-databases.sql 에서 생성한 사용자
	$commonPass = 'zotero_app_pw';    // 위 사용자의 비밀번호

	if ($db == 'master') {
		$host = $commonHost;
		$port = $commonPort;
		$replicas = [];   // 단일 인스턴스에서는 빈 배열
		$db = 'zotero_master';
		$user = $commonUser;
		$pass = $commonPass;
		$state = 'up';    // 'up', 'readonly', 'down'
	}
	else if ($db == 'shard') {
		// shard의 host/port/db는 master DB의 shardHosts/shards 테이블에서 동적으로 결정됨
		// 여기서는 user/pass만 제공하면 됨
		$host = false;
		$port = false;
		$db = false;
		$user = $commonUser;
		$pass = $commonPass;
	}
	else if ($db == 'id1') {
		$host = $commonHost;
		$port = $commonPort;
		$db = 'zotero_ids';
		$user = $commonUser;
		$pass = $commonPass;
	}
	else if ($db == 'id2') {
		// id2는 id1과 같은 DB를 가리킴 (단일 인스턴스 환경)
		$host = $commonHost;
		$port = $commonPort;
		$db = 'zotero_ids';
		$user = $commonUser;
		$pass = $commonPass;
	}
	else if ($db == 'www1') {
		$host = $commonHost;
		$port = $commonPort;
		$db = 'zotero_www_dev';
		$user = $commonUser;
		$pass = $commonPass;
	}
	else if ($db == 'www2') {
		// www2는 www1과 같은 DB를 가리킴 (단일 인스턴스 환경)
		$host = $commonHost;
		$port = $commonPort;
		$db = 'zotero_www_dev';
		$user = $commonUser;
		$pass = $commonPass;
	}
	else {
		throw new Exception("Invalid db '$db'");
	}

	return [
		'host' => $host,
		'replicas' => !empty($replicas) ? $replicas : [],
		'port' => $port,
		'db' => $db,
		'user' => $user,
		'pass' => $pass,
		'charset' => $charset,
		'state' => !empty($state) ? $state : 'up'
	];
}
?>
```

> **참고:**
> - `shard`의 `host`/`port`/`db`는 `false`로 유지합니다. 실제 연결 정보는 `zotero_master.shardHosts` + `zotero_master.shards` 테이블에서 동적으로 가져옵니다.
> - `id1`과 `id2`, `www1`과 `www2`는 각각 같은 DB를 가리킵니다 (고가용성 구성이 아닌 단일 인스턴스 환경).
> - Docker Compose가 아닌 외부 MySQL을 사용할 경우 `$commonHost`를 해당 서버 주소로 변경하세요.

### 3. 서버 실행

```bash
cd docker
docker compose up -d --build
```

첫 실행 시 DB 초기화와 Composer 의존성 설치가 자동으로 진행됩니다.
서버가 준비되면 `http://localhost:8080` 으로 접속할 수 있습니다.

### 4. 서버 중지 / 재시작

```bash
docker compose down        # 중지
docker compose up -d       # 재시작
docker compose down -v     # 중지 + DB 데이터 삭제 (초기화)
```

### 5. 로그 확인

```bash
docker compose logs -f php-fpm   # PHP 로그
docker compose logs -f nginx     # Nginx 로그
docker compose logs -f mysql     # MySQL 로그
```

---

## Web UI

### 관리자 페이지 (`/admin.php`)

`http://<호스팅IP>:8080/admin.php`

- **인증**: `config.inc.php`의 `API_SUPER_USERNAME` / `API_SUPER_PASSWORD`로 로그인
- **기능**:
  - 서버 상태 대시보드 (사용자, 그룹, 아이템 수 등)
  - 사용자 생성 / 삭제 / 비밀번호 변경
  - 그룹 생성 / 삭제 / 멤버 관리
  - API 키 생성 / 삭제

### 사용자 페이지 (`/account.php`)

`http://<호스팅IP>:8080/account.php`

일반 사용자가 자신의 계정을 관리하는 페이지입니다. 자신의 사용자명/비밀번호로 로그인합니다.

- **Settings 탭**: 프로필 정보 확인, 이메일 변경, 비밀번호 변경
- **Groups 탭**: 내 그룹 목록 확인, 새 그룹 생성, 그룹 멤버 관리, 그룹 탈퇴/삭제
- **API Keys 탭**: API 키 목록 확인, 새 키 생성, 키 삭제

---

## 사용자 계정

### 계정 요청

사용자 계정은 관리자(admin)에게 **메일로 요청**해 주세요.
계정 생성 후 사용자명과 API 키를 전달받습니다.

### 기본 테스트 계정

서버 초기 설정 시 아래 테스트 계정이 자동 생성됩니다:

| 항목 | 값 |
|------|-----|
| 사용자명 | `testuser` |
| 비밀번호 | `test123` |
| API 키 | `GmYMvkzxnJFeCKfDhBBD4ONv` |

> 운영 환경에서는 반드시 테스트 계정을 삭제하고 새 계정을 생성하세요.

---

## Zotero 클라이언트 설정

### 동기화 전 백업 (필수)

**싱크를 설정하기 전에 반드시 기존 라이브러리를 백업하세요.**

1. Zotero에서 `파일` → `라이브러리 내보내기...` 선택
2. 형식: **Zotero RDF** 선택
3. `파일 내보내기` 및 `노트` 옵션 체크
4. 안전한 위치에 저장

### 서버 연결 설정

1. Zotero 클라이언트 실행
2. `편집` → `설정` → `고급` → `설정 편집기` 열기
3. 아래 설정값을 검색하여 변경:

| 설정 키 | 값 |
|---------|-----|
| `extensions.zotero.api.url` | `http://<서버주소>:8080/` |
| `extensions.zotero.streaming.enabled` | `false` |

4. Zotero 재시작
5. `편집` → `설정` → `동기화`에서 사용자명/비밀번호 입력 후 동기화

> `<서버주소>`는 서버가 실행 중인 호스트의 IP 또는 도메인으로 변경하세요.

---

## 사내 네트워크 참고

- 사내 프록시 환경에서는 `corporate-proxy-ca.pem` 인증서가 Docker 빌드 시 자동으로 등록됩니다
- 프록시 설정이 필요한 경우 Docker 빌드 시 `--build-arg` 로 전달합니다:

```bash
docker compose build \
  --build-arg http_proxy=http://proxy.corp:8080 \
  --build-arg https_proxy=http://proxy.corp:8080
```
