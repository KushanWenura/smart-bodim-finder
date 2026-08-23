# Security and privacy notes

## Implemented controls

- Laravel password hashing and password-strength validation; lower-cased unique email; session regeneration on login, invalidation on logout and other-session logout after password change.
- Sanctum HTTP-only SameSite cookies and CSRF; stateful domains and CORS are environment-restricted. Production must enable secure cookies/HTTPS.
- Server-side active-account, role, ownership and conversation-participant authorization. Admin role cannot be publicly registered and self-suspension is blocked.
- Named throttles for login, registration, recovery, search, messages, reviews and uploads.
- Eloquent/query builder parameter binding and allowlisted enums/sorts/page sizes; SQL-like text is treated as plain input.
- Plain-text React rendering with no `dangerouslySetInnerHTML`; stored-XSS regression test.
- Upload validation checks size, MIME and decoded image content, caps ten files, generates framework-safe random paths/thumbnails and removes files with records.
- Private listing address never appears in the public API resource; message bodies are participant-only and excluded from admin analytics.
- Notifications accept internal relative links only. Flask requires an environment shared secret and has body/input limits, correlation IDs and safe logs.
- Audit/status history for moderation; soft deletion/archive preferred; passwords, secrets and private message text are excluded from analytics.
- `nosniff`, frame denial, referrer/permissions policy; HSTS in production. Nginx adds equivalent edge headers.

## Threat-focused tests

Feature tests cover role escalation, IDOR on conversations, owner/admin separation, favorite uniqueness, admin self-suspension, suspended-session blocking, executable upload rejection, SQL-injection-like input, AI failure integrity and PHP↔Flask contracts. Frontend tests verify role routing and React escaping. Flask tests cover authentication, validation, correlation IDs and summary evidence.

## Before public release

1. Rotate every default credential/secret, remove demo accounts, provision admins through a controlled CLI process and use a secret manager.
2. Set `APP_ENV=production`, `APP_DEBUG=false`, HTTPS-only secure cookies, trusted proxies/hosts and HSTS at the edge.
3. Add a deployment-specific CSP covering only the chosen maps/tiles/images/fonts; restrict any Google key by domain/API. Leaflet tiles must comply with provider policy at production load.
4. Store uploads outside executable paths or in private object storage; malware scan and strip EXIF where required; use signed access for sensitive media.
5. Use distinct least-privilege MySQL/AI identities, private networks/firewalls and encrypted backups; test restore and retention/deletion procedures.
6. Configure mail delivery, queue supervision, log centralization/redaction/rotation, alerting and rate limits at the reverse proxy/WAF.
7. Conduct dependency/SAST/DAST/accessibility review and a manual penetration test. Add consent/privacy/terms reviewed for the deployment jurisdiction.

This is production-shaped academic software, not a completed legal/security certification.
