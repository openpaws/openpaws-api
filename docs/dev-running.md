# Running OpenPaws API locally

Prerequisites: the services in [dev-setup.md](dev-setup.md) are running (MariaDB, Redis,
MinIO, Meilisearch) and `.env` is filled in.

## Processes

Run each in its own terminal (or background it with `nohup`, logs under `storage/logs/`).

```bash
# HTTP API
php artisan serve --port=8000

# Queue worker; the queue order matters (moves first so rating flips are not stuck behind transcodes)
php artisan queue:work redis --queue=moves,media,video,default

# Scheduler (pruning stale uploads etc.)
php artisan schedule:work
```

Note: `php artisan serve` only forwards a fixed allow-list of environment variables to the
PHP server, so per-run overrides such as `OPENPAWS_SIGNED_MEDIA_TTL=5 php artisan serve`
are ignored. Put them in `.env`, or run the server directly:
`cd public && OPENPAWS_SIGNED_MEDIA_TTL=5 php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.

The worker's PHP `memory_limit` comes from `/etc/php85/php.ini` (64M on this box). Large
video uploads (> ~100 MB) can exhaust it in `FileSignature::detect`; start the worker with
`php -d memory_limit=512M artisan queue:work ...` when processing big files.

## Search index

```bash
php artisan scout:sync-index-settings
php artisan scout:import "App\Models\Submission"
```

## Talking to the API with curl (Sanctum SPA cookie session)

The API authenticates the SPA with cookies, so requests must look like they come from the
UI origin (`SANCTUM_STATEFUL_DOMAINS`, dev `localhost:3000`) and carry the CSRF token.

```bash
J=/tmp/jar.txt; B=http://localhost:8000
H=(-H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" -H "Accept: application/json")

# 1. get the XSRF-TOKEN cookie
curl -s -c $J -b $J "${H[@]}" $B/sanctum/csrf-cookie -o /dev/null

# 2. send its URL-decoded value as X-XSRF-TOKEN on every non-GET
XSRF=$(grep XSRF-TOKEN $J | awk '{print $7}' | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
curl -s -c $J -b $J "${H[@]}" -H "X-XSRF-TOKEN: $XSRF" -H 'Content-Type: application/json' \
  -X POST $B/api/v1/auth/register \
  -d '{"username":"demo","display_name":"Demo","email":"demo@example.test","password":"Passw0rd!xyz","password_confirmation":"Passw0rd!xyz"}'
```

Login takes `{"login": <username or email>, "password": ...}`. Verify the email in dev with
`php artisan tinker --execute='App\Models\User::where("username","demo")->update(["email_verified_at"=>now()]);'`,
then `POST /api/v1/me/adult-confirmation {"confirm":true}` and `PATCH /api/v1/me/settings {"max_rating":"adult"}`
to see adult content.

Upload flow: `POST /uploads` -> `PUT` the file to the returned presigned URL (single) or each
`POST /uploads/{id}/parts` URL (multipart, keep every part's `ETag` response header) ->
`POST /uploads/{id}/complete` (`{"parts":[{"part_number":1,"etag":"..."}]}` for multipart) ->
`POST /submissions`, then poll `GET /submissions/{id}` until `status` is `published`.
