# Data dictionary

| Table/group | Purpose and important integrity rules |
|---|---|
| `users` | Shared tenant/owner/admin identity; indexed role/status, hashed password, soft deletion. |
| `tenant_profiles`, `owner_profiles` | Role-specific preferences and verification. One-to-one with users. Required facilities are an explicitly documented JSON preference cache. |
| `locations`, `institutions`, `facilities` | Reference data; unique area tuple/facility code; coordinates are decimals. |
| `listings` | Owner, private/public location, integer LKR money, availability, moderated status, aggregate counters and timestamps; soft deleted. |
| `listing_facility` | Unique many-to-many facility assignment. |
| `listing_images` | Safe storage/thumbnail paths, MIME/size/dimensions, caption/alt/order/cover; unique listing order. |
| `listing_nearby_places` | Point type/name and straight-line metres; never represented as route travel time. |
| `listing_status_history` | Actor, previous/new state, reason and timestamp for every workflow transition. |
| `favorites` | Composite primary key prevents duplicate tenant/listing saves. |
| `saved_searches` | Structured filter JSON, optional natural query and notification toggle. |
| `conversations`, `messages`, `conversation_reads` | Unique listing/tenant/owner scope, participant-only bodies, read cursor and per-role archive timestamps. |
| `reviews` | Unique tenant/listing, integer 1–5 rating, plain body, moderation state and soft deletion. |
| `review_reports`, `listing_reports` | Deduplicated user reports and admin resolution status. |
| `review_ai_analyses` | Cached label/confidence/aspects/model/status/error; application review remains authoritative. |
| `listing_review_summaries` | Versioned aggregate cache with visible review count and evidence counts. |
| `notifications` | Laravel database notification UUID/data/read time; links are restricted to internal paths. |
| `search_logs` | Sanitized query/filter/result/mode/latency; no message/password content. |
| `analytics_events` | Privacy-conscious event type, optional user/listing, metadata and occurrence time. |
| `ai_model_versions`, `ai_index_records` | Version/checksum/manifest and per-listing pending/indexed/error lifecycle. |
| `admin_audit_logs` | Immutable actor/action/target/reason/before-after/request/IP hash for sensitive actions. |
| `sessions`, `password_reset_tokens`, `jobs`, `failed_jobs`, `cache` | Laravel infrastructure for secure cookie auth, recovery, asynchronous reliability and cache. |

Indexes target public listing eligibility/location/price/dates, role/status, conversation participants/timestamps, review moderation, notification read time, search mode/date and audit target/actor. Framework timestamps are stored consistently and displayed in Asia/Colombo.
