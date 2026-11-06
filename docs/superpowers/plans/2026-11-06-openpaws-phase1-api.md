# OpenPaws Phase 1 API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the openpaws-api Laravel backend for Phase 1: accounts, profiles, direct-to-bucket uploads for all four media types, queue processing, folders, tags, the ratings filter, browse and search.

**Architecture:** A Laravel JSON API under `/api/v1`, with Sanctum SPA cookie auth. Data lives in MariaDB, and Redis handles cache, queues and rate limits. Object storage sits behind an `ObjectStorage` interface: S3/MinIO in real use, a local fake in tests. One `ProcessSubmission` job sends each submission to a processor for its media type. All rating rules live in `Submission` query scopes, plus a matching Scout filter.

**Tech Stack:** PHP 8.5, Laravel 13.x, Pest 4, MariaDB, Redis (phpredis), Laravel Sanctum, Laravel Scout + Meilisearch, `aws/aws-sdk-php` (via `league/flysystem-aws-s3-v3`), `intervention/image` v3 (Imagick driver), ffmpeg/ffprobe CLI via `Symfony\Component\Process`, `league/commonmark` (bundled with Laravel).

**Spec:** `docs/superpowers/specs/2026-11-06-openpaws-phase1-design.md` (read it alongside this plan).

**Companion plan:** `openpaws-ui/docs/superpowers/plans/2026-11-06-openpaws-phase1-ui.md`, which consumes this API.

## Global Constraints

- PHP 8.5, Laravel 13.x, Pest 4. Dev server is Alpine 3.24 (x86_64). Commands run as root; after every task run `chown -R devkat:devkat /var/www/devkat.uk/htdocs/openpaws-api`.
- Run Composer as `COMPOSER_ALLOW_SUPERUSER=1 composer ...`.
- All routes live under `/api/v1`. All responses are JSON. Errors use `{ "error": { "code": string, "message": string, "fields"?: {field: string[]} } }`.
- Error codes: `validation_failed` (422), `unauthenticated` (401), `forbidden` (403), `not_found` (404), `rate_limited` (429), `server_error` (500), and the domain codes `upload_not_complete`, `upload_already_used`, `quota_exceeded`, `invalid_state` (all 422).
- Every response carries an `X-Request-Id` header. An incoming `X-Request-Id` is reused if it is 1–64 chars `[A-Za-z0-9-]`; otherwise a UUID is generated.
- Content a viewer may not see returns **404**, never 403.
- `public_id`: 10 characters from `[a-z0-9]`, unique, used as the route key for submissions and folders.
- Ratings `general|mature|adult`. Guests and users without `adult_confirmed_at` get an effective rating of `general`.
- Cursor pagination: default 48 items, max 96 (`?per_page=`), cursor in `?cursor=`. Response shape `{ data: [], meta: { next_cursor: string|null } }`.
- Limits (in `config/openpaws.php`, env-overridable): image 50 MB; audio 200 MB; video 2 GB; document 10 MB; cover 10 MB; avatar/banner 10 MB. Multipart above 100 MB with 50 MB parts. Presigned URL TTL 3600 s. Adult signed media URL TTL 600 s. Daily quota 100 uploads and 10 GB per user. Stale upload sessions expire after 24 h. Soft-deleted submissions are purged after 30 days.
- Field limits: title 1–150; description ≤ 20 000; writing body ≤ 500 000; ≤ 60 tags; tag slug `[a-z0-9_]{1,50}`; username `[a-z0-9_-]{3,30}`, case-insensitive; profile links ≤ 10; pinned submissions ≤ 6.
- Image sizes: `thumb_sm` 200 px, `thumb_md` 400 px, `thumb_lg` 800 px (longest edge), `preview` ≤ 2000 px longest edge, all WebP. Audio transcode AAC 256 kbps `.m4a`. Video H.264/AAC MP4 ≤ 1080p with `+faststart`. Poster frame at 10% of the running time.
- Rate limits: login 5/min per IP+username; register 3/hour per IP; uploads 30/hour per user; search 60/min per IP.
- Markdown (description, body, bio) is stored raw. The API returns `*_html` fields rendered by `App\Support\Markdown::toSafeHtml`: CommonMark with `html_input: strip` and `allow_unsafe_links: false`.
- Tests use the `collection` Scout driver, the `sync` queue, the `array` mailer, and the `FakeObjectStorage` binding.

## Review Focus

1. **Reused or missing upload**: calling `POST /submissions` with an upload session whose file never reached the bucket, or one already consumed, must give `upload_not_complete` or `upload_already_used`. It must not create a submission stuck in `processing`. Tests in Task 6.
2. **Tag input variety**: `"Wolf"`, `" wolf "` and `"wolf!"` must collapse to one tag `wolf`. Tags that are empty after cleaning are dropped. More than 60 distinct tags is a 422. Test in Task 8.
3. **Corrupt media that passes the type check**: a truncated MP4 or an MP3 header followed by junk must end in `failed`, with the staging file deleted, never stuck in `processing`. Test in Task 7.
4. **Username collisions and reserved names**: `Foxy` and `foxy` cannot both register. Reserved names (`admin`, `api`, `settings`, `upload`, `search`, `browse`, `login`, `register`, `me`, `openpaws`, `moderator`, `support`) are rejected. Test in Task 3.
5. **Markdown XSS**: `<script>`, `javascript:` links and raw HTML in description, body or bio must not appear in any `*_html` field. Test in Task 4.

