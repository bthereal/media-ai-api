# 🎬 YouFlix Media API

**Upload a video. Get back a transcript, an AI summary, chapters, tags, translated captions, and a
semantic search engine that finds it later just by describing what's in it.**

A Symfony 8 / PHP 8.4 backend built to push the [Symfony AI Bundle](https://symfony.com/bundles/ai)
through every corner of a real, non-trivial product — speech-to-text, LLM agents, vector embeddings,
retrieval-augmented search, and tool calling — all wired into one coherent pipeline rather than
demoed as isolated snippets.

[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-8.0-000000?logo=symfony&logoColor=white)](https://symfony.com/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-18-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![pgvector](https://img.shields.io/badge/pgvector-HNSW-336791)](https://github.com/pgvector/pgvector)
[![OpenAI](https://img.shields.io/badge/OpenAI-Whisper%20%C2%B7%20GPT--4o--mini-412991?logo=openai&logoColor=white)](https://openai.com/)
[![Tests](https://img.shields.io/badge/tests-247%20passing-2ea44f)](#testing)

**Companion UI:** [`media-ai-admin`](https://github.com/bthereal/media-ai-admin) — a React 19 / TypeScript
admin frontend built specifically to drive this API, covering upload, playback, search, playlists, and
role-based user management.

---

## Table of Contents

- [What It Does](#what-it-does)
- [Architecture](#architecture)
- [Domain Model](#domain-model)
- [Features](#features)
- [Engineering Decisions — Why This Stack](#engineering-decisions--why-this-stack)
- [Authentication & Permissions](#authentication--permissions)
- [Companion Frontend](#companion-frontend)
- [Infrastructure & CI/CD](#infrastructure--cicd)
- [Tech Stack](#tech-stack)
- [Getting Started](#getting-started)
- [Testing](#testing)
- [API Reference](#api-reference)
- [Console Commands](#console-commands)
- [Project Structure](#project-structure)
- [Known Limitations & Roadmap](#known-limitations--roadmap)

---

## What It Does

Upload an MP4 and the platform takes it from there, fully automatically:

```
Upload (chunked) → Assemble → Dedupe (SHA-256) → Transcribe (Whisper)
                                                        │
                        ┌───────────────┬───────────────┼───────────────┐
                        ▼               ▼               ▼               ▼
                   Embed for       Chapters         Category        Captions
                   search           & tags          & topics       (on demand)
                  (pgvector)      (GPT-4o-mini)    (GPT-4o-mini)   (translated,
                                                                     lazily, per
                                                                      language)
```

Once that's done, a viewer can type a plain-English question — *"videos about pricing objections"*,
*"how do I format a drive for Mac"* — and get back a natural-language answer with direct links to the
right clips, ranked by semantic similarity rather than keyword match.

None of this is a toy pipeline sitting next to a real app — it *is* the app. Every stage is exercised
by the same upload, the same search bar, the same admin UI.

---

## Architecture

<img src="docs/architecture.png" alt="System architecture: React admin UI → nginx → PHP-FPM / messenger-worker → PostgreSQL+pgvector, Flysystem, OpenAI" width="100%">

Two PHP processes matter here, and the split is deliberate:

- **`php` (php-fpm)** — the web process. Fast, request/response, never blocks on anything slow.
- **`messenger-worker`** — a separate, long-running container consuming a Doctrine-backed queue. Every
  slow, AI-dependent step (transcription, embedding, chaptering, tagging, translation) happens here,
  completely decoupled from the HTTP request that kicked it off.

PostgreSQL is doing three jobs at once in this system — relational store, message queue (Doctrine
transport), and vector database (pgvector) — a deliberate consolidation explained in
[Engineering Decisions](#engineering-decisions--why-this-stack). The frontend never talks to OpenAI
directly; every AI call is server-side, keeping the API key off the client and every prompt/model
choice centrally controlled.

---

## Domain Model

<img src="docs/domain_model.png" alt="Entity-relationship diagram: User, Content, VideoTranscription, Playlist, PlaylistItem, WatchEvent, and the pgvector embeddings table" width="100%">

A few things worth calling out:

- **`Content` and `VideoTranscription` are deliberately separate entities**, joined 1:1. `Content` is
  "what was uploaded" — stable file/upload metadata that rarely changes. `VideoTranscription` is "what
  the AI pipeline produced" — a `pending → processing → completed | failed` state machine that gets
  rewritten repeatedly across the async pipeline. Splitting them means the *file* record is never
  contended by concurrent AI-pipeline writes.
- **The vector store is a third, independent persistence layer**, outside Doctrine's mapping entirely
  — its schema is owned by the AI bundle's own `ai:store:setup` command, not a migration. Its primary
  key is literally the `Content` UUID as a string, which is what makes embedding an upsert (re-running
  the pipeline after a model change just overwrites the same row) and what lets search results get
  mapped straight back to a real video.
- **Ownership is a plain string column (`ownerId`), not a foreign key.** Content and playlists need to
  survive their owning user being deactivated or removed without a cascading delete wiping them out,
  and the ownership check at permission time is a cheap string comparison against the JWT's identity
  claim — no join required.

---

## Features

### 🎙️ Speech-to-Text Transcription

The MP4's audio track is extracted with ffmpeg and sent straight to Whisper via the AI bundle's
platform abstraction — no intermediate agent layer, since speech-to-text has no "conversation" to
prompt-engineer, just a transcript to produce.

The extraction itself is duration-aware on two axes that matter a lot in practice: the output bitrate
is computed from the video's length so long recordings never silently exceed Whisper's 25MB upload
cap, and the ffmpeg timeout scales with duration rather than relying on a flat default that a
90-minute video would blow straight through.

### ✍️ AI Summarisation

GPT-4o-mini reads the completed transcript and writes a ≤200-character summary — generated
automatically once, and regenerable on demand from the UI. Critically, a summary is just a regular
editable field: **a user can write and save their own custom summary at any point, including before
transcription has even finished**, and the pipeline never silently overwrites a manually-set value.

### 🔍 Semantic Search (RAG)

The most involved feature, and the one that exercises the AI bundle most fully:

<img src="docs/vectoriser_stack.png" alt="Sequence diagram: EmbedVideoSummaryHandler vectorizes the transcript via text-embedding-ada-002 and upserts into pgvector" width="100%">

**Writing:** once transcription completes, the transcript is embedded with `text-embedding-ada-002`
into a 1536-dimension vector and upserted into a dedicated pgvector table, keyed by the video's own
UUID.

<img src="docs/vectoriser_flow.png" alt="Sequence diagram: a search query flows through SearchController, the video_search agent, the similarity_search tool, and pgvector, back to a natural-language answer" width="100%">

**Reading:** a search agent is handed a `similarity_search` tool — a plain PHP class tagged
`#[AsTool]` — and decides for itself when to call it based on the conversation. The tool runs a pure
cosine-distance vector query with a real relevance cutoff (not a keyword-gated hybrid search — see
[Engineering Decisions](#engineering-decisions--why-this-stack) for why that distinction mattered in
practice), and the agent composes a natural-language answer citing the matches. The raw IDs come back
alongside the prose so the UI can render direct links, capped at three results so a search never comes
back as a wall of loosely-related videos.

### 🗂️ Chapters, Tags & Category

Once a transcript lands, three more agents run in parallel: one splits it into 3–8 named chapters with
timestamps, one extracts topic tags and assigns a single browsing category, and both write back to the
video without the user lifting a finger.

### 🌍 Captions, Translated On Demand

Native-language captions are served directly from the stored transcript — free, instant, no AI call.
Translations into other languages are generated **lazily, per language, the first time a viewer's own
player requests that specific track** — not eagerly for every video in every supported language at
upload time. The first request for an untranslated language kicks off a background job and returns
immediately; the browser's native captions menu just shows nothing for that language until it's ready,
exactly like any other track that hasn't loaded yet. This keeps translation cost proportional to actual
demand instead of the size of the language catalog.

### ⚙️ Async Processing Pipeline

<img src="docs/core_workflow.png" alt="Flowchart: chunked upload, assembly, SHA-256 dedupe, async transcription, and a fan-out into embedding, chapters, and tagging" width="100%">

The request that assembles the final upload chunk returns immediately — it never waits on
transcription. A message is dispatched via Symfony Messenger onto a Doctrine-backed queue; the
`messenger-worker` container picks it up, transcribes, and then fans out into several independent
follow-up jobs that all run in parallel since none of them depend on each other.

### 📦 Chunked Upload with Content-Addressed Dedup

Large files are split into 2MB chunks client-side and reassembled server-side on receipt of the final
piece. The assembled file is then SHA-256 hashed — a duplicate upload of the exact same bytes
short-circuits before a second database row or a second transcription job is ever created, which
matters because transcription is the expensive, billable step in the whole pipeline.

---

## Engineering Decisions — Why This Stack

Every non-obvious technology choice here was made deliberately, with a real trade-off behind it —
not defaults picked out of habit. The reasoning generalizes past this specific project.

<details>
<summary><strong>PostgreSQL + pgvector, not MySQL or a dedicated vector database</strong></summary>

<br>

`pgvector` is a mature Postgres extension offering a real vector type with **indexed**
approximate-nearest-neighbor search and native distance operators — MySQL has no equivalent, and
without it you're doing brute-force distance calculation in application code, which stops scaling
almost immediately.

A standalone vector database is the *textbook-correct* choice at serious scale, but it's a second
system to run, secure, back up, and keep consistent with the primary database. Colocating vectors in
the same Postgres instance that already holds the relational data means one database to operate, and
trivial referential integrity — the vector store's document ID is literally the primary entity's UUID,
so there's no cross-system ID mapping to maintain.

**The trade-off, named honestly:** pgvector's ANN indexes don't scale as gracefully as a
purpose-built vector engine at tens-of-millions-of-vectors scale, and index choice matters more than
it looks. An IVFFlat index (pgvector's older default) is tuned for *large* tables — on a small
library its default `probes=1` setting can scan so little of the index that a query returns almost
arbitrary results rather than true nearest neighbors. HNSW gives reliable recall across dataset sizes
with no size-dependent tuning, and is the right default unless the table is enormous.
</details>

<details>
<summary><strong>Symfony Process for shelling out to ffmpeg — and never raw shell strings</strong></summary>

<br>

`ffmpeg` and Whisper preprocessing aren't PHP libraries — they're external binaries, and
`symfony/process` is the safe way to invoke them. Arguments are passed as an **array**, never a
concatenated shell string, which structurally rules out an entire class of shell-injection bugs rather
than relying on someone remembering to escape correctly.

The less obvious lesson: `Process` has a **default 60-second timeout**, which is exactly long enough
to work perfectly in testing on short clips and then silently fail on real content. A full-length
audio transcode or a slow-seeking frame extraction on a long video can both blow past that default —
the fix isn't just "raise the number," it's computing a timeout (and, for audio extraction, a target
bitrate) from the actual input duration, so correctness doesn't depend on nobody ever uploading
anything longer than what got tested.
</details>

<details>
<summary><strong>A real message queue (even without RabbitMQ or Redis)</strong></summary>

<br>

The async pipeline is built on Symfony Messenger from day one, deliberately decoupled from *how*
messages are delivered. The message classes and their handlers know nothing about their transport —
today that's `doctrine://`, using the same Postgres instance as a durable queue table, but swapping to
Redis or AMQP under real load is a one-line DSN change, not a refactor.

Doctrine transport specifically needs zero extra infrastructure: no broker to run, secure, or monitor.
It's also durable by default — a crashed worker doesn't lose in-flight messages, they're just rows
waiting to be reclaimed — and trivially inspectable with a plain SQL query when debugging, instead of
needing a broker-specific admin tool.

The pipeline also fans out deliberately: once a transcript exists, four independent jobs (embedding,
chapters, tags, and — now — nothing eager for captions, see above) are dispatched in parallel rather
than chained, because none of them depend on each other's output.
</details>

<details>
<summary><strong>A hand-rolled chunked upload, not a resumable-upload library</strong></summary>

<br>

Reliable large-file upload over consumer connections needs chunking, but pulling in a full resumable
upload spec was more machinery than the actual requirement — sequential, same-session chunk upload,
not cross-session resume from an arbitrary offset. The protocol here is intentionally minimal: the
client posts chunks in order, the server assembles on the final one, and a content hash makes retries
idempotent for free.
</details>

<details>
<summary><strong>Stateless JWTs with claims baked in, not a database check per request</strong></summary>

<br>

Every request is authenticated by a signed JWT carrying the user's roles and permissions as custom
claims, injected at token-mint time — authorization decisions never need a database round-trip to
check *what* someone can do, only that their token's signature is valid. Fine-grained ownership checks
(can this specific user edit this specific video) still happen per-request against the database, since
that's data a token can't carry — the split is deliberate: coarse permission from the token, fine
ownership from the row.

The real cost of this design showed up directly: deactivating a user needs to take effect
*immediately*, not just block future logins, since an already-issued token would otherwise keep
working until it naturally expired. That's why deactivation is checked live against the database on
every request, not just gated at login.
</details>

<details>
<summary><strong>Semantic search over hybrid search, once it actually mattered</strong></summary>

<br>

The AI bundle's retriever defaults to a *hybrid* search — semantic similarity blended with a Postgres
full-text keyword match — when the store supports it. That sounds strictly better than pure vector
search, but the keyword half is a hard **requirement**, not a bonus signal: a document only qualifies
at all if at least one word overlaps somewhere in its transcript. On a full-length transcript, that's
an extremely low bar — a stray common word is enough to pull a completely unrelated video into
results.

Pure cosine-distance search with a real, empirically-calibrated distance cutoff (measured against this
library's actual embeddings, not a value picked from theory) turned out to be the correct choice for
"is this result actually relevant," precisely because it doesn't have a keyword-match escape hatch
letting irrelevant content in.
</details>

---

## Authentication & Permissions

<img src="docs/auth_flow.png" alt="Sequence diagram: login issues a signed JWT with tenant role/permission claims; every request re-derives TenantContext from the token and checks ownership per entity" width="100%">

Two layers, checked separately:

1. **Coarse-grained, from the token.** Does this JWT's claims include the permission the endpoint
   needs (`content:update`, `content:read`, …)? No database hit required — the claims were signed at
   login.
2. **Fine-grained, from the row.** *Given* the general permission, is this specific record theirs?
   Admins bypass this; everyone else is checked against a stored owner identifier. This pattern —
   extracted into a single reusable helper once it needed to guard five different mutation endpoints
   — is applied identically to both video ownership and to users editing their own profile.

---

## Companion Frontend

<img src="docs/frontend_architecture.png" alt="React Router v7 route map: public login, a protected root layout with sidebar nav, admin-only Analytics/Users routes, and a self-or-admin user detail route" width="100%">

[`media-ai-admin`](https://github.com/bthereal/media-ai-admin) is a React 19 + TypeScript admin UI
built specifically to drive this API — upload, playback with resume and chapter navigation, playlist
management, natural-language search, analytics, and role-based user administration. Route-level guards
mirror the backend's RBAC shape directly (`ProtectedRoute` for "logged in," `RequireAdmin` for
admin-only sections), while ownership-specific pages that need a route parameter compared against the
current user — like a user's own profile — do that fine-grained check inside the component itself,
exactly mirroring the backend's coarse-vs-fine split above.

---

## Infrastructure & CI/CD

<img src="docs/infra_ci.png" alt="Docker Compose topology and GitHub Actions pipelines for both repos, feeding into branch protection requiring passing checks" width="100%">

Both repositories run a `build → lint → test` pipeline as a required status check before merge, on
both `main` and `develop`:

- **Backend:** `composer install` → syntax lint + PHPCS (PSR-12) + Psalm (level 4) → a full PHPUnit run
  against a **fresh** pgvector service container (migrations, `ai:store:setup`, and
  `messenger:setup-transports` all run from scratch every time, so "works in CI" and "works from a
  clean checkout" mean the same thing).
- **Frontend:** `pnpm install` (cached) → `tsc --noEmit` + ESLint → a production `vite build`.

Branch protection on both repos blocks force-push and branch deletion and requires a passing PR before
merge on every protected branch.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.4 |
| Framework | Symfony 8.0 |
| AI | Symfony AI Bundle · OpenAI Whisper-1, GPT-4o-mini, `text-embedding-ada-002` |
| Database | PostgreSQL 18 + pgvector (HNSW) |
| ORM | Doctrine ORM 3 |
| Async queue | Symfony Messenger (Doctrine transport) |
| File storage | League Flysystem |
| Video/audio processing | ffmpeg |
| Auth | `lexik/jwt-authentication-bundle`, stateless JWT |
| API docs | NelmioApiDocBundle (OpenAPI 3) |
| Web server | nginx + PHP-FPM |
| Containers | Docker / Docker Compose |
| Frontend (companion repo) | React 19, TypeScript, Vite, react-router v7 |

---

## Getting Started

### Prerequisites

- Docker and Docker Compose
- An OpenAI API key

### 1. Clone and configure

```bash
git clone git@github.com:bthereal/media-ai-api.git
cd media-ai-api
cp .env .env.local
```

Fill in the required secrets in `.env.local` — everything else is fine at its default for local dev:

```dotenv
APP_SECRET=<any random 32-character string>
OPENAI_API_KEY=<your OpenAI API key>
JWT_PASSPHRASE=<any passphrase — protects the JWT signing key>
```

### 2. Build and start the stack

```bash
docker compose up -d --build
```

| Container | Purpose |
|---|---|
| `php` | PHP-FPM — HTTP request/response, controllers, DTOs, services |
| `messenger-worker` | Long-running consumer of the async pipeline (transcription, embedding, chapters, tags, translation) |
| `nginx` | Reverse proxy — the app is served at `http://localhost:8080` |
| `database` | PostgreSQL 18 with pgvector |
| `mailer` | Mailpit — local dev mail catcher |

### 3. Generate a JWT signing keypair

```bash
docker compose exec php php bin/console lexik:jwt:generate-keypair
```

Writes `config/jwt/private.pem` / `public.pem` — both gitignored, regenerate on every fresh checkout.

### 4. Run migrations and set up the vector store

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console ai:store:setup ai.store.postgres.video_transcript_embeds
docker compose exec php php bin/console messenger:setup-transports
```

The `video_transcript_embeds` table is owned by the AI bundle, not Doctrine — it's created by its own
setup command rather than a migration.

### 5. Create an admin user

```bash
docker compose exec php php bin/console app:create-admin
```

You'll be prompted for an email, password, first name, and last name. The account gets
`ROLE_GROUP_ADMIN` with full permissions.

The app is now running at **http://localhost:8080**.

---

## Testing

Unit and functional tests mock every external AI/network call — the suite needs no API key and no
network access to run. Functional tests run against a real Postgres instance.

```bash
# One-time: migrate the test database
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction --env=test
docker compose exec php php bin/console ai:store:setup ai.store.postgres.video_transcript_embeds --env=test
docker compose exec php php bin/console messenger:setup-transports --env=test

# Run the full suite
docker compose exec php vendor/bin/phpunit

# Or a single file / method
docker compose exec php vendor/bin/phpunit tests/Unit/Service/VideoTranscriptionServiceTest.php
docker compose exec php vendor/bin/phpunit --filter testMethodName
```

---

## API Reference

All endpoints require `Authorization: Bearer <token>` except `POST /api/auth/token` and a handful of
routes deliberately left public for direct browser use (video streaming, thumbnails, caption `.vtt`
files, and watch-event beacons — none of which can carry an auth header from a native `<video>` or
`<track>` element).

### Auth & Users

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/auth/token` | Log in, receive a JWT |
| `GET` | `/api/auth/me` | Current user's profile and permissions |
| `POST` | `/api/auth/register` | Create a new user *(admin)* |
| `GET` | `/api/auth/users` | List all users *(admin)* |
| `GET` | `/api/auth/users/{id}` | Get a user *(admin, or self)* |
| `PATCH` | `/api/auth/users/{id}` | Update a user *(admin, or self — self can't change role)* |
| `POST` | `/api/auth/users/{id}/deactivate` | Deactivate a user *(admin, can't target self)* |
| `POST` | `/api/auth/users/{id}/reactivate` | Reactivate a user *(admin)* |

### Content & Upload

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/upload/chunk` | Upload one chunk of a video |
| `GET` | `/api/content` | Paginated content list |
| `GET` | `/api/content/{id}` | Content metadata and transcription status |
| `PATCH` | `/api/content/{id}` | Update title / summary *(owner or admin)* |
| `DELETE` | `/api/content/{id}` | Archive content *(owner or admin)* |
| `POST` | `/api/content/{id}/summarize` | Regenerate the AI summary on demand |
| `GET` | `/api/content/{id}/stream` | Stream the MP4 (HTTP Range supported) |
| `GET` | `/api/content/{id}/thumbnail` | Current thumbnail JPEG |
| `POST` | `/api/content/{id}/thumbnail` | Regenerate thumbnail candidates |
| `GET` | `/api/content/{id}/thumbnail/candidates/{index}` | A specific candidate frame |
| `POST` | `/api/content/{id}/thumbnail/select` | Pick a candidate as the thumbnail |
| `GET` | `/api/content/{id}/captions/{lang}.vtt` | WebVTT captions — translated lazily on first request |
| `GET` | `/api/content/{id}/related` | Semantically similar videos |
| `GET` | `/api/transcription/{uploadId}` | Raw transcription record |

### Search & Analytics

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/content/search` | Natural-language semantic search |
| `GET` | `/api/analytics/overview` | Library-wide watch analytics |
| `GET` | `/api/content/{id}/analytics` | Per-video watch analytics + retention curve |
| `GET` | `/api/content/{id}/progress` | Current viewer's resume position |
| `POST` | `/api/content/{id}/watch-events` | Record a playback event *(public — used by the `<video>` element)* |

### Playlists

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/playlists` | List playlists |
| `POST` | `/api/playlists` | Create a playlist |
| `GET` | `/api/playlists/{id}` | Get a playlist and its items |
| `PATCH` | `/api/playlists/{id}` | Rename a playlist |
| `DELETE` | `/api/playlists/{id}` | Delete a playlist |
| `POST` | `/api/playlists/{id}/items` | Add a video to a playlist |
| `PATCH` | `/api/playlists/{id}/items` | Reorder items |
| `DELETE` | `/api/playlists/{id}/items/{itemId}` | Remove an item |

---

## Console Commands

| Command | Description |
|---|---|
| `app:create-admin` | Interactively create an admin user |
| `app:generate-thumbnails` | Backfill thumbnails for content missing one |
| `app:embed-videos` | Re-embed all completed transcriptions (after a model/config change) |
| `ai:store:setup ai.store.postgres.video_transcript_embeds` | Create the pgvector table — run once per environment |

---

## Project Structure

```
src/
├── Command/         Console commands — admin bootstrap, backfill jobs
├── Controller/      API endpoints
├── Dto/             API response shapes
├── Entity/          Doctrine entities — User, Content, VideoTranscription, Playlist, WatchEvent
├── EventListener/   JWT claim injection at login
├── Message/         Messenger message classes
├── MessageHandler/  Async job handlers — transcription, embedding, chapters, tags, translation
├── Repository/      Doctrine repositories
├── Security/        JWT context extraction, permission checking
├── Service/         Core services — transcription, summarization, ffmpeg extraction, upload, search
└── Tool/            AI agent tools (#[AsTool]) — similarity_search
```

---

## Known Limitations & Roadmap

Named unprompted, because a real project has these and pretending otherwise is a worse look than
admitting them:

- **The AI bundle is pre-1.0** (`^0.8.0`). Its API surface moved during this project's lifetime —
  fine for exploration, a real conversation for anyone considering it for production today.
- **No frontend component/integration test suite yet.** CI proves the frontend builds and typechecks,
  not that it behaves correctly end-to-end. Playwright coverage of the upload → poll → edit flow is
  the natural next investment.
- **Single Postgres instance for everything** — OLTP data, the message queue, and the vector store.
  Fine at this project's scale; the first thing to reconsider under real load is separating the queue
  from the primary database so a backlog of AI jobs can never contend with user-facing query latency.
- **pgvector's ceiling.** HNSW gives reliable recall at this project's scale, but a genuinely large
  library (many millions of embeddings, sub-100ms P99 requirements) would eventually justify a
  dedicated vector engine instead.
- **Translation reliability on very long transcripts.** The translation model occasionally drifts off
  the requested line-count on batches built from short, comma-fragmented source segments — retried
  automatically via the message queue, and it fails safely (no captions for that language yet, nothing
  crashes), but it isn't 100% first-try reliable for every language on every video.
