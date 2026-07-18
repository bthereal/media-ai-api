# Video Content Library - Symfony AI Bundle Exploration

A personal project built to explore the [Symfony AI Bundle](https://symfony.com/bundles/ai) in a real-world context. The goal was to wire the bundle's features into a working application that does something genuinely useful: upload MP4 videos, automatically transcribe and summarise them using AI, and then search across the entire library using natural language.

The project deliberately covers multiple bundle features in a single codebase - speech-to-text, LLM agents, vector embeddings, semantic retrieval, and tool use - so they can be compared and understood in relation to each other rather than in isolation.

There is a react vite UI project designed to work with this api at [bthereal/content-admin](https://github.com/bthereal/content-admin)

---

## What It Does

When a video is uploaded the application kicks off a fully automated pipeline:

1. The MP4 is uploaded in chunks and reassembled on the server.
2. ffmpeg strips the audio track and sends it to OpenAI Whisper for transcription.
3. GPT-4o-mini reads the transcript and writes a short summary.
4. The full transcript is converted into a vector embedding and stored in PostgreSQL alongside the video metadata.
5. Users can then search the library using plain English - the query is vectorised, matched against stored embeddings by cosine similarity, and an LLM agent composes a human-readable answer citing the relevant videos.

---

## Features

### 1. Speech-to-Text Transcription

**What happens:** After a video is assembled, ffmpeg extracts the audio-only track as an MP3. That audio file is passed directly to the Whisper-1 model via the Symfony AI platform abstraction, and the raw transcript is stored against the content record in the database.

**Technologies:** `symfony/ai-bundle` platform abstraction, OpenAI Whisper-1 STT model, ffmpeg audio extraction, Symfony Messenger for async dispatch.

**How it could evolve:** Whisper's context window means very long recordings can drift in accuracy. The next step would be to split long audio into overlapping segments before sending and stitch the results together. The bundle's platform abstraction means the underlying model could be swapped for a self-hosted Whisper instance or an alternative provider without touching the application code.

---

### 2. AI Summarisation

**What happens:** Once a transcript is available, GPT-4o-mini is given the full text and asked to produce a summary of 200 characters or fewer. This runs both automatically after transcription completes and on-demand via a dedicated API endpoint, so users can regenerate a summary after editing a transcript. The summary is stored on the transcription record and returned in all content responses.

**Technologies:** `symfony/ai-bundle` Agent, OpenAI GPT-4o-mini, configured entirely through `config/packages/ai.yaml` - no PHP code is needed to swap the model or rewrite the system prompt.

**How it could evolve:** The 200-character hard limit is a pragmatic UI decision. A more flexible approach would generate summaries at multiple lengths and let the consumer decide which to use. The prompt itself is also a candidate for improvement - few-shot examples in the system prompt tend to produce more consistent formatting across different video types.

---

### 3. Vector Embeddings and Semantic Search

**What happens:** This is the most involved feature and makes use of three distinct parts of the AI bundle working together.

- **Embedding:** The completed transcript is passed to the `text-embedding-ada-002` model, which converts it into a 1536-dimension vector representation of its meaning.
- **Storage:** That vector is written into a dedicated `video_transcript_embeds` table in PostgreSQL, managed by the `pgvector` extension. The table is set up via the bundle's own `ai:store:setup` command rather than Doctrine migrations.
- **Retrieval:** When a user searches, their query is embedded with the same model, then a cosine similarity query finds the closest matching transcripts in the vector store.
- **Agent with tool use:** A GPT-4o-mini agent is given a `similarity_search` tool (implemented as a plain PHP class tagged with `#[AsTool]`). The agent calls the tool with the user's query, receives a formatted list of matching videos, and composes a natural-language answer. The raw matching video IDs are also returned separately so the frontend can render direct links.

**Technologies:** `symfony/ai-bundle` vectorizer, postgres store, retriever, and agent with tool use; OpenAI `text-embedding-ada-002`; PostgreSQL 18 with the `pgvector` extension; cosine distance similarity.

**How it could evolve:** The current setup embeds the entire transcript as a single document. For longer videos this loses precision - a better approach is to chunk the transcript into overlapping passages and store each chunk separately, then aggregate results at retrieval time (sometimes called RAG chunking). Hybrid search combining vector similarity with a full-text keyword index (PostgreSQL's `tsvector`) would also improve recall for searches that include specific names or technical terms that embeddings don't capture well.

---

### 4. Async Processing Pipeline

**What happens:** The final chunk request returns immediately with a `contentId` - it does not wait for transcription or embedding to complete. Instead, a `TranscribeVideoMessage` is dispatched via Symfony Messenger to a Doctrine-backed queue. A worker process picks it up, runs the Whisper transcription, and on completion dispatches a second message, `EmbedVideoSummaryMessage`, which handles summarisation and vector storage. The frontend polls the content status endpoint to track progress.

**Technologies:** Symfony Messenger, Doctrine transport (`doctrine://default`), chained message handlers.

**How it could evolve:** The Doctrine transport works well for low volumes but uses database polling, which adds latency and load. For higher throughput the transport DSN can be switched to Redis or RabbitMQ with no code changes - just an environment variable update. Adding a dead-letter queue and retry configuration would also make failed transcriptions recoverable without manual intervention.

---

### 5. Chunked Video Upload

**What happens:** Large MP4 files are split into 2 MB chunks in the browser and sent sequentially. Each chunk is stored temporarily on the server, and when the final chunk arrives the pieces are reassembled into the complete file. The controller also performs a SHA-256 hash of the assembled content to detect duplicate uploads before persisting anything.

**Technologies:** Custom chunked upload protocol, League Flysystem for chunk and assembled file storage, PHP `hash('sha256')` for deduplication.

**How it could evolve:** Chunks are currently stored on the local filesystem. Swapping the Flysystem adapter to S3 (or any S3-compatible store) would allow the API to scale horizontally without shared storage concerns. Resumable uploads - tracking which chunks have been received so an interrupted upload can continue from where it left off - would be a meaningful improvement for large files on slow connections.

---

### 6. JWT Authentication and Role-Based Permissions

**What happens:** Login returns a signed JWT containing the user's roles and permissions as custom claims, injected at token creation time via a `JWTCreatedListener`. Every subsequent request is stateless - the token is verified by the security firewall and the claims are extracted into a `TenantContext` service available throughout the request. Permission checks (`content:read`, `content:create`, `content:update`) are centralised in a `PermissionChecker` service; users with `ROLE_GROUP_ADMIN` bypass all permission checks.

**Technologies:** `lexik/jwt-authentication-bundle`, Symfony Security, custom JWT event listeners, stateless API firewall.

**How it could evolve:** The current implementation stores all roles and permissions directly in the JWT, which means a token issued before a permission change remains valid until expiry. Short-lived tokens with a refresh token flow, or token revocation via a blocklist, would close that gap.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.4 |
| Framework | Symfony 8.0 |
| AI | Symfony AI Bundle, OpenAI (Whisper-1, GPT-4o-mini, text-embedding-ada-002) |
| Database | PostgreSQL 18 with pgvector |
| ORM | Doctrine ORM 3 |
| File storage | League Flysystem (local adapter) |
| Video processing | ffmpeg |
| Queue | Symfony Messenger (Doctrine transport) |
| Auth | lexik/jwt-authentication-bundle |
| API docs | Nelmio API Doc Bundle (OpenAPI 3) |
| Web server | Nginx + PHP-FPM |
| Containers | Docker / Docker Compose |

---

## Setup

### Prerequisites

- Docker and Docker Compose
- An OpenAI API key

### 1. Clone and configure environment

```bash
git clone <repo-url>
cd <project-directory>
cp .env .env.local
```

Edit `.env.local` and fill in the three required secrets - everything else can stay as the defaults for local development:

```dotenv
APP_SECRET=<any random 32-character string>
OPENAI_API_KEY=<your OpenAI API key>
JWT_PASSPHRASE=<any passphrase - used to protect the JWT signing key>
```

### 2. Build and start containers

```bash
docker compose up -d --build
```

This starts three services:

| Container | Purpose |
|---|---|
| `php` | PHP 8.4-FPM with all extensions and vendor dependencies baked in |
| `nginx` | Reverse proxy, available at `http://localhost:8080` |
| `database` | PostgreSQL 18 with the pgvector extension |

### 3. Generate JWT signing keys

```bash
docker compose exec php php bin/console lexik:jwt:generate-keypair
```

This writes `config/jwt/private.pem` and `config/jwt/public.pem`. These files are gitignored and must be regenerated on each fresh checkout.

### 4. Run database migrations

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

### 5. Set up the vector store table

The `video_transcript_embeds` table is managed by the AI bundle, not Doctrine. Create it with:

```bash
docker compose exec php php bin/console ai:store:setup ai.store.postgres.video_transcript_embeds
```

### 6. Create an admin user

```bash
docker compose exec php php bin/console app:create-admin
```

You will be prompted for an email, password, first name, and last name. The created user has `ROLE_GROUP_ADMIN` with full content permissions.

---

The application is now running at `http://localhost:8080`.

---

## Running tests

There are unit and functional test in place to cover the main features and functionality.

The functional tests require a app_test database via the postgres container, which will also need the migrations executed on. This could be handled better via an abstract test setup method but is manual for now.

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

Then run tests via:

```bash 
docker compose exec php php bin/phpunit
```

---

## API Overview


| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/auth/token` | Log in, receive a JWT |
| `GET` | `/api/auth/me` | Current user profile and permissions |
| `POST` | `/api/auth/register` | Create a new user (admin only) |
| `POST` | `/api/upload/chunk` | Upload a single MP4 chunk |
| `GET` | `/api/content` | Paginated content list |
| `GET` | `/api/content/{id}` | Content metadata and transcription status |
| `PATCH` | `/api/content/{id}` | Update title or summary |
| `DELETE` | `/api/content/{id}` | Archive content (soft delete) |
| `POST` | `/api/content/{id}/summarize` | Regenerate AI summary on demand |
| `GET` | `/api/content/{id}/stream` | Stream the MP4 file (supports HTTP Range requests) |
| `GET` | `/api/content/{id}/thumbnail` | JPEG thumbnail image |
| `POST` | `/api/content/search` | Natural language search across the library |

All endpoints except `/api/auth/token` require an `Authorization: Bearer <token>` header.

---

## Console Commands

| Command | Description |
|---|---|
| `app:create-admin` | Interactively create an admin user |
| `app:generate-thumbnails` | Backfill thumbnails for any content missing one |
| `app:embed-videos` | Backfill vector embeddings for completed transcriptions |
| `ai:store:setup ai.store.postgres.video_transcript_embeds` | Create the pgvector table (run once after checkout) |

---

## Project Structure

```
src/
├── Command/          Console commands (admin user, backfill jobs)
├── Controller/       API endpoints
├── Dto/              API response shapes
├── Entity/           Doctrine entities (User, Content, VideoTranscription)
├── EventListener/    JWT claim injection at login
├── Message/          Messenger message classes
├── MessageHandler/   Async job handlers (transcription, embedding)
├── Repository/       Doctrine repositories
├── Security/         JWT context extraction and permission checker
├── Service/          Core services (transcription, summary, ffmpeg, upload)
└── Tool/             AI agent tools (#[AsTool] - similarity search)
```
