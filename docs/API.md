# API contracts

Application base path: `/api/v1`. Browser writes require a Sanctum session and CSRF cookie. Collections use Laravel pagination (`data`, `links`, `meta`). Validation uses HTTP 422; authorization 403; unauthenticated 401; conflict/state errors 409 where applicable.

## Application API

| Area | Methods and routes |
|---|---|
| Health/auth | `GET health`, `GET auth/me`, `POST auth/register|login|logout|forgot-password|reset-password` |
| Public | `GET meta`, `GET listings`, `GET listings/featured`, `GET listings/{id}`, `GET search`, `GET destinations`, `GET proximity`, `POST assistant/chat` |
| Account | `PUT profile`, `PUT account/password`, `DELETE account` |
| Tenant | `GET recommendations|favorites|my-reviews|saved-searches`; `PUT/DELETE favorites/{listing}`; `POST reviews`; `DELETE reviews/{review}`; `POST reviews/{review}/report`; `POST listings/{listing}/report`; saved-search create/delete |
| Messaging | `GET conversations`; `POST conversations`; `GET/POST conversations/{id}/messages`; `POST conversations/{id}/read|archive` |
| Notifications | `GET notifications`; `POST notifications/{id}/read`; `POST notifications/read-all`; `DELETE notifications/{id}` |
| Owner | `GET/POST owner/listings`; `PUT owner/listings/{id}`; `POST owner/listings/{id}/submit|deactivate`; `GET owner/listings/{id}/history`; image upload/delete; `GET owner/reviews` |
| Admin | dashboard; listing queue and `approve|reject|suspend|restore`; owner verify; user status; review `hide|restore`; notification composer; audit logs |

Search keys: `q`, `city`, `type`, `gender`, `facility`, `minPrice`, `maxPrice`, `minRating`, `occupancy`, `furnished`, `sort`, `page`, `perPage`. Sort is one of `relevance`, `newest`, `price_asc`, `price_desc`, `rating`. Pagination is bounded at 24 public/50 admin rows.

Proximity keys: `destination` (branch name or supported alias), `radiusKm` (1–50), optional `maxPrice` and `facility`. Results are published/available listings ordered by Haversine `distanceKm`, with disclosed `commuteOptions`, route method and sourced public `nearbyPlaces`. A generic multi-branch organization such as `ICBT Campus` returns HTTP 422 with `error.code=ambiguous_destination` and branch suggestions; `POST assistant/chat` returns the same choices conversationally. OSRM routing is optional; offline estimates are explicitly labelled and are not live traffic.

Assistant responses include structured `understanding`, confidence/language, hard/preferred/excluded requirements, match breakdowns, `relaxationAnalysis`, model/search metadata and a `searchLogId`. Authenticated tenants can post privacy-safe outcomes to `POST /api/v1/ai/feedback` and explicitly donate an anonymized search through `POST /api/v1/ai/evaluation-samples`. Profile learning is opt-in/resettable. Administrator AI routes expose metrics, risk assessments, the human annotation queue and a numeric-only feedback training export.

## Internal Flask API

All routes except `/health` require `X-Internal-Secret`; `X-Correlation-ID` is echoed. JSON bodies are capped at 1 MB.

| Method | Route | Contract |
|---|---|---|
| GET | `/health` | service/model/index readiness, versions and index size |
| GET | `/v1/models` | loaded model metadata |
| POST | `/v1/search` | `{query, listings[], limit}` → ranked IDs, constraints, version/mode |
| POST | `/v1/query/understand` | `{text}` → trained intent labels and calibrated confidence |
| POST | `/v1/recommendations` | `{preference, listings[], limit}` → ranked IDs |
| POST | `/v1/reviews/analyze` | `{text}` → label, confidence, aspects, model version |
| POST | `/v1/reviews/summarize` | `{reviews[]}` → safe summary, sample size and supporting aspects |
| POST | `/v1/index/upsert` | `{id,text}` → idempotent index status |
| POST | `/v1/index/delete` | `{id}` → delete status |
| POST | `/v1/index/rebuild` | `{listings[]}` → version-bound index status |

Errors use `{error:{code,message,correlationId}}`. Laravel uses short timeouts and only retries queued idempotent operations.
