# Test plan and recorded results

## Automated suites

| Layer | Command | Verified result on 2026-08-25 |
|---|---|---|
| Laravel feature/security/contract | `php artisan test` | 33 passed, 417 assertions |
| Laravel formatting | `php vendor/bin/pint --test` | passed |
| React type/build | `pnpm run lint && pnpm run build` | type-check/build passed; 234 modules with route code-splitting |
| React component/security | `pnpm test` | 4 files, 7 tests passed |
| Playwright browser workflows | `pnpm run test:e2e` | 6 role/guest/branch-clarification/strict-ranking workflows passed against the live stack in system Edge |
| Flask unit/API/FAISS lifecycle | `python -m pytest ai-service/tests -q` | 16 passed |
| Fine-tuned search evaluation | `python ai-service/evaluate_search_model.py ...` | 1,152-query group-held-out MRR 0.9795/Recall@1 0.9644; base MiniLM MRR 0.6977/Recall@1 0.5625; keyword MRR 0.8234/Recall@1 0.7526 |
| MySQL migration/seed | environment override + `artisan migrate --seed` | 35 tables, 4 users, 24 listings, 5 reviews; final migration verified |

## Covered behavior

- Auth registration/password hashing and public admin-role rejection.
- Role/active-account middleware, admin self-protection and conversation participant IDOR.
- Published listing filters, explicit workflow history/audit and AI-independent publication.
- Favorite add/remove/idempotency and detail-page state; one tenant review/listing and aggregate refresh.
- Invalid executable upload, SQL-like search text and AI failure fallback.
- PHP↔Flask ranking and index lifecycle contract, including campus/workplace interpretation, ordered Haversine results and five categories of nearby essentials.
- Nationwide multi-branch institution ambiguity, constraint-preserving branch choices and exact-branch matching without silently guessing a campus.
- Strict WiFi/AC/car-parking/budget filtering, descending composite suitability scores, numbered ranking labels and user-facing match explanations.
- Conversational refinements preserve short-term context for requests such as “make it cheaper” or “only within 5 km”; occupancy, furnished state and nearby-place priorities remain enforceable requirements.
- Frontend listing price/accessibility, chatbot launcher, role redirect and stored-XSS escaping.
- Browser-level guest search/detail privacy, tenant protected tools, owner portfolio/wizard and admin AI/global-search workflows.
- Flask canonical text, ranking, constraints, sentiment/aspects/summary, shared-secret and error schema.

## Browser acceptance scenarios

The six read-safe core workflows in `frontend/e2e/role-workflows.spec.ts` are automated and verified at a desktop viewport. The following extended acceptance checklist should also be rerun against fresh seed data before a public release:

1. Tenant login → search/filter/map → listing detail → favorite/compare → enquiry/reply → review → notification read.
2. Owner login → profile/verification → four-step listing wizard/images → submit → view pending/history/rejection feedback → resubmit.
3. Admin login → verify owner → reject/approve listing → suspend/restore user/listing → hide/restore review → send announcement → inspect audit/AI status.
4. Confirm approved listing appears in search; run queue and confirm `ai_index_records.status=indexed` in base/production mode.
5. Stop Flask; repeat natural search and review submission, confirm visible fallback and persisted domain transaction.

## Still required for a public deployment

Add mobile-browser and automated axe coverage, MySQL-backed hosted CI, real image/EXIF tests, concurrency/race tests and representative production-model performance/error/bias review. The included local Playwright suite is a repeatable academic acceptance check, not a substitute for production monitoring and release gates.
