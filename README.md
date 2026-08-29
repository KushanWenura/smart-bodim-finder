# BodimBuddy.lk

Smart Sri Lankan boarding-room discovery platform with transparent AI matching, area insights and a safe visit-to-rental workflow.

## Main features

- Search verified bodims by campus or workplace branch, distance, budget and facilities.
- Buddy AI understands English, Sinhala, Tamil and common mixed-language requests.
- Exact-match filtering is applied before listings are ranked with clear suitability reasons.
- Compare listings, save favourites and searches, receive alerts and message owners.
- View commute estimates, nearby essentials and evidence-aware area safety insights.
- Arrange property visits before requesting rental dates.
- Protected reservation holds, availability calendars and downloadable rental agreements.
- AI review summaries with an option to reveal the original resident reviews.
- Owner tools for creating, editing and managing listings, visits and reservations.
- Administrator tools for listing moderation, owner verification, reports and system health.

## Technology

- **Frontend:** React 19, TypeScript, Vite, Bootstrap and TanStack Query.
- **Backend:** Laravel 13 REST API, Sanctum authentication, queues and scheduler.
- **AI service:** Local Flask service with trained semantic-search and intent models.
- **Database:** SQLite for zero-configuration assessment; MySQL is also supported.
- **Maps:** Leaflet and OpenStreetMap with optional OSRM-compatible routing.

The browser communicates with Laravel. Laravel enforces eligibility and permissions, then sends only eligible listing candidates to the internal AI service for ranking.

## Requirements

- Windows 10 or 11
- PHP 8.3+ or WAMP
- Node.js 22+ and pnpm
- Python 3.12+

## Start the complete system

Open PowerShell in the project root:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\Run\start-all.ps1
```

The first run installs missing dependencies, creates and seeds SQLite, rebuilds the AI index and starts Laravel, the queue worker, scheduler, AI service and frontend.

Open: `http://127.0.0.1:5173`

Service endpoints:

- Website: `http://127.0.0.1:5173`
- Laravel health: `http://127.0.0.1:8000/api/v1/health`
- AI health: `http://127.0.0.1:5100/health`

Stop the launcher-managed services:

```powershell
.\Run\stop-all.ps1
```

Create a local database and upload backup:

```powershell
.\Run\backup-project.ps1
```

## Local assessment accounts

| Role          | Email                  | Password     |
| ------------- | ---------------------- | ------------ |
| Tenant        | `tenant@smartbodim.lk` | `Tenant@123` |
| Owner         | `owner@smartbodim.lk`  | `Owner@123`  |
| Administrator | `admin@smartbodim.lk`  | `Admin@123`  |

These accounts and all included listings are synthetic assessment data. Do not use these credentials in production.

## Verification

After the first startup has installed the dependencies:

```powershell
# Backend
cd backend
php artisan test

# Frontend
cd ..\frontend
pnpm run lint
pnpm test
pnpm run build

# AI service
cd ..
ai-service\.venv312\Scripts\python -m pytest ai-service\tests -q
```

## Project structure

- `frontend/` — public website and tenant, owner and administrator workspaces.
- `backend/` — Laravel API, migrations, seeders, policies, jobs and tests.
- `ai-service/` — local AI API, ranking logic, training tools and tests.
- `models/` — submitted trained model artifacts.
- `datasets/` — licensed/synthetic training and evaluation data.
- `Run/` — Windows start, stop and backup scripts.
