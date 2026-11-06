# OpenPaws dev environment setup

Alpine 3.24 x86_64, run as root. Secrets live in `/root/openpaws-dev-secrets.env`
(chmod 600, not in git): `DB_PASSWORD`, `MEILISEARCH_KEY`, `MINIO_ROOT_USER`,
`MINIO_ROOT_PASSWORD`. Copy them into `.env` (Task 2).

## Packages
```bash
apk add mariadb ffmpeg imagemagick php85-pecl-imagick minio minio-client exiftool gcompat
```
The MinIO client binary is installed as **`mcli`** (not `mc`).

## MariaDB and Redis (OpenRC, enabled at boot)
```bash
mariadb-install-db --user=mysql --datadir=/var/lib/mysql
rc-update add mariadb default && rc-service mariadb start
rc-update add redis default && rc-service redis start
```

## Databases and user
Password generated with `openssl rand -hex 16`.
```sql
CREATE DATABASE openpaws CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE openpaws_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'openpaws'@'localhost' IDENTIFIED BY '<DB_PASSWORD>';
GRANT ALL ON openpaws.* TO 'openpaws'@'localhost';
GRANT ALL ON openpaws_test.* TO 'openpaws'@'localhost';
```

## Meilisearch v1.56.1
Official `meilisearch-linux-amd64` release binary placed at `/usr/local/bin/meilisearch`.
It is a glibc binary; on Alpine it needs `gcompat` AND a tiny shim, because gcompat
lacks `__res_init` ("Error relocating ... __res_init: symbol not found").
The shim `/usr/local/lib/libresshim.so` (built once with gcc, compiler since removed) is:
```c
#include <resolv.h>
int __res_init(void) { return res_init(); }
```
It is loaded via `LD_PRELOAD` in the supervisord program. Export it too if running
`meilisearch` by hand: `LD_PRELOAD=/usr/local/lib/libresshim.so meilisearch --version`.

## MinIO and Meilisearch (supervisord)
Programs `openpaws-minio` and `openpaws-meilisearch` in `/etc/supervisor.d/openpaws.ini`
(mode 600, contains the credentials). Both listen on 127.0.0.1 only
(MinIO :9000, console disabled via `MINIO_BROWSER=off`; Meilisearch :7700 with master key).
Data: `/var/lib/minio`, `/var/lib/meilisearch`. Logs: `/var/log/openpaws/`.
```bash
supervisorctl update
supervisorctl status
```

## Bucket
```bash
. /root/openpaws-dev-secrets.env
mcli alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD"
mcli mb --ignore-existing local/openpaws
```

## Verify
```bash
php -m | grep imagick; ffprobe -version | head -1; mariadb -e 'show databases' | grep openpaws
redis-cli ping; curl -s localhost:7700/health; mcli ls local/
```
Expected: `imagick`, ffprobe version, `openpaws` + `openpaws_test`, `PONG`,
`{"status":"available"}`, `openpaws/`.
