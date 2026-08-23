# Entity relationship diagram

```mermaid
erDiagram
  USERS ||--o| TENANT_PROFILES : has
  USERS ||--o| OWNER_PROFILES : has
  USERS ||--o{ LISTINGS : owns
  LOCATIONS ||--o{ LISTINGS : classifies
  LISTINGS }o--o{ FACILITIES : includes
  LISTINGS ||--o{ LISTING_IMAGES : has
  LISTINGS ||--o{ LISTING_NEARBY_PLACES : has
  LISTINGS ||--o{ LISTING_STATUS_HISTORY : records
  USERS }o--o{ LISTINGS : favorites
  USERS ||--o{ SAVED_SEARCHES : saves
  LISTINGS ||--o{ CONVERSATIONS : scopes
  USERS ||--o{ CONVERSATIONS : tenant
  USERS ||--o{ CONVERSATIONS : owner
  CONVERSATIONS ||--o{ MESSAGES : contains
  USERS ||--o{ MESSAGES : sends
  LISTINGS ||--o{ REVIEWS : receives
  USERS ||--o{ REVIEWS : authors
  REVIEWS ||--o| REVIEW_AI_ANALYSES : analyzed
  REVIEWS ||--o{ REVIEW_REPORTS : reported
  LISTINGS ||--o{ LISTING_REPORTS : reported
  LISTINGS ||--o| LISTING_REVIEW_SUMMARIES : summarizes
  USERS ||--o{ NOTIFICATIONS : receives
  USERS ||--o{ SEARCH_LOGS : performs
  LISTINGS ||--o{ ANALYTICS_EVENTS : relates
  AI_MODEL_VERSIONS ||--o{ AI_INDEX_RECORDS : versions
  LISTINGS ||--o{ AI_INDEX_RECORDS : indexed
  USERS ||--o{ ADMIN_AUDIT_LOGS : acts
```

Foreign-key deletion behavior is deliberate: profile/favorite/message-support tables cascade where the parent cannot sensibly exist; audit actors and conversation/listing relationships restrict destructive deletion; user/listing records use soft deletion where historical accountability matters.