---

## File map

```
app/
  Enums/               Rating, SubmissionType, SubmissionStatus, Visibility, UserRole, UserStatus,
                       FolderKind, AssetRole, UploadKind, UploadStatus
  Exceptions/          DomainError.php (code + message -> 422)
  Http/
    Controllers/Api/V1/  Auth/*, MeController, ProfileController, UserController, UploadController,
                         SubmissionController, FolderController, BrowseController, SearchController, TagController
    Middleware/          RequestId.php
    Requests/            one FormRequest per write endpoint
    Resources/           UserResource, ProfileResource, SubmissionResource, MediaAssetResource,
                         FolderResource, TagResource, UploadSessionResource
  Jobs/                ProcessSubmission.php, ProcessProfileImage.php
  Console/Commands/    ExpireUploads.php, PurgeDeletedSubmissions.php
  Media/
    FileSignature.php           detect real mime via finfo
    Ffmpeg.php                  thin wrapper: probe(), run()
    AssetDraft.php              readonly DTO
    Processors/{MediaProcessor,ImageProcessor,AudioProcessor,VideoProcessor,WritingProcessor}.php
    MediaUrls.php               CDN vs signed URL
  Models/              User, Profile, Submission, MediaAsset, Folder, Tag, UploadSession
  Models/Concerns/HasPublicId.php
  Storage/             ObjectStorage.php (interface), S3ObjectStorage.php, FakeObjectStorage.php
  Support/             PublicId.php, Markdown.php, TagNormalizer.php, ReservedUsernames.php, ViewCounter.php
  Uploads/             UploadLimits.php, UploadQuota.php
config/openpaws.php
routes/api.php
tests/Feature/...  tests/Unit/...  tests/Fixtures/media/*
```

---

### Task 1: Dev environment services

**Files:**
- Create: `docs/dev-setup.md` (a record of the commands below)

**Interfaces:**
- Produces: running MariaDB (DBs `openpaws`, `openpaws_test`; user `openpaws`/password in `.env`), Redis on 6379, MinIO on 9000 (bucket `openpaws`), Meilisearch on 7700 with a master key, `ffmpeg`/`ffprobe` on PATH, PHP `imagick` extension loaded.

- [ ] **Step 1: Install packages**

```bash
apk add mariadb ffmpeg imagemagick php85-pecl-imagick minio minio-client exiftool
```

- [ ] **Step 2: Initialise and start MariaDB and Redis, enabled at boot**

```bash
mariadb-install-db --user=mysql --datadir=/var/lib/mysql
rc-update add mariadb default && rc-service mariadb start
rc-update add redis default && rc-service redis start
```

- [ ] **Step 3: Create the databases and user** (generate the password with `openssl rand -hex 16` and record it only in `.env`, Task 2)

```sql
CREATE DATABASE openpaws CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE openpaws_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'openpaws'@'localhost' IDENTIFIED BY '<generated>';
GRANT ALL ON openpaws.* TO 'openpaws'@'localhost';
GRANT ALL ON openpaws_test.* TO 'openpaws'@'localhost';
```

- [ ] **Step 4: Install Meilisearch** from the official GitHub release (static `meilisearch-linux-amd64`, pinned version) to `/usr/local/bin/meilisearch`. Run MinIO (`/var/lib/minio`, console off) and Meilisearch (`--db-path /var/lib/meilisearch --master-key <generated>`) as supervisord programs in `/etc/supervisor.d/openpaws.ini`. Create bucket `openpaws` with `mc mb`.

- [ ] **Step 5: Verify**

Run: `php -m | grep imagick; ffprobe -version | head -1; mariadb -e 'show databases' | grep openpaws; redis-cli ping; curl -s localhost:7700/health; mc ls local/`
Expected: `imagick`, an ffprobe version, both DBs, `PONG`, `{"status":"available"}`, bucket `openpaws/`.

- [ ] **Step 6: Commit** `docs/dev-setup.md` with message `docs: dev environment setup`.

---

### Task 2: Laravel scaffold, error format, request IDs

**Files:**
- Create: the Laravel app (via create-project into a temp dir, then move the contents in so `LICENSE`, `README.md` and `docs/` are kept), `config/openpaws.php`, `app/Http/Middleware/RequestId.php`, `app/Exceptions/DomainError.php`
- Modify: `bootstrap/app.php` (API routing with prefix `api/v1`, middleware, exception rendering), `.env`, `.env.example`, `phpunit.xml`
- Test: `tests/Feature/ErrorFormatTest.php`, `tests/Feature/RequestIdTest.php`

**Interfaces:**
- Produces: `App\Exceptions\DomainError(string $errorCode, string $message, int $status = 422)`, renderable as the error shape. `config('openpaws.*')` with the keys `limits.{image,audio,video,document,cover,avatar,banner}` (bytes), `multipart.threshold`, `multipart.part_size`, `presign_ttl`, `signed_media_ttl`, `quota.daily_count`, `quota.daily_bytes`, `uploads.stale_hours`, `purge_days`, `ui_url`, `cdn_url`, `pagination.default`, `pagination.max`.

- [ ] **Step 1: Create the project and install packages**

