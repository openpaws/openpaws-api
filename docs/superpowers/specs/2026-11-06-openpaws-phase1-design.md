# OpenPaws — Phase 1 Design: Foundation + Core Gallery

Date: 2026-11-06
Status: Draft for review
Repos: `openpaws/openpaws-api` (this repo), `openpaws/openpaws-ui`

## 1. Purpose

OpenPaws is a community art gallery for furries to publish creative work, similar to FurAffinity and SoFurry. The goal is a **public launch** to the furry community.

The launch is built in four phases. Each phase gets its own spec, plan and build cycle, and all four ship before launch:

1. **Foundation + core gallery** (this spec)
2. **Social**: favourites, threaded comments, watches, notification inbox, journals
3. **Notes + moderation**: private notes (DMs), blocks, reports, admin queue, re-rating, takedowns, bans
4. **Launch hardening**: production hosting, rate-limit tuning, backups, legal pages (ToS, privacy, DMCA), load testing

### Decisions made during brainstorming

| Topic | Decision |
|---|---|
| Goal | Public launch |
| Media types | Images, writing, audio, video/animation, all in Phase 1 |
| Content ratings | 3 tiers: General / Mature / Adult; 18+ self-confirmation to view Mature/Adult |
| Social features at launch | Favourites, comments, watches + inbox, journals, private notes |
| Stack | Laravel API + Nuxt (SSR) UI |
| Media storage | S3-compatible bucket (e.g. Backblaze B2 / Cloudflare R2) behind a CDN; MinIO in development |

### Assumptions (confirm or correct during review)

- Moderation is required before launch; it's built in Phase 3.
- Age gating uses self-confirmation. Third-party age verification is out of scope, but the design must not block adding it later.
- Search uses Meilisearch via Laravel Scout.

### Phase 1 success criteria

- A visitor can sign up, verify their email, confirm 18+, and set their rating filter.
- A user can upload an image, a piece of writing, audio or video. The work is processed and published, and appears in their gallery, in browse, and in search.
- Guests and General-only users **never** receive Mature/Adult content from any endpoint.
- Submission links unfurl correctly on Discord and Telegram (Open Graph), except Adult content, which shows no preview image.
- Every endpoint has feature tests; key UI flows have end-to-end tests.

### Out of scope for Phase 1

All Phase 2–4 features. Also out of scope: commissions, payments, groups, and third-party age verification.

## 2. Architecture

```
Browser ──► openpaws-ui (Nuxt SSR, Node) ──► openpaws-api (Laravel, /api/v1)
   │                                             │      │        │
   │  presigned PUT                              │   MariaDB   Redis (cache, queues, rate limits)
   └──────────────► S3 bucket ◄── queue workers ─┘      │
                      │        (thumbs, ffmpeg)     Meilisearch
                     CDN ──► browser (media)
```

- **openpaws-api**: Laravel on PHP 8.5. It's a JSON REST API under `/api/v1`. Auth uses Sanctum SPA cookie sessions with CSRF protection. Data lives in MariaDB, with Redis for cache, queues and rate limiting. Queue workers process media, and search runs through Scout + Meilisearch.
- **openpaws-ui**: Nuxt (Vue 3, TypeScript), server-rendered for SEO and link unfurls. It talks only to the API and contains no business rules, including rating rules.
- **Media**: browsers upload straight to the bucket using presigned URLs, so file bytes never go through PHP. Queue workers process staged files into final assets.

## 3. Data model

Integer primary keys stay internal. Public-facing resources use a short random `public_id` (8–10 characters from a URL-safe alphabet, unique), e.g. `/view/k3j9xq2a`, so they can't be found by counting up IDs.

### users
- `id`, `username` (unique, 3–30 chars `[a-z0-9_-]`, case-insensitive), `display_name`, `email` (unique), `password`, `email_verified_at`
- `adult_confirmed_at` (nullable timestamp: when the user confirmed they are 18+)
- `max_rating` enum `general|mature|adult`, default `general`. It can only be above `general` when `adult_confirmed_at` is set.
- `role` enum `user|moderator|admin`, default `user`
- `status` enum `active|suspended|banned`, default `active`
- timestamps

### profiles
- `user_id` (PK/FK), `bio` (Markdown), `links` (JSON array of `{label, url}`, max 10), `species`, `pronouns`, `pinned_submission_ids` (JSON, max 6)
- Avatar and banner are `media_assets` rows linked to the user.

### submissions
- `id`, `public_id`, `user_id`
- `type` enum `image|writing|audio|video`
- `title` (1–150 chars), `description` (Markdown, ≤ 20k chars)
- `body` (Markdown, writing only, ≤ 500k chars)
- `rating` enum `general|mature|adult`
- `status` enum `processing|published|failed|hidden|removed`
- `visibility` enum `public|unlisted`: unlisted works can be opened by link but are left out of browse, search and gallery listings.
- `published_at`, `views_count`, timestamps, soft deletes

