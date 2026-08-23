# Master Build Prompt — Smart Bodim Finder with AI Assistant

Copy everything below this line into a capable coding agent. Treat this prompt as the authoritative implementation specification.

---

## 1. Role and Mission

You are a senior full-stack architect, UX engineer, PHP engineer, Python/ML engineer, database designer, security engineer, QA engineer, and technical writer.

Build a complete, locally runnable, academically demonstrable production-style web application named **Smart Bodim Finder with AI Assistant** for the Sri Lankan bodim/boarding accommodation market.

The platform connects:

1. **Tenants** — students and working individuals searching for rooms, annexes, boarding places, or small rental houses.
2. **Property owners** — landlords/property managers who submit and manage listings.
3. **Administrators** — platform moderators who verify owners, approve/reject listings, moderate reviews, manage users, send notifications, and view analytics.

This is not a static prototype. Implement real authentication, authorization, database persistence, image uploads, search, filters, maps, messaging, reviews, notifications, moderation workflows, analytics, AI inference, AI training/evaluation scripts, tests, documentation, and realistic seed data.

Do not stop after scaffolding. Continue until all required workflows work end-to-end and the acceptance criteria pass.

## 2. Non-Negotiable Product Decisions

- Use only free and open-source application libraries and locally hosted AI models.
- Do not call OpenAI, Gemini, Claude, or any paid external AI API.
- Do not add a payment gateway, subscription, checkout, or listing fee.
- Listing publication must use administrator approval instead of payment.
- The AI models must be downloadable from Hugging Face, fine-tunable locally, evaluable, versioned, and served by the Python AI service.
- The system must remain usable when the AI service is temporarily unavailable. Show a clear warning and fall back to structured filters/keyword search where possible.
- The public browser application must never call the Python AI service directly. The PHP backend is the only application allowed to call it.
- Never expose database credentials, AI service secrets, map keys, or private configuration in the browser bundle or repository.
- Build a responsive web application, not a native mobile app.
- English is the primary UI language. Store content as UTF-8 and design the data model so Sinhala/Tamil localization can be added later.
- Use Sri Lankan conventions: LKR currency, Asia/Colombo timezone, local phone validation, and Sri Lankan cities/institutions in demo data.

## 3. Technology Architecture

### 3.1 Recommended implementation stack

Use this stack unless the existing repository already contains a coherent, working alternative:

- **Frontend:** React with Vite and TypeScript, Tailwind CSS, React Router, TanStack Query, React Hook Form, and Zod.
- **Main backend:** PHP with Laravel, REST-style JSON API, server-side validation, Eloquent ORM, queues/events where useful, and secure cookie/session authentication.
- **Database:** MySQL with migrations, foreign keys, indexes, seeders, and transactions.
- **AI service:** Python with Flask, Hugging Face Transformers, sentence-transformers, PyTorch, FAISS, pandas, scikit-learn, and pytest.
- **Maps:** a provider abstraction. Support Google Maps JavaScript API when a key is configured. Provide Leaflet/OpenStreetMap as the no-key local-development fallback.
- **Local development:** Docker Compose is preferred, with clear Windows-friendly manual setup instructions as a fallback.
- **Testing:** frontend unit/component tests, Laravel unit/feature tests, Python unit/API tests, and at least one browser-level end-to-end test for each core role.

### 3.2 Framework freedom rule

The original frontend list of HTML5, CSS3, Tailwind CSS, and vanilla JavaScript is not a restriction. A suitable frontend framework may be used. React/TypeScript is recommended for maintainability. If another framework is already established, preserve it only if it supports the same features, security, responsiveness, accessibility, and tests.

HTML5 and CSS3 remain the browser foundation. Tailwind may be combined with accessible headless UI components. Do not introduce a large visual component library that makes the interface look generic or creates conflicting styling systems.

### 3.3 Required logical tiers

Maintain four clear tiers:

1. Presentation layer — public, tenant, owner, and admin interfaces.
2. PHP application/API layer — authentication, business rules, CRUD, moderation, messaging, notifications, analytics, map-provider orchestration, and AI-service orchestration.
3. Python AI layer — model loading, embeddings, FAISS retrieval, sentiment/aspect analysis, training, evaluation, and index maintenance.
4. MySQL data layer — authoritative relational application data.

Keep the AI service independently testable. The PHP application must treat it as an internal service behind a typed client with timeouts, retries only where safe, health checks, and graceful fallback behavior.

## 4. Repository and Deliverable Structure

Use a clean monorepo structure similar to:

```text
smart-bodim-finder/
  frontend/
  backend/
  ai-service/
  database/
  docs/
  datasets/
    raw/.gitkeep
    processed/.gitkeep
    README.md
  models/.gitkeep
  storage/.gitkeep
  docker-compose.yml
  .env.example
  README.md
```