```bash
cd "$(mktemp -d)" && COMPOSER_ALLOW_SUPERUSER=1 composer create-project laravel/laravel api-skel
# move api-skel/* and dotfiles into openpaws-api without overwriting LICENSE/README.md/docs
php artisan install:api   # Sanctum + routes/api.php
COMPOSER_ALLOW_SUPERUSER=1 composer require laravel/scout meilisearch/meilisearch-php http-interop/http-factory-guzzle league/flysystem-aws-s3-v3 intervention/image
COMPOSER_ALLOW_SUPERUSER=1 composer require --dev pestphp/pest pestphp/pest-plugin-laravel
```
Configure `.env` with MariaDB `openpaws`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `SCOUT_DRIVER=meilisearch`, the S3 vars pointing at MinIO (`AWS_USE_PATH_STYLE_ENDPOINT=true`), `OPENPAWS_UI_URL=http://localhost:3000`, `SANCTUM_STATEFUL_DOMAINS=localhost:3000`, `SESSION_DOMAIN=localhost`. In `phpunit.xml`, set `DB_DATABASE=openpaws_test`, `SCOUT_DRIVER=collection`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`.

- [ ] **Step 2: Write the failing tests**

```php
it('renders unknown routes in the error shape', function () {
    $this->getJson('/api/v1/nope')->assertStatus(404)
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
});
it('renders validation errors with fields', function () {
    // throwaway test route registered in the test: validates ['title' => 'required']
    $this->postJson('/api/v1/_test/validate', [])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['message', 'fields' => ['title']]]);
});
it('renders DomainError with its code', fn () => /* route throws new DomainError('invalid_state','Nope.') */
    $this->postJson('/api/v1/_test/domain')->assertStatus(422)->assertJsonPath('error.code','invalid_state'));
