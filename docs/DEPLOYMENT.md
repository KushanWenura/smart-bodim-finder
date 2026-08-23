# Deployment, operations and backups

## Local containers

`docker compose up --build` starts Nginx/React on 8080, Laravel on 8000, MySQL internally/3307 host, a database queue worker and internal Flask. Named volumes persist MySQL, uploads and AI artifacts. Health checks gate dependent services. The optional mail catcher is `docker compose --profile mail up --build` and appears on 8025 after configuring Laravel SMTP to `mailpit:1025`.

Docker was not installed on the verification workstation, so container construction could not be executed there; the same application was executed manually against WAMP PHP 8.3 and MySQL 9.1. Validate `docker compose config`, build and health checks on the assessment/deployment host.

## Production layout

- Terminate HTTPS at a maintained reverse proxy/load balancer; serve immutable frontend assets with cache headers.
- Run Laravel behind PHP-FPM/Octane or an appropriate application server, not `artisan serve`; run supervised queue workers and scheduler separately.
- Keep MySQL and Flask private. Use a strong unique internal AI secret and separate least-privilege database user.
- Build/pin dependencies and base images; do not mount source code. Store Laravel key/secrets outside images.
- Use persistent object/local upload storage and persistent versioned model/index artifacts. Build a new FAISS snapshot before atomically changing active version.
- Apply migrations in a controlled release step, then restart workers. Do not automatically seed production.

## Health and recovery commands

```powershell
php artisan queue:failed
php artisan queue:retry all
php artisan queue:work --tries=3 --timeout=90
curl http://127.0.0.1:8000/api/v1/health
curl http://127.0.0.1:5100/health
```

Index errors remain in `ai_index_records`; review errors remain in `review_ai_analyses` and failed jobs. Publication/review data stays committed in MySQL.

## MySQL backups

Use `scripts/backup-mysql.ps1` from a secured scheduler. It reads credentials from environment variables, writes a dated compressed dump outside the web root, and should be protected/encrypted by the host backup system. Keep no real-person dump in Git. Example restore rehearsal:

```powershell
gzip -dc .\backups\smart_bodim_YYYYMMDD_HHMMSS.sql.gz | mysql -h 127.0.0.1 -u smart_bodim -p smart_bodim_restore_test
```

Define daily/weekly/monthly retention, alert on missing/empty output, encrypt offsite copies, restrict access and test a restore at least quarterly.

## Release checklist

- Secrets rotated; debug/demo seeding disabled; administrator created securely.
- HTTPS/cookies/CORS/CSP/trusted proxies configured.
- Migrations, uploads, queue, mail, scheduler, logs and backup restore verified.
- Full licensed model metrics/model cards approved; index version matches embedding version.
- Unit/feature/API/E2E/accessibility/security tests pass against production-like infrastructure.
