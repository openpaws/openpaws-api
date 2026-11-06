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
mcli anonymous set-json docs/minio/openpaws-anonymous-policy.json local/openpaws
```

### Bucket layout and anonymous access
- `public/media/{public_id}/…` — general/mature submission assets, served directly via the
  CDN (`OPENPAWS_CDN_URL`, dev `http://127.0.0.1:9000/openpaws`).
- `private/media/{public_id}/…` — adult submission assets, only via short-lived presigned
  URLs (`signed_media_ttl`, 600 s). Moved between the two by `MoveSubmissionMedia` when the
  rating crosses the adult boundary.
- `staging/…` — browser uploads awaiting processing, private.

The policy (`docs/minio/openpaws-anonymous-policy.json`) grants anonymous `s3:GetObject` on
`openpaws/public/*` and nothing else. Do **not** use the canned
`mcli anonymous set download local/openpaws/public`: besides GetObject (on `public*`) it also
grants anonymous `ListBucket` for prefix `public`, which would let anyone enumerate every key
under `public/` (including unlisted submissions' unguessable paths).

Check: `mcli anonymous get-json local/openpaws` shows the single GetObject statement; an
anonymous `curl` of a `public/` object is 200, of a `private/` or `staging/` object 403,
of `http://127.0.0.1:9000/openpaws?list-type=2&prefix=public` 403; a presigned
`temporaryUrl` for a `private/` object is 200.

### CORS for browser uploads
The UI uploads files straight to the bucket with presigned `PUT` URLs, and multipart uploads
read each part's `ETag` response header (sent back in `POST /uploads/{id}/complete`). The rule:

| Field | Value |
|---|---|
| AllowedOrigins | `http://localhost:3000` (the UI) |
| AllowedMethods | `PUT` |
| AllowedHeaders | `*` (the signed `Content-Type` of single PUTs) |
| ExposeHeaders | `ETag` — without it the browser hides the header and the UI fails with "Upload blocked by storage CORS (ETag not exposed)." |

On S3 (and providers with bucket-level CORS) apply it to the bucket:
```bash
mcli cors set local/openpaws - <<'XML'
<CORSConfiguration>
  <CORSRule>
    <AllowedOrigin>http://localhost:3000</AllowedOrigin>
    <AllowedMethod>PUT</AllowedMethod>
    <AllowedHeader>*</AllowedHeader>
    <ExposeHeader>ETag</ExposeHeader>
    <MaxAgeSeconds>3600</MaxAgeSeconds>
  </CORSRule>
</CORSConfiguration>
XML
```
**Our MinIO (RELEASE.2025-10-15) does not implement bucket CORS**: the command above fails with
"A header you provided implies functionality that is not implemented." MinIO applies one
server-wide CORS policy instead. It always allows `PUT`, echoes the requested headers and exposes
`Etag` (among other S3 headers); only the allowed origin is configurable, and it defaults to `*`.
Restrict it to the UI:
```bash
mcli admin config set local api cors_allow_origin=http://localhost:3000
mcli admin config get local api | tr ' ' '\n' | grep cors   # cors_allow_origin=http://localhost:3000
```
Check with a presigned PUT URL (`app(App\Storage\ObjectStorage::class)->presignPut(...)` in
`php artisan tinker`): a preflight `OPTIONS` with `Origin: http://localhost:3000` and
`Access-Control-Request-Method: PUT` returns `Access-Control-Allow-Origin: http://localhost:3000`
(another origin gets no such header); the `PUT` itself returns `ETag` and
`Access-Control-Expose-Headers: …, Etag, …`. For more UI origins, comma-separate them.

## Queue worker
Queues in priority order: `moves` (MoveSubmissionMedia: public/ ↔ private/ after an adult
rating change; short, and must not wait behind transcodes), then `media`, `video`, `default`.
```bash
php artisan queue:work --queue=moves,media,video,default
```
Until a pending move has run, the API returns no URL for an adult submission's assets that are
still under `public/` (the media is briefly missing rather than leaking). If a move fails for good,
it logs at `critical` level ("Moving submission media failed") with the paths that were not moved.

## Search index
Meilisearch must be running (`MEILISEARCH_HOST`/`MEILISEARCH_KEY` in `.env`, `SCOUT_DRIVER=meilisearch`).
After first setup (and whenever the index settings in `config/scout.php` change):
```bash
php artisan scout:sync-index-settings
php artisan scout:import "App\Models\Submission"
```
Submissions are indexed on save (published + public only) and removed on delete or unlisting.

## Verify
```bash
php -m | grep imagick; ffprobe -version | head -1; mariadb -e 'show databases' | grep openpaws
redis-cli ping; curl -s localhost:7700/health; mcli ls local/
```
Expected: `imagick`, ffprobe version, `openpaws` + `openpaws_test`, `PONG`,
`{"status":"available"}`, `openpaws/`.
