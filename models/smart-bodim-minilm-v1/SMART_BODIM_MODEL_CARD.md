# Smart Bodim MiniLM v1

## Purpose

Fine-tuned semantic retrieval model for Sri Lankan boarding-accommodation queries involving campuses, workplaces, budgets, facilities, accommodation rules and nearby essentials.

## Base model and adaptation

- Base: `sentence-transformers/all-MiniLM-L6-v2` (22.7M parameters, 384-dimensional embeddings).
- Loss: `MultipleNegativesRankingLoss`.
- Domain data: 48 CC0 synthetic Smart Bodim query/relevant/irrelevant triples.
- Deterministic split: 34 train, 7 validation, 7 test; seed 42.
- Training: 2 epochs, batch size 8, learning rate 2e-5, CPU.
- The saved `model.safetensors` contains the fine-tuned weights; this is not an untouched downloaded model.

## Recorded evaluation

`evaluation.json` uses seven held-out queries and a pooled 14-document corpus. Fine-tuned and pretrained MiniLM both achieved MRR/Recall@1 of 1.0; the keyword baseline achieved MRR 0.8571 and Recall@1 0.7143. The small synthetic set demonstrates training and evaluation plumbing, not real-world superiority.

## Responsible-use limitations

English-first synthetic data cannot establish Sinhala/Tamil/transliterated accuracy, production bias, live route time, safety or property truth. Laravel enforces eligibility/privacy separately, and the system exposes deterministic fallback when this model is unavailable.
