# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Symfony 8 / PHP 8.4 API for managing MP4 video content with AI-powered transcription, summarisation, and semantic search. Videos are uploaded in chunks, assembled server-side, transcribed via OpenAI Whisper, and embedded into a pgvector store to power natural-language search. Backs a React admin UI but is fully usable standalone.

```
Upload → Assemble → Transcribe (Whisper) → Embed transcript (pgvector)
                                         ↳ Generate thumbnail (ffmpeg)
                 On demand → Summarise (GPT-4o-mini)
                 On demand → Semantic search (GPT-4o-mini + pgvector)
```

## Commands

```bash
# Install dependencies
composer install

# Start Postgres (with pgvector) via Docker
docker compose up -d

# Run migrations
php bin/console doctrine:migrations:migrate --no-interaction

# Create the pgvector embeddings table (not a Doctrine migration — separate step)
php bin/console ai:store:setup ai.store.postgres.video_transcript_embeds

# Start the dev server
symfony serve

# Run the full test suite
vendor/bin/phpunit --testdox

# Run a single test file / method
vendor/bin/phpunit tests/Unit/Service/VideoTranscriptionServiceTest.php
vendor/bin/phpunit --filter testMethodName

# Re-embed all completed transcriptions (e.g. after changing vectorizer/model config)
php bin/console app:embed-videos

# Backfill thumbnails for content missing one
php bin/console app:generate-thumbnails
```

Tests are unit/functional only and mock all external dependencies (OpenAI, filesystem) — no live DB or API key required to run them.

## Architecture

### Async pipeline is all `sync://` — but still message-driven

`TranscribeVideoMessage` and `EmbedVideoSummaryMessage` are routed through Symfony Messenger, but the transport is `sync://` (see `config/packages/messenger.yaml`), so handlers run inline in the same request/process. The chunked-upload endpoint dispatches `TranscribeVideoMessage` synchronously after assembly; `TranscribeVideoHandler` transcribes, updates `VideoTranscription` status, then dispatches `EmbedVideoSummaryMessage`, which `EmbedVideoSummaryHandler` uses to vectorize the transcript into pgvector. If this were swapped to a real async transport (Redis/AMQP), the request would return before transcription/embedding complete — several controller assumptions (e.g. summarize requiring `status === 'completed'`) already account for this eventuality.

### `Content` and `VideoTranscription` are separate entities, joined 1:1

`Content` (in `content` table) holds file/upload metadata; `VideoTranscription` (in `video_transcription` table) holds transcription status/text/summary, linked via a nullable `transcription_id` FK. Status transitions (`pending → processing → completed | failed`) live entirely on `VideoTranscription` via `markProcessing()`/`markCompleted()`/`markFailed()`. The pgvector table `video_transcript_embeds` is a third, separate store managed by `ai:store:setup`/Symfony AI, not Doctrine — its document ID is the `Content` UUID (string), which is what makes `embedAndStore` upserts idempotent and what `VideoSimilaritySearch`/`SearchController` use to look the `Content` row back up after a retrieval hit.

### Symfony AI bundle wiring (`config/packages/ai.yaml`)

Three named agents (`ai.agent.*`), all `gpt-4o-mini` on the OpenAI platform:
- `video_transcriber` — Whisper STT, no tools, verbatim-only prompt (not currently invoked directly by app code; transcription instead goes through `VideoTranscriptionService` calling `ai.platform.openai` with `whisper-1` directly on an `Audio` message).
- `video_summarizer` — used by `VideoSummaryService::summarize()`.
- `video_search` — used by `SearchController`; has the `VideoSimilaritySearch` tool (`App\Tool\VideoSimilaritySearch`, tool name `similarity_search`) bound, which itself queries `ai.retriever.video_transcript_embeds`.

Services referenced via `#[Autowire(service: 'ai.xxx')]` rather than type-hinted, since these are Symfony AI's runtime-configured service IDs, not autodiscoverable classes — grep `config/packages/ai.yaml` when tracing which agent/store/vectorizer backs a given `Autowire` attribute.

`VideoTranscriptionService` extracts an audio-only track via `AudioExtractor` (ffmpeg) before sending to Whisper, since Whisper's 25MB limit is easily exceeded by full MP4s — don't send the raw MP4 directly.

### Chunked upload protocol

Custom sequential protocol (not a standard resumable-upload spec): client POSTs chunks to `/api/upload/chunk` with `uploadId`/`chunkIndex`/`totalChunks`. `ChunkUploadService` writes each chunk to `temp/{uploadId}/{chunkIndex}` via Flysystem, and only on receipt of the final chunk (`chunkIndex + 1 === totalChunks`) does `assembleIfComplete` concatenate all chunks in order and move the result to `{uploadId}/{filename}`. Files are content-addressed after assembly via SHA-256 (`Content.fileHash`, unique constraint) — a duplicate upload short-circuits before creating a new `Content`/`VideoTranscription` pair or dispatching transcription.

### Permission model

`TenantContext` is a per-request scoped service (`ResetInterface`) populated by `JWTAuthenticatedListener` from `tenant_roles`/`tenant_permissions` JWT claims — auth itself is delegated entirely to an external Passport service via LexikJWT; there's no local user entity or login endpoint. `PermissionChecker` is the single point controllers call (`hasPermission()` / `hasRoleAndPermission()`); `ROLE_GROUP_ADMIN` always bypasses checks, and an absent security token (e.g. test env, or the `stream`/`thumbnail` public routes) is treated as permitted rather than denied — see `config/packages/security.yaml`'s `access_control` for the two `PUBLIC_ACCESS` exceptions. Upload requires `CONTENT_ADMIN` role + `content:create`; other writes need `content:update`; reads need `content:read`.

### AI Mate MCP

This repo has `symfony/ai-mate` installed as an MCP-powered project assistant (see `AGENTS.md`, `mate/`). Per `mate/AGENT_INSTRUCTIONS.md`, prefer its MCP tools over equivalent raw shell commands when both are available. `mate/extensions.php` and `mate/AGENT_INSTRUCTIONS.md` are regenerated by `vendor/bin/mate discover` — don't hand-edit the managed sections.
