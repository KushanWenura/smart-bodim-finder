# AI, datasets, training and evaluation

## Runtime profiles

| Profile | Search | Reviews | Intended use |
|---|---|---|---|
| `fixture` | deterministic TF-IDF cosine | transparent lexicon + uncertain threshold | quick CPU assessment and CI |
| `base` | submitted `smart-bodim-minilm-v1` + FAISS | Hugging Face classifier + aspect templates | complete local trained-model profile; each model falls back independently |
| `production` | fine-tuned artifact + persisted FAISS | fine-tuned DistilBERT artifact | fails startup if configured artifacts are missing |

The submitted search path is `models/smart-bodim-minilm-v1`, fine-tuned from `sentence-transformers/all-MiniLM-L6-v2`; review sentiment defaults to `distilbert-base-uncased-finetuned-sst-2-english`. Models load once at process start. Embeddings are normalized and FAISS uses inner product as cosine similarity. Persistent indexes contain external listing-ID metadata and a model version, while request ranking rebuilds an eligible in-memory FAISS view so hard Laravel filters cannot be bypassed.

## Evidence-based review summary

DistilBERT classifies; it does not generate prose. Every visible review is classified sentence/document-wise, low confidence maps to `uncertain`, and a configurable phrase lexicon finds cleanliness, owner responsiveness, noise, safety, WiFi, food, bathroom, transport, location, price/value and utilities. A deterministic template mentions only aspects present in stored evidence. Fewer than two reviews produce an insufficient-data statement.

## Bodim AI chatbot request flow

The floating assistant sends natural-language requests to Laravel's `POST /api/v1/assistant/chat` endpoint. Laravel interprets budget, gender, property type, facilities and common aliases (`AC`, `A/C`, `car park`, `Wi-Fi`), Sri Lankan place names, campuses, workplaces and radius phrases. Multi-branch institutions are resolved by organization, branch and aliases. A generic request such as `near ICBT Campus` returns branch-choice buttons; selecting a branch preserves the original budget and facility constraints, and only an explicit branch such as `ICBT Kandy` is assigned coordinates. Laravel then calculates Haversine distance before sending only eligible public listing text to the Flask ranker. Results can include nearest bus, railway, supermarket, hospital and food records. If Flask is unavailable, structured and keyword fallback still return safe eligible results with a visible warning.

Chatbot ranking is deliberately two-stage. First, publication, availability, budget, gender, property type, requested facilities and destination radius are hard eligibility rules; a listing that misses one is not shown as an exact match. Second, eligible listings receive a 1–99 suitability score combining semantic relevance, distance fit, resident rating, budget value, verified ownership and completeness of nearby essentials. The API returns `matchRank`, `matchScore`, `matchLabel`, `matchedRequirements` and `matchReasons`, which the interface displays as #1 Best Match and numbered Top Match cards. This score is comparative and explainable, not a probability or guarantee.

This design deliberately keeps authorization and hard filters in Laravel. The AI service ranks candidate IDs only; it cannot reveal private addresses or reintroduce unpublished listings.

## Reproducible pipeline

1. Place only legitimately licensed JSONL in `datasets/raw/` with `id`, source and license metadata.
2. Run `validate_dataset.py` for schema/provenance/label/duplicate checks.
3. Run `prepare_data.py`; it normalizes/deduplicates, keeps every `groupId` in one seeded split and writes a SHA-256 manifest.
4. Fine-tune search with `train_search.py` (cosine triplet margin loss over explicit hard negatives) and sentiment with `train_sentiment.py` (Transformers Trainer).
5. Run `evaluate_models.py`; it writes search MRR and keyword baseline, plus sentiment accuracy, per-class precision/recall/F1 and confusion matrix.
6. Export a model card/run manifest, configure paths/versions, then run `build_index.py` on published/available listing JSON.

Exact commands are in the root README. Each training script records base model, timestamp, random seed, hyperparameters, split sizes, runtime/platform and output. Pin Hugging Face revisions and add dataset checksums for any assessed full run.

## Current measured state

The current search model is trained on 5,376 rows from 7,680 CC0 synthetic domain triples for two epochs with seed 42, batch size 16 and learning rate 2e-5. The group-aware split contains 1,344/288/288 intent groups (5,376/1,152/1,152 rows) with zero overlap. The source catalog contains 152 higher-education destinations across 41 organizations plus 8 workplaces. `models/smart-bodim-minilm-v1/evaluation.json` records the pooled held-out retrieval metrics. These synthetic values verify the controlled nationwide branch-aware task and reproducibility, not production performance or unbiased real-world accuracy.

At runtime, retrieval uses the current database corpus (24 individually written synthetic Sri Lankan listings across 24 areas, with distinct local project images) rather than treating the tiny evaluation fixtures as real accommodation data. No Kaggle source or scraped property photography is bundled or claimed.

## Limitations and responsible use

- English-first; Sinhala, Tamil and transliterated text need representative licensed data and separate evaluation.
- Sarcasm, negation, mixed sentiment, short text and class imbalance can cause errors.
- Recommendation popularity/location bias needs offline subgroup analysis and engagement monitoring.
- Summaries describe user opinions, not verified property facts.
- Never embed private addresses, contact details, moderation notes or message bodies.