Do not commit large model weights, private datasets, credentials, generated uploads, virtual environments, node_modules, or build output. Provide reproducible download/training commands and checksums/metadata instead.

Required documentation:

- Root setup and usage README.
- Architecture overview and request-flow diagram.
- ER diagram and data dictionary.
- API documentation for the PHP API and internal AI API.
- Role/permission matrix.
- AI dataset documentation and model cards.
- Training and evaluation guide.
- Test plan and test results template.
- Deployment guide.
- Security notes and known limitations.

## 5. User Roles and Authorization

### 5.1 Guest

- View the home page, public listings, listing details, maps, ratings, reviews, and AI review summaries.
- Run natural-language search and structured filters.
- Register or log in.
- Be prompted to log in before favoriting, messaging, reviewing, saving a search, or using personalized recommendations.

### 5.2 Tenant

- All guest capabilities.
- Maintain a tenant profile and accommodation preferences.
- Save/remove favorites and compare selected listings.
- Save searches/preferences and receive matching-listing notifications.
- Send messages to owners and manage conversations.
- Submit/update/delete their own rating and review, subject to moderation rules.
- View notifications and mark them read.
- Receive personalized AI recommendations.
- Manage account, password, and profile.

### 5.3 Owner

- Maintain an owner profile and verification information.
- Create listing drafts using a multi-step wizard.
- Upload, reorder, caption, and remove up to ten listing images.
- Submit a listing for administrator review.
- View rejection feedback and resubmit corrected listings.
- Edit drafts/rejected listings freely.
- Request changes to a published listing; material changes must return to review before publication.
- Deactivate/archive listings and mark availability/occupancy.
- View listing status/history and owner dashboard metrics.
- Read and reply to tenant enquiries.
- View ratings, reviews, and AI summaries for owned listings.
- Receive approval, rejection, enquiry, and review notifications.
- Owners must not approve their own listings, moderate their own reviews, or review their own properties.

### 5.4 Administrator

- Admin accounts are not publicly registerable. Create them through a secure seeder/CLI/database provisioning workflow.
- Review and verify owner accounts.
- Review pending listings with images, location, facilities, price, owner details, and previous moderation history.
- Approve, reject with mandatory feedback, suspend, unpublish, or archive listings.
- Activate, suspend, restore, or soft-delete tenant and owner accounts.
- Moderate/flag/hide/restore reviews with a reason.
- Search across users, listings, reviews, and conversations metadata without exposing private message bodies unnecessarily.
- Send targeted, role-based, listing-specific, or system-wide notifications.
- View analytics and AI health/model-version information.
- View immutable audit logs of sensitive admin actions.

Implement authorization on the server for every protected action. Hiding a button in the frontend is not authorization.

## 6. Authentication and Account Management

Implement:

- Separate tenant and owner registration paths with a shared users table and role field.
- Secure login/logout using HTTP-only, Secure-in-production, SameSite cookies and CSRF protection.
- Email uniqueness, normalized email, password confirmation, and password-strength rules.
- Password hashing using the framework's secure bcrypt/Argon-compatible password API; never store or log plaintext passwords.
- Forgot-password/reset-password flow. In local development, use a mail log or local mail catcher.
- Session invalidation on logout and password change.
- Rate limiting for login, registration, password reset, search, messaging, reviews, and upload endpoints.
- Account status checks: pending verification, active, suspended, and deleted/archived as applicable.
- Profile editing with both client-side convenience validation and authoritative server-side validation.
- Optional email-verification infrastructure if it can be implemented without blocking local demonstrations.

Tenant profile fields:

- Full name, email, Sri Lankan phone number, avatar optional.
- Student/worker category.
- Institution/workplace.
- Preferred city/area and optional preferred coordinates/radius.
- Minimum and maximum monthly budget in LKR.
- Preferred property type.
- Gender accommodation rule/preference.
- Occupancy/room-sharing preference.
- Required and preferred facilities.
- Short preference description used by AI recommendation.

Owner profile fields:

- Full name/business display name, email, phone, address optional.
- Identity/verification reference fields suitable for an academic demo; never seed real identity numbers.
- Verification status and admin notes.

## 7. Listing Domain and Workflow

### 7.1 Listing types

Support configurable types such as boarding room, shared room, private room, annex, studio, small house, and hostel/boarding house.

### 7.2 Listing fields

Each listing must support:

