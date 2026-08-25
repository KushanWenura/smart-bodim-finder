---
base_model: sentence-transformers/all-MiniLM-L6-v2
language: en
license: apache-2.0
library_name: sentence-transformers
pipeline_tag: sentence-similarity
tags:
  - sentence-transformers
  - semantic-search
  - sri-lanka
  - accommodation
---

# Smart Bodim MiniLM v1

Smart Bodim MiniLM v1 is the project-owned semantic search model for ranking Sri Lankan boarding-room listings from natural-language requests. It is fine-tuned from `sentence-transformers/all-MiniLM-L6-v2`; it is not an unchanged downloaded model.

## Intended use

The model embeds requests such as:

> Find a WiFi room near ICBT Campus Kandy under Rs. 30,000.

It ranks candidate listing descriptions by semantic fit. Laravel remains responsible for authentication, publication status, branch resolution, price/facility constraints and Haversine distance calculation.

## Training

- Dataset: `datasets/raw/smart_bodim_search_pairs.jsonl`
- Examples: 1,344
- Intent groups: 336
- Destinations: 28, including ten independently labelled ICBT branches
- Split: group-aware 70/15/15 so paraphrases of one intent stay in one split
- Objective: cosine triplet margin loss (`margin=0.20`)
- Optimizer: AdamW
- Epochs: 2
- Positive examples: matching destination, budget and facility needs
- Hard negatives: wrong branch, over-budget and missing-facility listings
- Language styles: formal, conversational, casual Sri Lankan English and typo/noise variants

## Recorded held-out evaluation

| Model | MRR | Recall@1 |
|---|---:|---:|
| Smart Bodim MiniLM v1 | 1.0000 | 1.0000 |
| Base MiniLM | 0.8562 | 0.7550 |
| Keyword baseline | 0.9379 | 0.8850 |

The evaluation contains 200 group-held-out queries over 100 pooled candidate documents. These are controlled synthetic academic results and must not be interpreted as production accuracy on every real user request.

## Usage

```python
from sentence_transformers import SentenceTransformer

model = SentenceTransformer("models/smart-bodim-minilm-v1")
embeddings = model.encode([
    "room near ICBT Kandy with WiFi",
    "verified WiFi boarding room close to ICBT Campus - Kandy",
], normalize_embeddings=True)
similarity = embeddings[0] @ embeddings[1]
print(float(similarity))
```

## Limitations and safeguards

- The training corpus is synthetic and English-first; real Sinhala/Tamil and code-mixed evaluation is future work.
- Branch coordinates are reference points for transparent straight-line estimates, not live route or traffic data.
- The model must not decide listing publication, verification, safety or payment status.
- A generic multi-branch name is clarified by the application before model ranking.
- Users should verify routes, addresses and property claims independently.

See `SMART_BODIM_MODEL_CARD.md`, `training-run.json` and `evaluation.json` for the detailed provenance and recorded artifacts.