it('hides exception details on 500 when not debugging', ...);           // code server_error, generic message
it('echoes a valid X-Request-Id and generates one otherwise', ...);      // header present; invalid input replaced by UUID
```

- [ ] **Step 3: Run** `php artisan test` — Expected: FAIL.

- [ ] **Step 4: Implement** the exception rendering in `bootstrap/app.php` (`->withExceptions`): map `ValidationException`, `AuthenticationException`, `AuthorizationException`, `ModelNotFoundException`/`NotFoundHttpException` (→ `not_found`, `"Not found."`), `ThrottleRequestsException`, `DomainError` and any other `Throwable` to the error shape. Add the `RequestId` middleware to the API group, and log the request ID in the log context.

- [ ] **Step 5: Run** `php artisan test` — Expected: PASS.

- [ ] **Step 6: Commit** `feat: scaffold Laravel API with error format and request ids`.

---

### Task 3: Users, registration, login, email verification, password reset

**Files:**
- Create: the migration changes to `users` (add `username` unique, `display_name`, `adult_confirmed_at`, `max_rating`, `role`, `status`), the enums `Rating`/`UserRole`/`UserStatus`, `app/Support/ReservedUsernames.php`, `Http/Controllers/Api/V1/Auth/{RegisterController,LoginController,LogoutController,EmailVerificationController,PasswordController}.php`, `MeController@show`, `UserResource`, `app/Notifications/VerifyEmail.php`
- Test: `tests/Feature/Auth/*Test.php`

**Interfaces:**
- Produces:
  - `enum Rating: string { General='general'; Mature='mature'; Adult='adult'; public function rank(): int; /** @return list<Rating> */ public static function upTo(Rating $max): array }`
  - `User::effectiveMaxRating(): Rating`
  - `UserResource` fields: `username, display_name, avatar_url|null, banner_url|null, created_at`. When the user is the viewer, it adds `email, email_verified, adult_confirmed, max_rating, role`.
  - Routes: `POST /auth/register {username, display_name, email, password, password_confirmation}`, `POST /auth/login {login (username or email), password, remember?}`, `POST /auth/logout`, `POST /auth/email/verify {token}`, `POST /auth/email/resend`, `POST /auth/password/forgot {email}`, `POST /auth/password/reset {token, email, password, password_confirmation}`, `GET /me`.
  - Rate limiter names `login`, `register`.
  - The email verification token is `Crypt::encryptString(json_encode(['uid'=>..,'email'=>..,'exp'=>unix+86400]))`. The link is `{ui_url}/verify-email?token=...`. The password reset link is `{ui_url}/reset-password?token=..&email=..`, set via `ResetPassword::createUrlUsing`.
  - Usernames are stored lowercased; display_name keeps the original case.

- [ ] **Step 1: Write the failing tests**
  - `registers, logs in via session, and returns me` (uses `->withHeader('Origin','http://localhost:3000')` and a `GET /sanctum/csrf-cookie` first; asserts `GET /me` returns `username`).
  - `rejects duplicate usernames case-insensitively` (`Foxy` then `foxy` → 422 `fields.username`).
  - `rejects reserved usernames` (dataset of the 12 names in Review Focus #4 → 422).
  - `rejects usernames outside [a-z0-9_-]{3,30}` (`ab`, `a b`, 31 chars, `fox!`).
  - `logs in by username or email`; `rejects bad password with validation_failed on login`.
  - `throttles login after 5 attempts per minute` (6th → 429 `rate_limited`).
  - `throttles register after 3 per hour per IP`.
  - `sends a verification email on register containing a ui_url link` (Notification fake).
  - `verifies email with a valid token`; `rejects an expired token` (travel 25 h); `rejects a token for a changed email`.
  - `resets password via emailed token` (Notification fake → extract token → reset → login with new password).
  - `GET /me is 401 when logged out`.
  - `new users default to max_rating general and role user`.

- [ ] **Step 2: Run** `php artisan test tests/Feature/Auth` — Expected: FAIL.

- [ ] **Step 3: Implement** the migration, enums, the `User` casts and `effectiveMaxRating()`, the controllers (using `Auth::guard('web')` session login with `$request->session()->regenerate()`), the limiters in `AppServiceProvider::boot` (`login`: `Limit::perMinute(5)->by($ip.'|'.strtolower($login))`; `register`: `Limit::perHour(3)->by($ip)`), and `config/cors.php` with `supports_credentials=true` and allowed origin `ui_url`.

- [ ] **Step 4: Run** the tests — Expected: PASS.

- [ ] **Step 5: Commit** `feat: accounts, session auth, email verification, password reset`.

---

### Task 4: Account settings, 18+ confirmation, profiles, Markdown

**Files:**
- Create: the `profiles` migration and model, `app/Support/Markdown.php`, `ProfileController`, `UserController@show`, `ProfileResource`, FormRequests
- Modify: `MeController` (update, password, settings, adultConfirmation). On register, create an empty profile (Task 3's RegisterController).
- Test: `tests/Feature/Account/*Test.php`, `tests/Unit/MarkdownTest.php`

**Interfaces:**
- Produces:
  - `Markdown::toSafeHtml(?string $md): string`
  - Routes: `PATCH /me {display_name?, email?}` (an email change clears `email_verified_at` and resends verification), `PATCH /me/password {current_password, password, password_confirmation}`, `PATCH /me/settings {max_rating}`, `POST /me/adult-confirmation {confirm: true}`, `PATCH /me/profile {bio?, links?, species?, pronouns?, pinned_submission_ids?}`, `GET /users/{username}` → `{ user: UserResource, profile: ProfileResource }`
  - `ProfileResource`: `bio, bio_html, links[{label,url}], species, pronouns, pinned: SubmissionResource[]`. `pinned` returns `[]` in this task; Task 6 fills it in through `listedFor`.

- [ ] **Step 1: Write the failing tests**
  - `adult confirmation requires verified email` (unverified → 403 `forbidden`; verified → 200, `adult_confirmed` true).
  - `max_rating above general requires adult confirmation` (422 `fields.max_rating`; 200 after confirming).
  - `effective max rating is general for guests and unconfirmed users` (unit on `User`).
  - `updates profile with links limited to 10 and urls only http(s)`.
  - `changing email clears verification and resends`.
  - `password change requires current password`.
  - `GET /users/{username} is case-insensitive and 404s for unknown users`.
  - `Markdown strips script tags, raw html, and javascript: links` (Review Focus #5: input `"<script>x</script>[a](javascript:alert(1))<img src=x onerror=y>"` → output contains none of `<script`, `javascript:`, `onerror`).
  - `bio_html is sanitised in GET /users/{username}`.

- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.

- [ ] **Step 5: Commit** `feat: account settings, adult confirmation, profiles`.

---

### Task 5: Object storage abstraction and upload sessions

**Files:**
- Create: `app/Storage/{ObjectStorage,S3ObjectStorage,FakeObjectStorage}.php`, the `upload_sessions` migration and model, the enums `UploadKind`/`UploadStatus`, `app/Uploads/{UploadLimits,UploadQuota}.php`, `UploadController`, `UploadSessionResource`
- Modify: `AppServiceProvider` (bind `ObjectStorage`; the `uploads` limiter `Limit::perHour(30)->by($user->id)`), `tests/Pest.php` (bind `FakeObjectStorage` in every test)
- Test: `tests/Feature/Uploads/*Test.php`, `tests/Unit/Storage/FakeObjectStorageTest.php`

**Interfaces:**
- Produces:
  ```php
  interface ObjectStorage {
      public function presignPut(string $path, string $mime, int $ttl): string;
      public function createMultipart(string $path, string $mime): string;              // returns uploadId
      public function presignPart(string $path, string $uploadId, int $partNumber, int $ttl): string;
      /** @param list<array{part_number:int, etag:string}> $parts */
      public function completeMultipart(string $path, string $uploadId, array $parts): void;
      public function abortMultipart(string $path, string $uploadId): void;
      public function exists(string $path): bool;
      public function size(string $path): ?int;
      public function downloadToTemp(string $path): string;                              // local temp file path
      public function putFile(string $path, string $localPath, string $mime): void;
      public function delete(string $path): void;
      public function deletePrefix(string $prefix): void;
      public function publicUrl(string $path): string;                                    // cdn_url . '/' . $path
      public function temporaryUrl(string $path, int $ttl): string;
  }
  ```
  - `FakeObjectStorage` stores files under `storage/framework/testing/objects`. Presigned URLs are `fake://put/{path}`. The test helper `FakeObjectStorage::simulateUpload(string $path, string $localFixture)` places a file as though the browser had uploaded it.
  - `enum UploadKind: string { Image, Audio, Video, Document, Cover, Avatar, Banner }` (lowercase values), with `maxBytes(): int` from config and `allowedMimes(): list<string>`.
  - `enum UploadStatus: string { Pending, Uploaded, Consumed, Expired }`.
  - Routes:
    - `POST /uploads {kind, filename, mime, size}` → `201 {id, kind, status, multipart: bool, url?: string, part_size?: int, part_count?: int, expires_at}`
    - `POST /uploads/{id}/parts {part_numbers: int[]}` → `{urls: {"1": "..."}}`
    - `POST /uploads/{id}/complete {parts?: [{part_number, etag}]}` → `{id, status: "uploaded"}`
  - `UploadSession::consume(): void` sets `consumed`. Callers do the locking.
  - Staging path: `staging/{uuid}/{sanitised filename}`.
  - `UploadQuota::assertCanUpload(User $u, int $bytes): void` throws `DomainError('quota_exceeded', ...)`.