- Owner ID and unique public slug/reference.
- Title and complete description.
- Property type.
- Monthly price in LKR and optional refundable deposit.
- Pricing notes; no payment processing.
- Address line kept private by default, public area/city/district, latitude, longitude, and map visibility rules.
- Gender rule: any, male only, female only, or configurable equivalent.
- Occupancy limit, current availability, available-from date, room sharing allowed.
- Furnished status and house rules.
- Facilities: WiFi, air conditioning/fan, meals/food, parking, attached bathroom, hot water, laundry, kitchen access, study area, security/CCTV, electricity/water inclusion, and extensible facility records.
- Nearby-place distances: university/institute, bus stop, railway station, hospital, supermarket, or other points of interest.
- Up to ten images with one cover image, alt text/caption, ordering, and safe storage paths.
- Status, moderation feedback, submitted/approved/published/rejected/deactivated timestamps.
- View count, favorite count, average rating, review count, and last indexed timestamp.

### 7.3 State machine

Use an explicit validated state machine:

```text
draft -> pending_review -> approved -> published
draft -> pending_review -> rejected -> draft/resubmitted
published -> change_pending -> published or rejected_changes
published -> deactivated/archived
published -> suspended by admin
```

An approved listing may be published immediately as one transaction. Only published, available, non-suspended listings appear in public search or the FAISS index.

Record status changes in a listing-status history/audit table with actor, previous status, new status, reason, and timestamp.

### 7.4 Image upload security

- Accept only validated JPEG and PNG; WebP may be accepted if consistently supported.
- Validate MIME type and decoded image content, not only the filename extension.
- Apply a configurable size limit, generate safe unique filenames, prevent path traversal, remove unsafe metadata where practical, and generate optimized thumbnails.
- Never execute uploaded files.
- Enforce the ten-image limit on both client and server.
- Remove orphan files safely when a listing/image is deleted.

## 8. Public Search, Browse, Map, and Comparison

### 8.1 Home page

Include:

- Clear value proposition for finding verified bodim accommodation in Sri Lanka.
- Prominent natural-language search bar with example queries.
- Popular cities/areas.
- Featured/recent verified listings.
- Explanation of verified listings and AI-assisted search.
- Calls to action for tenants and owners.

### 8.2 Search modes

Support:

1. Natural-language semantic search.
2. Traditional keyword fallback.
3. Structured filters.
4. Sort options.
5. Map/list synchronized results when the map provider is configured.

Structured filters:

- City/district/area.
- Price range.
- Property type.
- Gender rule.
- Occupancy and sharing.
- Available-from date.
- Facilities with AND/OR semantics made clear.
- Furnished status.
- Minimum rating.
- Distance/radius from a chosen institution or point.

Sort options:

- AI relevance.
- Newest.
- Price low-to-high/high-to-low.
- Rating.
- Distance.

Search results must be paginated or use accessible incremental loading. Preserve filters in the URL. Show active-filter chips and a clear-all action. Empty, loading, error, AI-offline, and no-results states must be designed explicitly.

### 8.3 Listing cards and details

Cards show cover image, title, public area, price/month, key facilities, gender rule where relevant, rating/review count, verification badge, favorite control, and availability.

Detail pages show image gallery, full description, facilities, rules, map, approximate/public location, nearby-place distances, owner summary/contact action, rating distribution, AI review summary, individual reviews, related/recommended listings, and report-listing action.

Do not expose a precise private address to anonymous users unless explicitly configured by the owner/admin.

### 8.4 Favorites and comparison

- Add/remove favorites idempotently.
- Provide a tenant wishlist page.
- Allow comparing up to four listings by price, property type, location, gender rule, occupancy, facilities, rating, distance, and availability.
- Persist favorites in MySQL, not only local storage.

## 9. Messaging

Implement stored, in-platform tenant-owner messaging:

- A conversation is normally scoped to a tenant, owner, and listing.
- A tenant can start an enquiry from a published listing.
- Owner and tenant can reply, view chronological messages, and see unread counts.
- Prevent arbitrary users from reading or posting to conversations they do not participate in.
- Validate length, sanitize display, rate limit spam, and prevent stored XSS.
- Support marking messages/conversations read and archiving a conversation.
- Create notifications for new messages.
- Do not require real-time WebSockets; polling is acceptable for the academic scope. If WebSockets are added, retain a reliable non-realtime fallback.

## 10. Ratings, Reviews, Moderation, and AI Summary

- Only authenticated tenants may post reviews.
- One active review per tenant per listing; allow editing their own review with updated timestamps.
- Rating is an integer from 1 to 5 and review text has sensible minimum/maximum lengths.
- Prevent owners from reviewing their own listings.
- Store moderation status: visible, flagged, hidden, removed.
- Recalculate average rating and distribution safely after create/update/delete/moderation.
- Admin actions require reasons and audit logs.
- Provide reporting/flagging for inappropriate reviews.
- Trigger or queue AI sentiment analysis after review changes.
- Display the aggregate AI summary above the review list with sample size and a clear label such as “AI review summary.”
- If there are too few visible reviews, display a neutral insufficient-data message instead of fabricating a conclusion.
- Never present the AI summary as verified fact. It summarizes user opinions.

