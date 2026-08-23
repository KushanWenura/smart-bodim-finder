# Semantic search model card

- Intended artifact: fine-tuned `sentence-transformers/all-MiniLM-L6-v2`.
- Current runnable state: `fixture` deterministic TF-IDF; configurable `base` adapter; no weights committed.
- Task: rank already-eligible public boarding listings for an English accommodation query/preferences document.
- Index: normalized embeddings, FAISS `IndexFlatIP`, listing-ID metadata and exact model-version binding.
- Training input: licensed query/positive/negative accommodation pairs; private address/contact/message text prohibited.
- Required metrics: held-out MRR, keyword baseline MRR, latency and subgroup/error analysis.
- Fixture result: MRR 1.0 on three synthetic hand-aligned queries vs stated baseline 0.7778; not generalizable.
- Limitations: English/domain coverage, Sinhala/Tamil/transliteration, geographic/popularity bias, sparse facility synonyms and adversarial text.
- Human control: hard filters and publication/availability are enforced in Laravel; ranking never publishes or verifies a listing.