- [ ] **Step 1: Write the failing tests**
  - `creates a single-PUT session under 100 MB` (multipart false, url present).
  - `creates a multipart session above 100 MB with 50 MB parts` (size 250 MB → part_count 5).
  - `rejects size over the kind's limit` (image 50 MB + 1 → 422 `fields.size`).
  - `rejects a declared mime not allowed for the kind` (image + `application/pdf` → 422).
  - `requires a verified email to upload` (403).
  - `enforces daily quota by count and bytes` (101st → `quota_exceeded`; bytes over 10 GB → `quota_exceeded`).
  - `throttles to 30 uploads per hour` (31st → 429).
  - `complete fails with upload_not_complete when the object is missing`.
  - `complete fails when the stored size differs from the declared size`.
  - `complete marks uploaded when the object exists with the declared size`.
  - `cannot touch another user's upload session` (404).

- [ ] **Step 2: Run** — FAIL. **Step 3: Implement** (`S3ObjectStorage` uses `Storage::disk('s3')->getClient()` for presigning and multipart; it gets no automated test, but Task 15 checks it by hand against MinIO). **Step 4: Run** — PASS.

- [ ] **Step 5: Commit** `feat: object storage abstraction and upload sessions`.

---

### Task 6: Submissions core: model, visibility scopes, create/show/edit/delete

**Files:**
- Create: the migrations for `submissions` and `media_assets`; the enums `SubmissionType`/`SubmissionStatus`/`Visibility`/`AssetRole`; `app/Support/PublicId.php`, `app/Models/Concerns/HasPublicId.php`, `Submission`, `MediaAsset`, `app/Support/ViewCounter.php`, `SubmissionController`, `SubmissionResource`, `MediaAssetResource`, `SubmissionPolicy`, FormRequests
- Modify: `ProfileResource` (`pinned` = `pinned_submission_ids` resolved through `listedFor`, order kept); profile update validates that pinned ids are the user's own submissions
- Test: `tests/Feature/Submissions/*Test.php`, `tests/Unit/PublicIdTest.php`

**Interfaces:**
- Consumes: `UploadSession`, `UploadKind`, `ObjectStorage`, `Rating::upTo`, `User::effectiveMaxRating`.
- Produces:
  - `PublicId::generate(): string` (10 chars `[a-z0-9]`, `random_int`). The `HasPublicId` trait fills it on `creating` (retrying on collision) and sets `getRouteKeyName(): 'public_id'`.
  - `Submission::scopeVisibleTo(Builder $q, ?User $viewer)`: the owner sees everything of theirs except force-deleted. Everyone else sees only `status=published`, `rating ∈ Rating::upTo(viewer effective)`, `published_at not null`.
  - `Submission::scopeListedFor(Builder $q, ?User $viewer)` = `visibleTo` minus the owner exception, plus `visibility=public`. **All listings must use `listedFor`; all single lookups use `visibleTo`.**
  - `Submission::assets(): MorphMany`, `Submission::asset(AssetRole $role): ?MediaAsset`.
  - `SubmissionResource`: `id (public_id), type, title, description, description_html, body?, body_html? (writing only, on the show endpoint only), rating, status, visibility, tags: string[], views_count, published_at, created_at, user: UserResource, folders: [{id,name,kind}], media: {thumb_sm?, thumb_md?, thumb_lg?, preview?, original?, transcode?, cover?, waveform?, document?}`. Each media entry is `{url, mime, width?, height?, duration_ms?, size_bytes}`. The URLs come from `MediaUrls` (Task 9); until then this resource returns `media: {}`.
  - Routes: `POST /submissions`, `GET /submissions/{publicId}`, `PATCH /submissions/{publicId} {title?, description?, body?, rating?, visibility?, tags?, folder?}`, `DELETE /submissions/{publicId}`.
  - `POST /submissions {type, upload_session_id? (required unless writing), cover_upload_session_id?, title, description?, body? (required if writing), rating, tags?: string[], folder: "gallery"|"scraps"|folderPublicId, visibility}`. In one DB transaction with `lockForUpdate` on the upload session(s) it: checks that the session status is `uploaded` and its kind matches the type (`upload_already_used` / `upload_not_complete` otherwise), consumes the session, creates the submission with `processing`, attaches folder and tags, and dispatches `ProcessSubmission` **after commit**. The `ProcessSubmission` class is created as a stub in this task, with an empty `handle()`.
  - `ViewCounter::record(Submission $s, ?User $viewer, string $ip, string $ua): void`: Redis `SET NX` on key `views:{id}:{viewerKey}:{Y-m-d}` with TTL 86400. Owner views are not counted.

