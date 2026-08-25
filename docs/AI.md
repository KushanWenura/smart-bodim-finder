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

## Buddy AI chatbot request flow

The floating assistant sends natural-language requests to Laravel's `POST /api/v1/assistant/chat` endpoint. Laravel interprets budget ranges and `35k` shorthand, occupancy, furnished state, gender, property type, facilities and common aliases (`AC`, `A/C`, `car park`, `Wi-Fi`), Sri Lankan place names, campuses, workplaces, radius phrases and nearby-place priorities. Multi-branch institutions are resolved by organization, branch and aliases. A generic request such as `near ICBT Campus` returns branch-choice buttons; selecting a branch preserves the original constraints, and only an explicit branch such as `ICBT Kandy` is assigned coordinates. The assistant retains at most three previous user messages for explicit refinements such as `make it cheaper` or `only within 5 km`; it does not maintain an unrestricted transcript. Laravel then calculates Haversine distance before sending only eligible public listing text to the Flask ranker. Results can prioritize nine mapped essentials: bus stops, railway stations, supermarkets, hospitals, food, pharmacies, banks/ATMs, police stations and laundry. If Flask is unavailable, structured and keyword fallback still return safe eligible results with a visible warning.

Chatbot ranking is deliberately two-stage. First, publication, availability, budget, occupancy, furnished state, gender, property type, requested facilities and destination radius are hard eligibility rules; a listing that misses one is not shown as an exact match. Second, eligible listings receive a 1–99 suitability score combining semantic relevance, distance fit, resident rating, budget value, verified ownership, requested nearby-place proximity and completeness of nearby essentials. The API returns `matchRank`, `matchScore`, `matchLabel`, `matchedRequirements`, `matchReasons` and safe follow-up prompts, which the interface displays as numbered Best Match/Top Match cards. This score is comparative and explainable, not a probability or guarantee.

This design deliberately keeps authorization and hard filters in Laravel. The AI service ranks candidate IDs only; it cannot reveal private addresses or reintroduce unpublished listings.

## Query Understanding v2

Laravel extracts typed slots with evidence and confidence for English, Sinhala, Tamil and common code-mixed/Singlish wording. Facilities, property type, furnishing and budget can be `hard`, `preferred` or `excluded`. Hard constraints determine eligibility; preferences only affect ordering; exclusions are enforced before ranking. An ambiguous branch or missing destination produces a clarification instead of a guessed coordinate. Zero-result analysis counts which single constraint blocks otherwise eligible listings and offers controlled, user-approved relaxations.

`models/query-intent-v1` is a locally trained character TF-IDF one-vs-rest classifier over 960 synthetic multilingual examples. It calibrates intent labels returned by Flask; deterministic Laravel validation remains authoritative. Its held-out synthetic F1 verifies reproducibility only. Generate and train it with `generate_query_intent_dataset.py` and `train_intent_model.py`.

## Routes, personalization and responsible learning

Haversine distance remains the reproducible eligibility radius. Results additionally expose walking, driving and public-transport estimates. If `ROUTING_SERVICE_URL` points to an OSRM-compatible deployment, cached road distance/duration is used; otherwise the response explicitly identifies a conservative offline estimate, never live traffic.

Authenticated tenants may opt into learning from favourites, enquiries and explicit helpful/hide feedback. Learning starts only after three signals, cannot override a hard filter and can be disabled or reset in the profile. Numeric score breakdowns—not private messages or addresses—can train `train_feedback_reranker.py` after at least 100 mixed positive/negative, group-separated outcomes. The trainer refuses undersized or single-class data. Listing risk checks flag price outliers, duplicate content/coordinates and identical images for human administrators; they never reject automatically.

Tenants can separately consent to donate an anonymized query for human evaluation. Administrators label corrected intent and ranked relevance. Monitoring reports feedback usefulness, no-result rate, p95 latency, language mix, model version, risk workload and annotation progress.

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
- Multilingual rules and the synthetic intent classifier are implemented, but representative consented language slices are still required before claiming real-world multilingual accuracy.
- Sarcasm, negation, mixed sentiment, short text and class imbalance can cause errors.
- Recommendation popularity/location bias needs offline subgroup analysis and engagement monitoring.
- Summaries describe user opinions, not verified property facts.
- Never embed private addresses, contact details, moderation notes or message bodies.