### media_assets
- `id`, `owner_type`/`owner_id` (polymorphic: submission or user)
- `role` enum `original|thumb_sm|thumb_md|thumb_lg|preview|transcode|cover|waveform|avatar|banner|document`
- `disk`, `path`, `mime`, `size_bytes`, `width`, `height`, `duration_ms`, `checksum_sha256`

### folders / folder_submission
- `folders`: `id`, `public_id`, `user_id`, `name`, `description`, `position`, and `kind` enum `gallery|scraps|custom`. Every user automatically gets one `gallery` folder and one `scraps` folder.
- `folder_submission`: `folder_id`, `submission_id`, `position`. A submission can be in several custom folders, but in exactly one of gallery or scraps.

### tags / submission_tag / tag_aliases
- `tags`: `id`, `slug` (unique, lowercase, `[a-z0-9_]`, ≤ 50 chars), `usage_count`
- `submission_tag`: pivot. A submission can have at most 60 tags.
- `tag_aliases`: `alias_slug` → `tag_id`. The table exists now; managing aliases is a Phase 3 moderation tool.

### upload_sessions
- `id` (UUID), `user_id`, `media_type`, `filename`, `declared_mime`, `size_bytes`, `staging_path`, `multipart_upload_id` (nullable), `status` enum `pending|uploaded|consumed|expired`, `expires_at`

Phase 2/3 tables (faves, comments, watches, notifications, journals, notes, blocks, reports) reference only `users` and `submissions`. Phase 1 tables need no changes for them.

## 4. Upload and processing

### Limits (all in `config/openpaws.php`)

