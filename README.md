# Smart Bodim Finder with AI Assistant

A complete academic full-stack application for Sri Lankan boarding accommodation. Guests browse verified places; tenants save, compare, message and review; owners manage moderated listings; administrators verify, publish, moderate, notify and audit. It contains no payment gateway and uses no paid AI API.

The primary user journey is the floating **Bodim AI** conversation: a tenant can ask for a room near a campus or workplace, add a distance radius, budget, facility or gender requirement, and receive clickable verified listing cards without exposing private addresses. The dedicated destination finder also ranks results by Haversine distance and shows the nearest bus stop, railway station, supermarket, hospital and food option.

Every public listing includes an **Explore nearby with AI** neighbourhood view. It plots the approximate bodim area and the five nearest essentials on an OpenStreetMap, lists their straight-line distances, and provides filters for bus stops, train stations, Cargills/markets, hospitals and food places. Bodim AI performs semantic property matching; deterministic coordinate calculations produce the displayed nearby distances so the result stays explainable and testable.

## Architecture

- `frontend/` — React 19, Bootstrap 5.3, Bootstrap Icons, Vite, TypeScript, Router, TanStack Query, React Hook Form/Zod and Leaflet/OpenStreetMap.
- `backend/` — Laravel 13 REST API, Sanctum cookie authentication, policies/middleware, Eloquent, queues, uploads, notifications and analytics.
- `ai-service/` — authenticated Flask service with fixture, configurable Hugging Face and FAISS profiles, plus training/evaluation scripts.
- MySQL 8.4 — normalized authoritative data with foreign keys, indexes, transactions and seed data.

The browser calls only Laravel. Laravel applies hard eligibility filters before asking the internal Python service to rank candidate IDs. If Python is unavailable, Laravel returns a visible warning and safe keyword/structured-filter results.

## Quick start with Docker Compose

Requirements: Docker Desktop with Compose.

```powershell
Copy-Item .env.example .env
docker compose up --build
```

Open `http://localhost:8080`. The API is also exposed at `http://localhost:8000`. MySQL is exposed on host port `3307`; the AI service remains internal. The first backend start migrates the database and seeds it only when the users table is empty.

The default Compose profile uses the small deterministic CPU AI fixture so startup does not download model weights. To build the full model image, install `ai-service/requirements.txt`, download/train the documented artifacts, set `AI_PROFILE=base` or `production`, and build the AI Dockerfile with `AI_REQUIREMENTS=requirements.txt`.

## Manual Windows setup (WAMP-compatible)

Requirements: PHP 8.3+, Composer 2, MySQL 8+, Node 22+/pnpm, and Python 3.12.

```powershell
# MySQL: create an empty database first
mysql -uroot -e "CREATE DATABASE smart_bodim_finder CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# Laravel API
Copy-Item backend\.env.mysql.example backend\.env
cd backend
php ..\tools\composer.phar install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

In a second terminal, run the included domain-fine-tuned search model:

```powershell
python -m venv ai-service\.venv312
ai-service\.venv312\Scripts\python -m pip install -r ai-service\requirements.txt
$env:AI_PROFILE='base'
$env:SEARCH_MODEL_PATH='..\models\smart-bodim-minilm-v1'
$env:SEARCH_MODEL_VERSION='smart-bodim-minilm-v1'
$env:AI_INTERNAL_SECRET='change-this-in-every-shared-environment'
ai-service\.venv312\Scripts\python ai-service\app.py
```

The first full-profile start may download the separate pretrained review sentiment classifier. The submitted semantic-search model is already present under `models/smart-bodim-minilm-v1`; it was fine-tuned for this project and is not merely an unchanged downloaded model.

In a third terminal:

```powershell
cd frontend
pnpm install
pnpm dev
```

Open `http://127.0.0.1:5173`. Run a queue worker in a fourth terminal for index, sentiment and database-notification jobs:

```powershell
cd backend
php artisan queue:work --tries=3
```