## 11. Notifications and Saved Preferences

Notification types:

- Owner account verified/rejected/suspended.
- Listing submitted, approved, rejected, published, suspended, or deactivated.
- New message.
- New review on an owner's listing.
- New listing matching a tenant's saved preferences/search.
- Admin-targeted and system-wide announcements.

Required behavior:

- Store notifications in MySQL.
- Unread/read status, created timestamp, type, safe message, and related-resource link.
- List, paginate, mark one read, mark all read, and delete/archive where appropriate.
- Do not allow arbitrary URLs in notification links.
- Saved searches store structured filters plus optional natural-language query.
- When a listing becomes published, evaluate matching saved preferences and create deduplicated notifications.

## 12. Administrator Portal

### 12.1 Dashboard

Show meaningful, query-backed metrics:

- Total/active/suspended users by role.
- Owner verification queue.
- Listings by status and pending approvals.
- Published/available listings.
- Review counts and flagged reviews.
- Message/conversation counts without exposing content.
- Search volume and top search queries.
- Popular areas and property types.
- Top-rated and most-favorited listings with minimum sample thresholds.
- Search-to-detail, detail-to-contact, and search-to-contact conversion indicators where event data exists.
- AI service health, loaded model versions, FAISS index size, and last index update.

Charts must have accessible labels and table/text alternatives.

### 12.2 Approval queue

- Filter/sort by submission date, city, owner, price, and status.
- Review complete listing data and images.
- See owner verification status and prior rejection/history.
- Approve or reject; rejection requires actionable feedback.
- Approval transaction publishes the listing, records audit history, indexes it in FAISS, and notifies the owner.
- If indexing fails, retain the published listing in MySQL, mark AI index status as pending/error, log the problem, and allow retry without duplicate vectors.

### 12.3 User/review/listing administration

- Server-side searchable tables with pagination.
- Confirmation dialogs for material actions.
- Prefer suspension/soft deletion over irreversible deletion.
- Prevent an administrator from accidentally suspending/deleting their own active account.
- Record admin actor, reason, target, timestamp, and before/after state where appropriate.

### 12.4 Notification composer

- Target all users, one role, selected users, or relevant users for a listing.
- Validate title/body lengths.
- Preview recipient count.
- Prevent HTML/script injection.

## 13. AI Feature 1 — Semantic Search and Recommendation

### 13.1 Base model and objective

Use `sentence-transformers/all-MiniLM-L6-v2` as the default English base model. Keep the model name configurable. Document that a multilingual sentence-transformer can be substituted later for full Sinhala/Tamil support.

Fine-tune on:

- Public accommodation listing/query data from appropriately licensed Kaggle datasets.
- Locally collected or ethically created Sri Lankan bodim data.
- Query-positive-listing pairs and hard/soft negatives.
- Sri Lankan place names, institutions, price expressions, facilities, gender/occupancy language, and transliterated local phrases where legitimate data exists.

Do not silently scrape sites or commit data that violates terms/licensing. Include dataset source, license, transformations, and split documentation.

### 13.2 Listing text representation

Build a deterministic canonical text representation containing useful searchable fields, for example:

```text
title; property type; city/area; description; monthly price band;
gender rule; occupancy; facilities; furnishing; house rules;
nearby institutions and transport; availability
```

Do not rely on embedding numeric constraints alone. Extract/enforce price, availability, distance, gender, and facility constraints as structured filters.

### 13.3 Search pipeline

1. Validate and normalize the query.
2. Detect/extract obvious structured constraints where safe: price ceiling/range, area, property type, gender rule, facility terms, and nearby institution.
3. Merge extracted constraints with explicit UI filters, with explicit filters taking precedence.
4. Query MySQL for eligible published/available listing IDs.
5. Encode the natural-language query.
6. Search the FAISS index for a candidate pool larger than the final top-k.
7. Intersect candidates with eligible IDs and apply hard constraints.
8. Optionally combine semantic similarity with distance, rating, recency, and preference fit using documented, configurable weights.
9. Return ranked listing IDs, normalized scores, matched factors, extracted constraints, model version, and latency.
10. Fetch authoritative listing data from MySQL in the PHP backend while preserving AI rank.

Never let the vector index become the authoritative listing database.

### 13.4 Personalized recommendations

