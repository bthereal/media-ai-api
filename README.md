# Bryan's adventures with Symfony ai

A Symfony 8 API for managing MP4 video content with AI-powered transcription, summarisation, and semantic search. Designed to back a React admin UI but fully usable as a standalone API. It will require a Jwt based auth/user api for authentication, though this project could be updated to nativley provide this via a standard User entity and lexik jwt for example. This is a deep dive into some of Symfony's new ai features and practical implementations, see https://symfony.com/doc/current/ai/bundles/ai-bundle.html

## What it does

Videos are uploaded in chunks, assembled server-side, then automatically transcribed via OpenAI Whisper. The full transcript is embedded into a postgres vector store using `text-embedding-ada-002`, enabling natural-language search over the video library. An optional on-demand summary (~300 chars) can be generated from the transcript at any time.

```
Upload → Assemble → Transcribe (Whisper) → Embed transcript (pgvector)
                                         ↳ Generate thumbnail (ffmpeg)
                 On demand → Summarise (GPT-4o-mini)
                 On demand → Semantic search (GPT-4o-mini + pgvector)
```

## Key features

- **Chunked MP4 upload** — custom sequential protocol; chunks stored via Flysystem, assembled when all arrive. Duplicate detection via SHA-256 hash prevents re-processing identical files.
- **Automatic transcription** — Whisper STT triggered synchronously after assembly via Symfony Messenger. Transcript stored in Postgres with status polling (`pending → processing → completed | failed`).
- **Transcript embeddings** — full transcript vectorised with `text-embedding-ada-002` (1536 dims) and stored in pgvector. Powers semantic similarity search.
- **On-demand AI summary** — GPT-4o-mini condenses any completed transcript into ≤200 characters via a single API call. Not generated automatically; user-triggered.
- **Semantic video search** — natural-language queries call a GPT-4o-mini agent that uses a `similarity_search` tool backed by pgvector. Returns both a prose answer and a ranked list of matching videos with live titles.
- **Thumbnail generation** — ffmpeg extracts a JPEG frame at 5 s post-assembly.
- **JWT authentication** — tokens issued by an external auth service (Passport). Roles and permissions (`content:read`, `content:create`, `content:update`) are extracted from JWT claims per request.
- **Multi-tenant permission model** — `TenantContext` carries per-request roles and permissions decoded from the JWT. `ROLE_GROUP_ADMIN` bypasses all permission checks.

## Tech stack

| Layer | Choice |
|---|---|
| Framework | Symfony 8.0 / PHP 8.4 |
| Database | PostgreSQL 18 + pgvector extension |
| ORM | Doctrine ORM 3 |
| AI | Symfony AI bundle 0.8 — OpenAI platform, agent, store, vectorizer |
| File storage | League Flysystem 3 (local) |
| Async | Symfony Messenger (sync transport — swappable to Redis/AMQP) |
| Auth | LexikJWT 3 |
| API docs | NelmioApiDoc (OpenAPI 3) |

## API endpoints

| Method | Path | Description |
|---|---|---|
| `POST` | `/api/upload/chunk` | Upload a single chunk (`uploadId`, `chunkIndex`, `totalChunks`, `filename`, `chunk`, optional `title`) |
| `GET` | `/api/content` | Paginated content list (12 per page, `?page=N`) |
| `GET` | `/api/content/{id}` | Fetch content metadata + transcription status |
| `PATCH` | `/api/content/{id}` | Update `title` and/or `summary` |
| `DELETE` | `/api/content/{id}` | Soft-delete (sets `deletedAt`) |
| `POST` | `/api/content/{id}/summarize` | Generate AI summary from transcript |
| `GET` | `/api/content/{id}/stream` | Stream MP4 — supports HTTP Range |
| `GET` | `/api/content/{id}/thumbnail` | Serve JPEG thumbnail (public, cached) |
| `GET` | `/api/transcription/{uploadId}` | Poll transcription status by upload ID |
| `POST` | `/api/content/search` | Semantic search — body: `{ "query": "..." }` |

Full OpenAPI spec available at `/api/doc` (dev only).

## Requirements

- PHP 8.4+
- Docker (for Postgres with pgvector)
- `ffmpeg` on the host (thumbnail generation and audio extraction)
- OpenAI API key

## Setup

```bash
# 1. Install dependencies
composer install

# 2. Start the database
docker compose up -d

# 3. Configure environment
cp .env .env.local
# Set APP_SECRTET, OPENAI_API_KEY, DATABASE_URL and jwt values in .env.local

# 4. Ensure symfony cli is installed and start the dev server
symfony serve

# 5. Run migrations
php bin/console doctrine:migrations:migrate --no-interaction

# 6. Create the pgvector embeddings table
php bin/console ai:store:setup ai.store.postgres.video_transcript_embeds

```

## Console commands

```bash
# Re-embed all completed transcriptions into pgvector (useful after config changes)
php bin/console app:embed-videos

# Generate thumbnails for any videos that are missing a thumbnail
php bin/console app:generate-thumbnails
```

## Database schema

Doctrine manages two tables:

- **`content`** — one row per uploaded video: `id` (UUID), `filename`, `upload_id`, `mime_type`, `file_size`, `duration`, `file_hash`, `title`, `has_thumbnail`, `created_at`, `deleted_at`
- **`video_transcription`** — `id` (UUID), `upload_id`, `filename`, `status`, `transcription` (full text), `summary` (≤200 chars), `error_message`, `created_at`, `completed_at`

The pgvector table `video_transcript_embeds` (`id UUID, metadata JSONB, embedding vector(1536)`) is managed by `ai:store:setup`, not Doctrine migrations. The document ID equals the content UUID, making upserts idempotent.

## Permissions

Upload requires `CONTENT_ADMIN` role + `content:create` permission. All other write operations require `content:update`. Read operations require `content:read`. `ROLE_GROUP_ADMIN` bypasses all checks. See the passport repo for user creation and permissions

## Running tests

```bash
vendor/bin/phpunit --testdox
```