Windows users can install missing dependencies on the first run and start all four processes in the background with one command:

```powershell
.\Run\start-all.ps1
```

Stop only the processes created by that launcher with `.\Run\stop-all.ps1`. See `Run/README.md` for details.

## Local-only accounts

| Role | Email | Password |
|---|---|---|
| Tenant | `tenant@smartbodim.lk` | `Tenant@123` |
| Owner | `owner@smartbodim.lk` | `Owner@123` |
| Administrator | `admin@smartbodim.lk` | `Admin@123` |

These synthetic credentials are for local assessment only. Never deploy them.

## Verification commands

```powershell
# Laravel formatting, feature/security/contract tests
cd backend
php vendor\bin\pint --test
php artisan test

# React type-check, production build and component tests
cd ..\frontend
pnpm run lint
pnpm run build
pnpm test
# With Laravel, Flask and Vite running on their documented local ports:
pnpm run test:e2e

# Flask API/unit tests and trained-model evaluation
cd ..
ai-service\.venv312\Scripts\python -m pytest ai-service\tests -q
cd ai-service
.venv312\Scripts\python evaluate_search_model.py --model ..\models\smart-bodim-minilm-v1 --test ..\datasets\processed\smart-bodim-v1\search-test.jsonl --output ..\models\smart-bodim-minilm-v1\evaluation.json
```

## Data and model pipeline

```powershell
cd ai-service
.venv\Scripts\python validate_dataset.py ..\datasets\raw\fixture_reviews.jsonl --kind reviews
.venv\Scripts\python prepare_data.py ..\datasets\raw\fixture_reviews.jsonl --kind reviews --out ..\datasets\processed

# Reproduce the submitted semantic-search fine-tune:
.venv312\Scripts\python train_search.py --train ..\datasets\processed\smart-bodim-v1\search-train.jsonl --output ..\models\smart-bodim-minilm-v1 --base-model sentence-transformers/all-MiniLM-L6-v2 --epochs 2 --batch-size 8 --learning-rate 0.00002 --seed 42

# Optional future sentiment fine-tune after adding a representative licensed review dataset:
.venv\Scripts\python train_sentiment.py --train ..\datasets\processed\reviews-train.jsonl --validation ..\datasets\processed\reviews-validation.jsonl --output ..\models\sentiment-v1
```

Private data, credentials, uploads, virtual environments and build output are ignored. The delivery archive intentionally includes the trained search artifact and its model card.

The submitted dataset is intentionally honest and reproducible: 24 synthetic seeded public listings are the live chatbot/search corpus; 48 CC0 domain search triples produce a deterministic 34/7/7 train/validation/test split; and 5 synthetic review samples validate the review pipeline. No Kaggle dataset is bundled or claimed. See `datasets/README.md` for exact provenance and intended use.

## Documentation

- [Architecture and request flow](docs/ARCHITECTURE.md)
- [Entity relationship diagram](docs/ERD.md)
- [API contracts](docs/API.md)
- [Data dictionary](docs/DATA_DICTIONARY.md)
- [Roles and permissions](docs/ROLE_MATRIX.md)
- [AI/training/model status](docs/AI.md)
- [Security](docs/SECURITY.md)
- [Test plan and actual results](docs/TEST_PLAN.md)
- [Deployment and backups](docs/DEPLOYMENT.md)

## Model state and limitations

The submission contains `smart-bodim-minilm-v1`, fine-tuned from `sentence-transformers/all-MiniLM-L6-v2` for two CPU epochs using Multiple Negatives Ranking Loss. On the seven-query synthetic held-out set, it records MRR 1.0 and Recall@1 1.0; the unchanged base model also scores 1.0, while the keyword baseline scores MRR 0.8571. This validates the pipeline but does not prove real-world superiority. Distance is straight-line Haversine and commute time is a disclosed estimate, not live routing. Sinhala/Tamil/transliterated quality requires a larger representative dataset.