- Build preference text from the tenant profile and saved behavior.
- Encode it with the same model and retrieve eligible listings.
- Exclude unavailable, suspended, archived, and optionally already-dismissed listings.
- Allow users to understand why an item is recommended using safe explanations based on actual matched fields, not invented reasons.
- Cold-start fallback: popular/new listings matching structured profile preferences.

### 13.5 FAISS lifecycle

- Store a mapping between FAISS vector IDs and listing IDs/model versions.
- Provide commands/endpoints to build, save, load, validate, rebuild, upsert, and delete/tombstone listing embeddings.
- Index only published eligible listings.
- Reindex after material listing changes.
- Make upsert idempotent.
- Use atomic index-file replacement and file locking where needed.
- Detect model/index version mismatch and refuse unsafe search until rebuilt.
- Expose index size and last update through health/model-info endpoints.

### 13.6 Evaluation

Provide a reproducible evaluation script and sample format:

- Curated test queries with graded or known relevant listing IDs.
- Mean Reciprocal Rank (MRR) as required.
- Also report Recall@K and nDCG@K where feasible.
- Compare fine-tuned semantic search against a keyword/BM25-like baseline.
- Record model, dataset version, split, seed, parameters, metrics, timestamp, and hardware.
- Prevent train/test leakage.

## 14. AI Feature 2 — Review Sentiment and Aspect Summary

### 14.1 Base model

Use `distilbert-base-uncased-finetuned-sst-2-english` as the default configurable base sentiment model and fine-tune it on appropriately licensed accommodation reviews plus legitimate local bodim review data.

The base classifier is positive/negative. Add neutral/uncertain handling through a documented confidence threshold or use a compatible three-class model only if the change is documented and evaluation remains reproducible.

### 14.2 Accurate summary design

DistilBERT is a classifier, not a generative summarizer. Do not pretend it generates prose by itself.

Implement the one-line review summary using:

1. Sentence-level sentiment classification.
2. Confidence scores and uncertain handling.
3. Deterministic aspect extraction using a configurable accommodation lexicon and phrase matching for cleanliness, owner responsiveness, noise, safety, WiFi, food, bathroom, transport, location, price/value, and utilities.
4. Aggregation by visible review count, sentiment, and aspect frequency.
5. Safe template-based natural-language generation.

Example:

> Most reviewers praised cleanliness and owner responsiveness; some mentioned evening noise. Based on 12 reviews.

Do not output a negative/positive aspect unless supported by stored review evidence. Escape all output. Do not expose private model internals or raw training data.

### 14.3 Refresh and storage

- Store per-review predicted label, confidence, model version, analysis timestamp, and extracted aspects.
- Store/cache the per-listing aggregate summary, counts, model version, and generated timestamp.
- Recompute after visible review create/update/delete/moderation.
- Provide an admin/CLI rebuild command for all summaries.
- If the AI service is down, save the review transaction, mark analysis pending, and retry safely later.

### 14.4 Evaluation

- Use train/validation/test splits with no leakage.
- Report precision, recall, F1, accuracy, and confusion matrix.
- Report per-class metrics, not only aggregate accuracy.
- Include error-analysis examples without personal data.
- Document limitations for sarcasm, mixed sentiment, Sinhala/Tamil, transliterated language, short text, and class imbalance.

## 15. Internal AI API Contract

Implement versioned internal endpoints similar to:

```text
GET  /health
GET  /v1/models
POST /v1/search
POST /v1/recommendations
POST /v1/reviews/analyze
POST /v1/reviews/summarize
POST /v1/index/upsert
POST /v1/index/delete
POST /v1/index/rebuild   # protected/admin or CLI-only
```

Requirements:

- JSON request/response schemas with validation.
- Consistent error envelope and request/correlation ID.
- Timeouts and maximum input lengths.
- Bind to localhost/internal Docker network only.
- Authenticate PHP-to-AI requests with a shared internal secret or signed internal header stored in environment variables.
- Never include secrets in logs.
- Log model version, endpoint, latency, success/failure, and safe request metadata, not full sensitive content by default.
- Health endpoint distinguishes service health, model readiness, and index readiness.
- Add contract tests from the PHP client to the Flask API.

## 16. Main PHP API

Design a consistent `/api/v1` API. Exact routes may follow framework conventions, but cover at least:

- Auth: register tenant/owner, login, logout, current user, forgot/reset password.
- Profiles and preferences.
- Public listings, listing detail, filters, facilities, institutions, cities/areas.
- Semantic search and recommendations.
- Owner listing CRUD, image management, submit/resubmit/deactivate.
- Admin owner verification and listing moderation.
- Favorites and comparison data.
- Conversations and messages.
- Reviews, review reports, moderation, and summaries.
- Notifications and saved searches.
- Admin users/listings/reviews/search/analytics/audit logs.
- AI health/status visible only to admins.