| Type | Accepted (checked by file signature, i.e. the file's real bytes) | Max size |
|---|---|---|
| Image | PNG, JPEG, GIF (incl. animated), WebP | 50 MB |
| Writing | Markdown body, optional document: TXT, DOCX, ODT, PDF | 10 MB document |
| Audio | MP3, WAV, FLAC, OGG, M4A | 200 MB |
| Video | MP4, WebM, MOV | 2 GB |
| Cover image (audio/video/writing) | as Image | 10 MB |

Each user also has a daily upload quota, set in config.

### Flow

1. `POST /api/v1/uploads {media_type, filename, mime, size}` validates type, size and quota, creates an `upload_session`, and returns a presigned PUT URL under `staging/{uuid}`. Files over 100 MB use S3 multipart upload: the API returns presigned part URLs and has a `complete` endpoint.
2. The browser uploads to the bucket directly and shows progress.
3. `POST /api/v1/submissions {upload_session_id, title, description, rating, tags[], folder, visibility, body?, cover_upload_session_id?}` creates the submission with status `processing` and queues `ProcessSubmission`.
4. **ProcessSubmission** (on the `media` queue; video uses the `video` queue):
   - Reads the file signature (the leading bytes that identify the type) and rejects files whose real type doesn't match.
   - **Image**: strips EXIF and GPS data, then generates WebP `thumb_sm` (200px), `thumb_md` (400px), `thumb_lg` (800px) and `preview` (max 2000px long edge). Animated GIF/WebP keep their animation in `preview`.
   - **Audio**: transcodes to AAC/M4A at 256 kbps (`transcode`) and generates waveform JSON.
   - **Video**: transcodes to H.264/AAC MP4 at up to 1080p (`transcode`), and grabs a poster frame at 10% of the running time as the `cover` unless the user uploaded one.
   - **Writing**: no file processing is needed for the body, which is sanitised when rendered. An uploaded document is stored as `document`.
   - Moves the original from `staging/` to `media/{public_id}/`, writes the `media_assets` rows, sets `status=published` and `published_at`, and indexes the submission in Meilisearch.
5. **Failure**: a job is retried 3 times with backoff. After that, the submission is set to `failed`, the staged file is deleted, and the user sees "processing failed — try again". A scheduled job runs daily, expires `pending` upload sessions older than 24 hours and deletes their staged files.

### Serving media
- Assets for General and Mature submissions are served through the CDN with long cache lifetimes. Paths contain the `public_id` plus a hash, so they can't be guessed.
- **Adult** assets (all roles) are served with short-lived (10-minute) signed URLs. These are issued only to a user whose `max_rating` is `adult`.
- Hidden or removed submissions get no URLs at all.

## 5. Ratings filter

- Each viewer has an **effective max rating**: `general` for guests and for users without `adult_confirmed_at`, and the user's `max_rating` otherwise.
- The filter is enforced in the API **only**, through a single `Submission::scopeVisibleTo(?User)` query scope (rating + status `published` + visibility rules). The same rule applies to Meilisearch via a `rating` filter attribute. Every listing and lookup path must go through this scope or the search filter.
- **Direct lookups**: if a viewer asks for a submission above their effective rating, the API returns **404**. The response doesn't say that the submission exists.
- **Listings**: submissions above the viewer's rating are left out of the results entirely. The UI never sees them.
- **Confirmation**: `POST /api/v1/me/adult-confirmation` records `adult_confirmed_at`. The user must be logged in and have a verified email. The UI shows a clear 18+ statement.
- **Editing**: the owner can change the rating of their own submission. Moderator re-rating comes in Phase 3.

## 6. API surface (`/api/v1`)

All responses are JSON via Laravel API Resources. Lists use cursor pagination (`?cursor=`, default 48 items, max 96).

**Auth**: `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `POST /auth/email/verify`, `POST /auth/email/resend`, `POST /auth/password/forgot`, `POST /auth/password/reset`, `GET /me`

**Account**: `PATCH /me` (display_name, email), `PATCH /me/password`, `PATCH /me/settings` (max_rating), `POST /me/adult-confirmation`, `PATCH /me/profile`, `PUT /me/avatar`, `PUT /me/banner` (both use an upload session)

**Users**: `GET /users/{username}`, `GET /users/{username}/submissions?folder=gallery|scraps|{folderPublicId}`, `GET /users/{username}/folders`

**Uploads**: `POST /uploads`, `POST /uploads/{id}/parts` (multipart URLs), `POST /uploads/{id}/complete`

**Submissions**: `POST /submissions`, `GET /submissions/{publicId}` (adds to the view count, deduplicated per viewer per day), `PATCH /submissions/{publicId}`, `DELETE /submissions/{publicId}` (soft delete; assets are purged after 30 days), `POST /submissions/{publicId}/retry` (failed only, with a new upload session)

**Folders**: `POST /folders`, `PATCH /folders/{publicId}`, `DELETE /folders/{publicId}` (its submissions move to gallery), `PUT /folders/{publicId}/order`

**Discovery**: `GET /browse?type=&sort=new|popular&period=day|week|month|all`, `GET /search?q=&tags=&exclude_tags=&type=&sort=`, `GET /tags/autocomplete?q=`, `GET /tags/{slug}`

**Error shape**: `{ "error": { "code": "validation_failed", "message": "...", "fields": { "title": ["..."] } } }` with 422 / 401 / 403 / 404 / 429 / 500.

**Rate limits** (Redis): login 5/min per IP+username; register 3/hour per IP; uploads 30/hour per user; search 60/min per IP.

## 7. UI (openpaws-ui)

Built with the current stable Nuxt (Vue 3, TypeScript), server-rendered. It uses a small typed API client (a `$fetch` wrapper with hand-written TypeScript types that mirror the API Resources), plus Pinia for session state.

**Pages**
- `/`: home and browse (newest/popular, type filter)
- `/search`, `/tag/{slug}`
- `/view/{publicId}`: the submission page, with a viewer for each media type (image zoom/fit, writing reader with font-size control, audio player with waveform, video player), plus title, artist, tags, rating badge and description
- `/user/{username}` (profile), `/user/{username}/gallery`, `/scraps`, `/folder/{publicId}`
- `/upload`: wizard (choose type → upload with progress → details → publish → processing status)
- `/login`, `/register`, `/verify-email`, `/forgot-password`, `/reset-password`
- `/settings`: account, profile, content filter / 18+ confirmation

**Behaviour**
- Open Graph and Twitter card meta on submission and profile pages. Adult submissions have no preview image, only a generic card.
- Light and dark themes. The layout is responsive and usable from 360px width upward. It meets basic accessibility: keyboard navigation, alt text fields on image uploads, enough colour contrast.
- Error pages for 404 and 500. Failed actions show a toast with the API's `message`.

## 8. Error handling

- One error format across the API (section 6). Unexpected exceptions return a generic 500 and are logged with a request ID, which also comes back in an `X-Request-Id` header.
- Content the viewer isn't allowed to see returns 404, never 403 (section 5).
- Processing failures are visible on the submission (`status=failed`) along with a retry action. Worker logs include the submission ID.

## 9. Testing

- **API**: Pest feature tests for every endpoint, run against a MariaDB test database.
  - **Rating-leak suite**: for every listing and lookup endpoint, it seeds General, Mature and Adult content and checks that guests and General-only users never receive anything above their rating.
  - Processing jobs are tested with small real fixture files (real ffmpeg and image library). The S3 disk is faked locally.
  - Policy tests check that only the owner can edit or delete.
- **UI**: Vitest for components and composables. Playwright end-to-end tests cover: sign up → verify → confirm 18+ → upload image → appears in browse and search; and a guest cannot see an Adult submission.
- Development is test-first, one feature at a time.

## 10. Environments

- **Development** (this server: Alpine, PHP 8.5, Node 24, MariaDB, Redis): API via `php artisan serve` plus `php artisan queue:work`; UI via `nuxt dev`; MinIO standing in for S3; Meilisearch as a single binary. ffmpeg and an image library (libvips or Imagick) are required.
- **Production**: decided in Phase 4: PHP-FPM behind a web server, Node SSR process, Supervisor-managed workers, managed bucket + CDN. This server currently has no web server or Docker installed.

## 11. Open questions for later phases

- Whether UK or US regional rules will require age verification for Adult content (Phase 4 legal review).
- Popularity ranking formula. Phase 1 uses views; it switches to faves once Phase 2 adds them.