- [ ] **Step 1: Write the failing tests**
  - `creates an image submission from an uploaded session and dispatches processing` (Queue fake; status `processing`; session `consumed`).
  - `rejects a pending (not completed) session with upload_not_complete and creates nothing` (Review Focus #1).
  - `rejects reusing a consumed session with upload_already_used` (Review Focus #1).
  - `rejects a session whose kind mismatches the type` (audio session + type image → 422).
  - `writing requires body; upload session optional`.
  - `owner can view own processing submission; others get 404`.
  - `guest gets 404 for mature and adult published submissions` (Rating dataset).
  - `confirmed adult user with max_rating mature sees mature but 404s adult`.
  - `unlisted submission is viewable by link`.
  - `only the owner can PATCH and DELETE` (others → 404 when not visible to them, 403 when visible).
  - `DELETE soft-deletes and then 404s for everyone`.
  - `views increment once per viewer per day and not for the owner`.
  - `PublicId generates 10 lowercase alphanumerics`.

- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.

- [ ] **Step 5: Commit** `feat: submissions with visibility scopes`.

---

### Task 7: Media processing pipeline

**Files:**
- Create: `app/Media/{FileSignature,Ffmpeg,AssetDraft}.php`, `app/Media/Processors/{MediaProcessor,ImageProcessor,AudioProcessor,VideoProcessor,WritingProcessor}.php`, `tests/Fixtures/media/*`
- Modify: `app/Jobs/ProcessSubmission.php` (implement); `SubmissionController` (add `POST /submissions/{publicId}/retry {upload_session_id}`)
- Test: `tests/Feature/Processing/*Test.php`, `tests/Unit/Media/FileSignatureTest.php`

**Interfaces:**
- Consumes: `ObjectStorage`, `Submission`, `MediaAsset`, `AssetRole`, `UploadKind::allowedMimes()`.
- Produces:
  - `FileSignature::detect(string $localPath): string` (finfo mime). `FileSignature::matches(string $localPath, UploadKind $kind): bool`. For audio, accept `audio/mp4`, `audio/x-m4a` and `video/mp4` for M4A when `Ffmpeg::probe` finds an audio stream and no video stream. For documents, accept `application/zip` only when the file contains `word/document.xml` (DOCX) or `content.xml` (ODT).
  - `Ffmpeg::probe(string $path): array{duration_ms:int, has_video:bool, has_audio:bool, width:?int, height:?int}` and `Ffmpeg::run(list<string> $args, int $timeout): void`. Both throw `App\Media\MediaProcessingFailed`.
  - `final readonly class AssetDraft { __construct(public AssetRole $role, public string $localPath, public string $mime, public ?int $width = null, public ?int $height = null, public ?int $durationMs = null) }`
  - `interface MediaProcessor { /** @return list<AssetDraft> */ public function process(Submission $s, string $originalPath, ?string $coverPath): array; }`
  - Thumbnail rule: for images, thumbnails come from the image. For audio, video and writing they come from the cover (uploaded, or the video poster frame). With no cover there are no thumbnails, and the UI shows a placeholder for the type.
  - `ProcessSubmission`: `$tries = 3`, `$backoff = [10, 60, 300]`, queue `video` for video and `media` otherwise. On success it uploads each draft to `media/{public_id}/{role}.{ext}`, moves the original to `media/{public_id}/original.{ext}`, creates the `media_assets` rows, sets `published` and `published_at`, and calls `searchable()`. `failed()` sets status `failed` and deletes the staging objects. The original is EXIF-stripped before it is stored.

- [ ] **Step 1: Generate the fixtures** (commit them; each must be under 200 KB):

```bash
cd tests/Fixtures/media
ffmpeg -f lavfi -i testsrc=size=640x480:duration=1 -frames:v 1 image.png
magick image.png image.jpg
cp image.jpg gps.jpg && exiftool -overwrite_original -GPSLatitude=51.5 -GPSLatitudeRef=N -GPSLongitude=0.12 -GPSLongitudeRef=W gps.jpg   # needs: apk add exiftool
ffmpeg -f lavfi -i testsrc=size=64x64:rate=5:duration=1 anim.gif
ffmpeg -f lavfi -i sine=frequency=440:duration=2 audio.mp3
ffmpeg -f lavfi -i testsrc=size=320x240:rate=10:duration=2 -f lavfi -i sine=duration=2 -shortest video.mp4
head -c 4000 video.mp4 > truncated.mp4
( head -c 2000 audio.mp3; head -c 20000 /dev/urandom ) > junk.mp3
printf '<?php echo 1;' > fake.png
```

- [ ] **Step 2: Write the failing tests**
  - `image: produces thumb_sm/md/lg and preview as webp with longest edges 200/400/800/≤2000`.
  - `image: stored original and preview have no EXIF GPS` (Imagick `getImageProperties('exif:GPS*')` is empty).
  - `image: animated gif preview keeps multiple frames`.
  - `audio: produces m4a transcode with duration and waveform json of 1000 peaks in 0..1`.
  - `audio with cover: thumbnails come from cover`.
  - `video: produces mp4 transcode ≤1080p and poster cover plus thumbnails`.
  - `writing: publishes with document asset when provided`.
  - `rejects fake.png (php content) as type mismatch → failed`.
  - `truncated.mp4 and junk.mp3 end in failed, staging deleted, never stuck in processing` (Review Focus #3; call the job's `handle` and, on exception, `failed()`, as the queue worker would after retries).
  - `successful processing publishes, sets published_at, and removes staging`.
  - `retry: only failed submissions, with a new uploaded session, go back to processing`.

- [ ] **Step 3: Run** — FAIL. **Step 4: Implement** (`ImageProcessor` uses Intervention Image v3 with the Imagick driver, `strip: true`. Video `-vf "scale='min(1920,iw)':'min(1080,ih)':force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2" -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 160k -movflags +faststart`. Waveform: decode to mono 8 kHz `s16le` and take the max abs per bucket over 1000 buckets, normalised.) **Step 5: Run** — PASS.

- [ ] **Step 6: Commit** `feat: media processing pipeline`.

---

### Task 8: Tags

**Files:**
- Create: the migrations for `tags`, `submission_tag` and `tag_aliases`; `Tag`; `app/Support/TagNormalizer.php`; `TagController`; `TagResource`
- Modify: `SubmissionController` store/update (sync tags, maintain `usage_count` only for published+public submissions, recounted by `Tag::recount(array $ids)`)
- Test: `tests/Unit/TagNormalizerTest.php`, `tests/Feature/TagsTest.php`

**Interfaces:**
- Produces:
  - `TagNormalizer::normalize(array $raw): list<string>`: lowercase, trim, spaces→`_`, strip characters outside `[a-z0-9_]`, collapse repeated `_`, trim `_`, drop empty, truncate to 50, de-duplicate in order. Aliases are resolved through `tag_aliases`.
  - The request validator rejects more than 60 tags *after* normalisation (`fields.tags`).
  - Routes: `GET /tags/autocomplete?q=` (prefix match, top 10 by `usage_count`, min 1 char), `GET /tags/{slug}` → `{tag: {slug, usage_count}, submissions: paginated listedFor}`.

- [ ] **Step 1: Write the failing tests**
  - `normalize collapses Wolf, " wolf ", wolf! to wolf` (Review Focus #2).
  - `normalize drops "!!!" and "   "`; `turns "red fox" into red_fox`; `truncates to 50`.
  - `more than 60 distinct tags is 422` (Review Focus #2).
  - `autocomplete orders by usage_count`.
  - `tag page lists only submissions listed for the viewer`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: tags with normalisation and autocomplete`.

---

### Task 9: Media URLs (CDN vs signed adult URLs)

**Files:**
- Create: `app/Media/MediaUrls.php`
- Modify: `SubmissionResource`, `MediaAssetResource`, `UserResource` (avatar/banner)
- Test: `tests/Feature/MediaUrlsTest.php`

**Interfaces:**
- Produces: `MediaUrls::for(MediaAsset $asset, Submission $s): string`. For an `adult` submission it returns `ObjectStorage::temporaryUrl($path, config('openpaws.signed_media_ttl'))`; otherwise `publicUrl($path)`. Resources only reach this after `visibleTo` has passed, so no extra authorisation happens here.
- `original` is included in `media` for images and documents only. Audio and video expose `transcode`, not the raw upload.

- [ ] **Step 1: Write the failing tests**
  - `general submission media urls are public cdn urls`.
  - `adult submission media urls are temporary and expire in 600s` (`FakeObjectStorage::temporaryUrl` encodes the expiry; assert the value).
  - `audio/video expose transcode but not original`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: media url resolution with signed adult urls`.

---

### Task 10: Folders and user galleries

**Files:**
- Create: the migrations for `folders` and `folder_submission`; enum `FolderKind`; `Folder` (HasPublicId); `FolderController`; `FolderResource`; `app/Actions/CreateDefaultFolders.php`
- Modify: `RegisterController` (call `CreateDefaultFolders`), `UserController` (`submissions`, `folders`), `SubmissionController` (the `folder` param on store/update)
- Test: `tests/Feature/FoldersTest.php`, `tests/Feature/UserGalleryTest.php`

**Interfaces:**
- Produces:
  - `CreateDefaultFolders::handle(User $u): void` creates `gallery` (position 0) and `scraps` (position 1).
  - Routes: `GET /users/{username}/folders` → `FolderResource[] {id, name, description, kind, position, count}` (count = listed for viewer). `GET /users/{username}/submissions?folder=gallery|scraps|{publicId}` (default `gallery`; ordered by `folder_submission.position` then `published_at desc`; uses `listedFor`). `POST /folders {name, description?}`, `PATCH /folders/{publicId} {name?, description?}`, `DELETE /folders/{publicId}` (moves its submissions into gallery if they are in no other gallery/scraps folder), `PUT /folders/{publicId}/order {submission_ids: string[]}` (public ids; must equal the folder's current set).
  - Rule: a submission is in exactly one of gallery/scraps, plus any number of custom folders. Store/update `folder` accepts `gallery`, `scraps` or a custom folder public id (a custom folder places the submission in the custom folder **and** in gallery).

- [ ] **Step 1: Write the failing tests**
  - `registration creates gallery and scraps folders`.
  - `cannot delete or rename gallery/scraps` (422 `invalid_state`).
  - `deleting a custom folder keeps submissions in gallery`.
  - `moving a submission from gallery to scraps removes it from gallery`.
  - `reorder requires exactly the folder's current set`.
  - `user gallery listing hides unlisted, processing, and over-rating items from others`.
  - `cannot add a submission to another user's folder` (404).
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: folders, gallery and scraps`.

---

### Task 11: Avatars and banners

**Files:**
- Create: `app/Jobs/ProcessProfileImage.php`
- Modify: `MeController` (`PUT /me/avatar {upload_session_id}`, `PUT /me/banner {upload_session_id}`), `UserResource`
- Test: `tests/Feature/Account/ProfileImagesTest.php`

**Interfaces:**
- Consumes: `UploadSession` (kind `avatar` or `banner`), `ImageProcessor` resize helpers, `ObjectStorage`.
- Produces: avatar = 256×256 centre crop WebP at `users/{id}/avatar-{hash}.webp`; banner = 1600×400 cover crop WebP at `users/{id}/banner-{hash}.webp`. The previous file is deleted. Both are public CDN URLs.

- [ ] **Step 1: Write the failing tests:** `sets avatar 256x256 webp`, `sets banner 1600x400`, `rejects session of wrong kind`, `replacing avatar deletes the old object`, `rejects non-image content`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: avatar and banner uploads`.

---

### Task 12: Browse and search

**Files:**
- Create: `BrowseController`, `SearchController`, `config/scout.php` index settings
- Modify: `Submission` (`Searchable`: `toSearchableArray`, `shouldBeSearchable`), `AppServiceProvider` (`search` limiter `Limit::perMinute(60)->by($ip)`)
- Test: `tests/Feature/BrowseTest.php`, `tests/Feature/SearchTest.php`

**Interfaces:**
- Produces:
  - `toSearchableArray()`: `id, public_id, title, description, tags (slugs), type, rating, user_id, username, published_at (unix), views_count`. `shouldBeSearchable()`: published && public && not trashed. Meilisearch `filterableAttributes: [rating, type, tags, user_id]`, `sortableAttributes: [published_at, views_count]`. `php artisan scout:sync-index-settings` is part of setup.
  - `GET /browse?type=&sort=new|popular&period=day|week|month|all` (defaults `new`, period `week` for popular; uses `listedFor`; popular = `views_count desc, id desc` within the period by `published_at`).
  - `GET /search?q=&tags=a,b&exclude_tags=c&type=&sort=relevance|new|popular`: Scout `whereIn('rating', Rating::upTo(effective))`, `whereIn('type',...)`, a `where('tags', $t)` for each required tag, and `whereNotIn('tags', $excluded)`. The results are then re-loaded through `listedFor` as a second guard. Pagination: Scout `paginate` mapped to the cursor shape with an opaque page cursor.

- [ ] **Step 1: Write the failing tests**
  - `browse new orders by published_at desc and filters type`.
  - `browse popular respects period`.
  - `search filters by rating for guests (no mature/adult)`.
  - `search supports required and excluded tags`.
  - `search excludes unlisted and processing`.
  - `search throttles at 60/min`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: browse and search`.

---

### Task 13: Rating-leak suite

**Files:**
- Create: `tests/Feature/RatingLeakTest.php`, `tests/Support/SeedsRatedContent.php`

**Interfaces:**
- Consumes: every listing and lookup route above.

- [ ] **Step 1: Write the tests.** Seed one published public submission per rating, owned by user `artist`, tagged `leak`, in a custom folder, pinned on the profile. Then, for viewers `[guest, unconfirmed user with max_rating forced to adult in DB, confirmed user max general, confirmed mature, confirmed adult]`, assert the exact set of ratings returned by: `GET /submissions/{each}`, `/browse`, `/search?q=`, `/search?tags=leak`, `/tags/leak`, `/users/artist/submissions`, `/users/artist/submissions?folder={custom}`, `/users/artist/folders` (counts), and `/users/artist` (pinned). Expected sets: guest/unconfirmed/general → `[general]`; mature → `[general, mature]`; adult → all three.
- [ ] **Step 2: Run** `php artisan test tests/Feature/RatingLeakTest.php` — Expected: PASS. Any failure is a bug in the owning task's scope usage: fix it there, not in the test.
- [ ] **Step 3: Commit** `test: rating leak suite across all endpoints`.

---

### Task 14: Scheduled cleanup

**Files:**
- Create: `app/Console/Commands/{ExpireUploads,PurgeDeletedSubmissions}.php`
- Modify: `routes/console.php` (schedule both `daily()`)
- Test: `tests/Feature/CleanupTest.php`

**Interfaces:**
- Produces: `uploads:expire` marks `pending`/`uploaded` sessions older than 24 h as `expired`, aborts their multipart uploads and deletes their staging objects. `submissions:purge-deleted` force-deletes submissions trashed more than 30 days ago, deletes `media/{public_id}/` and removes them from search.

- [ ] **Step 1: Write the failing tests:** `expires stale sessions and deletes staging`, `keeps fresh sessions`, `purges only submissions trashed over 30 days`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: scheduled cleanup of uploads and deleted submissions`.

---

### Task 15: End-to-end smoke check against real services

**Files:**
- Create: `docs/dev-running.md` (how to run `php artisan serve --port=8000`, `queue:work redis --queue=media,video,default`, `schedule:work`, `scout:sync-index-settings`)

- [ ] **Step 1:** Start the API and a worker. With curl and a cookie jar: get the CSRF cookie, register, mark verified via tinker, create an image upload session, `curl -X PUT --upload-file tests/Fixtures/media/image.png` to the presigned MinIO URL, complete, create the submission, and poll `GET /submissions/{id}` until `published`.
- [ ] **Step 2:** Verify that `media.thumb_md.url` downloads from MinIO, that `GET /search?q=<title>` finds it, and that an adult submission's media URL is signed and stops working after expiry (temporarily set `OPENPAWS_SIGNED_MEDIA_TTL=5`).
- [ ] **Step 3:** Repeat the upload with a 120 MB generated video (`ffmpeg -f lavfi -i testsrc=size=1920x1080:rate=30:duration=60 -b:v 16M big.mp4`) through the multipart path.
- [ ] **Step 4:** Run the full suite: `php artisan test` — Expected: all PASS.
- [ ] **Step 5: Commit** `docs: running the API locally`.