API rules:

- Use request validators/form requests.
- Return correct HTTP status codes.
- Use pagination metadata consistently.
- Use API resources/serializers; do not return raw ORM objects.
- Avoid N+1 queries.
- Enforce ownership and role policies.
- Use database transactions for multi-table state changes.
- Use idempotency for favorite toggles, index upserts, and retryable moderation side effects.

## 17. Database Design

Create normalized migrations, indexes, constraints, seeders, and factories for at least:

- `users`
- `tenant_profiles`
- `owner_profiles`
- `institutions`
- `locations` or normalized city/district/area reference data
- `facilities`
- `listings`
- `listing_facilities`
- `listing_images`
- `listing_nearby_places`
- `listing_status_history`
- `favorites`
- `saved_searches`
- `conversations`
- `conversation_participants` if the chosen model needs it
- `messages`
- `reviews`
- `review_reports`
- `review_ai_analyses`
- `listing_review_summaries`
- `notifications`
- `search_logs`
- `analytics_events`
- `ai_index_records`
- `ai_model_versions`
- `admin_audit_logs`

Data integrity requirements:

- Foreign keys and deliberate cascade/restrict behavior.
- Unique favorite per user/listing.
- Unique active review per tenant/listing.
- Unique/controlled conversation scope to reduce duplicates.
- Decimal/integer money representation; never floating-point currency.
- Decimal latitude/longitude with validation.
- Indexed status, role, owner, city/area, price, availability, created/published dates, conversation participants, unread status, and other common query fields.
- Soft deletion where auditability matters.
- UTC timestamps in storage where the framework expects it, displayed in Asia/Colombo.
- Do not duplicate authoritative values unless implementing a documented cache with refresh rules.

Create realistic synthetic seed data for Sri Lankan cities and institutions, demo tenant/owner/admin accounts, at least 20–30 varied listings, reviews, messages, favorites, and notifications. Clearly label credentials as local-development-only and never use real personal identity data.

## 18. Maps and Distance

- Create a map-provider interface so Google Maps and Leaflet/OpenStreetMap can be switched through configuration.
- Listing creation must include a coordinate picker and address/area inputs.
- Listing detail must show the location and nearby points.
- Compute/display distances consistently; label straight-line distance versus travel distance accurately.
- Use Haversine distance locally when route/distance APIs are unavailable.
- Never claim route travel time when only straight-line distance was computed.
- Cache external geocoding/distance results and respect provider terms/quotas.
- Restrict Google Maps keys by domain/API in deployment documentation.
- Provide a fully usable no-key development path.

## 19. UI/UX and Page Inventory

Create a polished, original, mobile-first design appropriate for Sri Lankan students, workers, and small property owners. Avoid a generic admin-template appearance.

Public pages:

- Home.
- Search results with filters and optional map.
- Listing detail.
- Login.
- Tenant registration.
- Owner registration.
- Forgot/reset password.
- About/how it works, safety guidance, privacy, and terms placeholders.
- 403, 404, 500, maintenance/AI-offline states.

Tenant pages:

- Dashboard/recommendations.
- Profile and preferences.
- Favorites/wishlist.
- Listing comparison.
- Saved searches.
- Messages/conversation detail.
- Notifications.
- My reviews.
- Account/security.

Owner pages:

- Dashboard.
- Verification/profile.
- My listings with status tabs.
- Create/edit multi-step listing wizard.
- Listing preview and submission confirmation.
- Listing moderation feedback/history.
- Messages.
- Reviews and AI summary monitoring.
- Notifications.
- Account/security.

Admin pages:

- Dashboard analytics.
- Owner verification queue/detail.
- Listing approval queue/detail.
- All listings.
- Users.
- Reviews/reports.
- Global search.
- Notification composer/history.
- AI service/model/index status.
- Audit log.
- Basic reference-data/settings management where needed.

Design requirements:

- Consistent design tokens for color, typography, radius, shadows, spacing, breakpoints, and states.
- Strong information hierarchy and readable LKR pricing.
- Accessible forms with labels, help text, errors, required indicators, and keyboard navigation.
- WCAG-minded color contrast, focus indicators, semantic landmarks, alt text, skip link, and reduced-motion support.
- Skeleton/loading states without layout shift.
- Toasts for transient success; inline messages for errors requiring action.
- Confirmation for destructive/material actions.
- Empty states that explain the next action.
- Responsive tables should become cards or allow accessible scrolling on small screens.
- Do not use browser alerts as the primary UI.
- Do not use fake data in production paths; seed/demo mode must be explicit.

## 20. Security and Privacy

Implement and test:

- Prepared/parameterized queries through the ORM/query builder.
- Output escaping and safe rich-text policy; plain text is preferred for messages/reviews/descriptions.
- CSRF protection for state-changing browser requests.
- XSS prevention including stored XSS tests.
- Server-side authorization policies and role middleware.
- Session fixation/hijacking protections and secure cookie configuration.
- Rate limiting and sensible lockout/backoff.
- File upload validation and storage isolation.
- Mass-assignment protection.
- Safe error handling: detailed server logs, generic user-facing production errors.
- Environment-based secrets and committed `.env.example` only.
- CORS restricted to known frontend origins.
- AI service network isolation and authentication.
- Validation of IDs, enums, money, dates, coordinates, page size, sort keys, and filter operators.
- Audit logs for admin moderation and account actions.
- Minimal collection/exposure of personal data.
- Private message access restrictions.
- Soft deletion/retention decisions documented.
- Security headers: CSP appropriate to maps/images, HSTS in production, frame restrictions, MIME sniffing protection, and referrer policy.

Add tests for IDOR/broken object authorization, role escalation, SQL injection-like input, stored XSS payloads, CSRF expectations, invalid uploads, and unauthorized admin/owner operations.

## 21. Performance, Reliability, and Observability

- Target semantic search response under two seconds for up to 500 indexed listings on a normal development machine after model warm-up.
- Avoid loading models per request; load once at AI service startup.
- Use pagination and database indexes.
- Optimize thumbnails and lazy-load non-critical images.
- Cache stable reference data and selected aggregates with explicit invalidation.
- Apply timeouts to PHP-to-AI and map-provider calls.
- Use safe retry only for idempotent jobs.
- Do not lose a user's review/listing state change merely because AI analysis/indexing failed.
- Add structured application logs with correlation/request IDs.
- Provide `/health` endpoints for main backend and AI service.
- Include commands to inspect failed jobs and retry AI index/review analysis work.
- Add daily database backup guidance/scripts appropriate for MySQL; do not commit backups containing personal data.

## 22. Analytics

Track privacy-conscious events such as:

- Search performed, sanitized query/filters, result count, latency, AI/fallback mode.
- Listing impression/detail view.
- Favorite added/removed.
- Contact/enquiry started.
- Review submitted.
- Listing submitted/approved/rejected/published.

Do not collect unnecessary private message or password content. Deduplicate noisy events and document metric definitions.

Required derived analytics:

- Search-query trends.
- Searches with zero results.
- Popular areas, facilities, and price bands.
- Top-rated listings with minimum review thresholds.
- Search-to-detail and detail-to-contact conversion.
- Search-to-contact conversion as a proxy for recommendation usefulness.
- AI versus fallback search latency and result engagement where available.

## 23. Testing Strategy

### 23.1 Backend tests

- Auth and role/access policies.
- Tenant/owner registration and account states.
- Listing CRUD, state transitions, moderation, and material-edit reapproval.
- Image count/type/size/security validation.
- Search/filter combinations and pagination.
- Favorites uniqueness/idempotency.
- Conversation participation and message authorization.
- Review constraints, aggregation, reporting, and moderation.
- Notifications and matching-listing deduplication.
- Admin actions and audit logs.
- AI-client timeout/failure/success behavior.
- Database constraints and transactions.

### 23.2 AI tests

- Health and schema validation.
- Canonical listing text generation.
- Query constraint extraction.
- Embedding shape and deterministic configuration.
- FAISS build/load/search/upsert/delete/version mismatch.
- Eligible-ID filtering.
- Review sentence splitting, label thresholding, aspect extraction, and safe template summary.
- Empty/one-review/low-confidence/mixed-review cases.
- Training/evaluation scripts on a small fixture dataset.

### 23.3 Frontend tests

- Form validation and accessible errors.
- Route protection and role navigation.
- Search filter URL synchronization.
- Listing wizard state and image limit.
- Favorites, messaging, reviews, notifications, moderation actions.
- AI-offline and API-error states.
- Keyboard and basic accessibility checks.

### 23.4 End-to-end scenarios

Automate at least:

1. Tenant registers/logs in, searches, filters, favorites a listing, messages an owner, and submits a review.
2. Owner registers, completes profile, creates a listing with images, submits it, receives rejection feedback, edits, and resubmits.
3. Admin verifies owner, reviews/approves listing, moderates a flagged review, and sends a notification.
4. Approved listing becomes searchable and appears in personalized recommendations/FAISS results.
5. AI service failure causes graceful fallback without corrupting application data.

## 24. AI Training and Data Pipeline Deliverables

Provide scripts/commands for:

- Dataset download instructions or import adapters; do not hardcode private Kaggle credentials.
- Schema validation, deduplication, text normalization, license/source metadata, and train/validation/test split.
- Building semantic query/listing training pairs and negatives.
- Fine-tuning the sentence-transformer with configurable seed/hyperparameters.
- Fine-tuning the sentiment model with configurable seed/hyperparameters.
- Evaluating both models and writing machine-readable JSON plus human-readable reports.
- Exporting model artifacts and model cards.
- Building/rebuilding the FAISS index from currently published listings.
- Running a tiny fixture/demo pipeline without a GPU.

Every training run must record:

- Base model and revision if known.
- Dataset versions/sources/licenses.
- Preprocessing version.
- Random seed.
- Hyperparameters.
- Train/validation/test sizes.
- Metrics.
- Hardware/runtime.
- Artifact output path and timestamp.

## 25. Setup, Deployment, and Developer Experience

Provide:

- One-command Docker Compose startup where feasible.
- Separate frontend, PHP backend, MySQL, AI service, and optional local mail service containers.
- Persistent volumes for MySQL, uploads, and model/index artifacts.
- Health checks and dependency readiness.
- `.env.example` files for each service with comments.
- Commands for migrations, seeding, tests, model download, fixture model setup, index build, and production build.
- Manual Windows setup for users preferring XAMPP/WAMP, PHP, Composer, Node, MySQL, and a Python virtual environment.
- Clear ports and base URLs.
- CORS/cookie setup for local development.
- Production checklist covering HTTPS, key restrictions, secrets, storage permissions, queue workers, backups, log rotation, and debug mode off.

The application must have a lightweight demo mode. If full fine-tuned artifacts are unavailable, allow a clearly labeled base-model mode and tiny fixture index so the full workflow can be demonstrated, while preserving commands for actual fine-tuning.

## 26. Implementation Order

Work in verifiable vertical slices:

1. Inspect repository and document assumptions.
2. Establish monorepo, environment examples, Docker/manual setup, and shared conventions.
3. Design ERD, migrations, roles, policies, seeders, and synthetic Sri Lankan demo data.
4. Implement auth and account/profile flows.
5. Implement owner listing wizard, image handling, and admin approval state machine.
6. Implement public browse, filters, listing detail, and maps.
7. Implement favorites, comparison, messaging, reviews, notifications, and saved searches.
8. Implement admin management, moderation, analytics, and audit logs.
9. Implement AI service, base-model demo mode, FAISS lifecycle, sentiment/aspect summary, and PHP client/fallback.
10. Implement training/evaluation/data scripts and model documentation.
11. Finish responsive UI, accessibility, loading/empty/error states.
12. Add unit, integration, contract, security, and end-to-end tests.
13. Run all tests, fix failures, validate fresh setup, and complete documentation.

After each slice, run relevant formatting, linting, type checking, and tests. Do not claim completion for code that has not been executed or verified.

## 27. Definition of Done

The project is done only when:

- A fresh clone can be configured from `.env.example` and started using documented commands.
- Migrations and seeders create a working demo system.
- Guest, tenant, owner, and admin permissions are enforced server-side.
- Owner submission -> admin rejection/approval -> publication works end-to-end.
- Published listings are searchable through structured filters and semantic search.
- AI search returns ranked listing IDs and respects hard filters.
- Personalized recommendations work with a cold-start fallback.
- Review submission triggers stored sentiment/aspect analysis and an evidence-based one-line summary.
- AI failure produces graceful fallbacks and retryable pending states.
- Maps work with the configured provider and a no-key local fallback exists.
- Favorites, comparison, messaging, reviews, notifications, saved searches, moderation, and analytics use real database data.
- Responsive layouts work on mobile, tablet, and desktop.
- Critical accessibility and security requirements are covered.
- All automated tests pass.
- Semantic model evaluation includes MRR and baseline comparison.
- Sentiment evaluation includes precision, recall, F1, and confusion matrix.
- Model cards, dataset documentation, ERD, API docs, setup, deployment, test plan, and limitations are complete.
- No paid AI API or payment gateway has been introduced.
- No credentials, private data, real identity documents, model weights, or prohibited datasets are committed.

## 28. Required Final Handoff from the Coding Agent

At the end, report:

1. What was implemented by module.
2. Final architecture and important design decisions.
3. Exact setup/run commands.
4. Demo accounts and clearly labeled local-only credentials.
5. Test/lint/type-check commands and actual results.
6. AI base/fine-tuned model state, index state, dataset sources, and evaluation results.
7. Any features intentionally deferred, with honest reasons.
8. Known limitations and recommended next improvements.
9. Security/deployment actions required before public release.

If any requirement cannot be implemented, do not silently omit it. Mark it clearly, explain the blocker, preserve a clean extension point, and continue completing all other in-scope work.

