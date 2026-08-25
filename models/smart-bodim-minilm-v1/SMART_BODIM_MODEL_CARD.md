# Smart Bodim MiniLM v1

## Purpose

Fine-tuned semantic retrieval model for Sri Lankan boarding-accommodation queries involving campuses, workplaces, budgets, facilities, accommodation rules and nearby essentials.

## Base model and adaptation

- Base: `sentence-transformers/all-MiniLM-L6-v2` (22.7M parameters, 384-dimensional embeddings).
- Loss: cosine triplet margin loss (`margin=0.20`) with explicit hard negatives.
- Domain data: 1,344 CC0 synthetic Smart Bodim query/relevant/irrelevant triples across 336 grouped intents and 28 destinations.
- Deterministic group-aware split: 944 train, 200 validation, 200 test; seed 42, with zero intent-group overlap.
- Training: 2 epochs, batch size 16, learning rate 2e-5, CPU.
- Branch handling: ten ICBT branches are separate destinations; negatives include a same-brand wrong branch.
- The saved `model.safetensors` contains the fine-tuned weights; this is not an untouched downloaded model.

## Recorded evaluation

`evaluation.json` uses 200 held-out queries and a pooled 100-unique-document corpus. The fine-tuned model achieved MRR 1.0 and Recall@1 1.0; unchanged pretrained MiniLM achieved MRR 0.856167 and Recall@1 0.755; the keyword baseline achieved MRR 0.937917 and Recall@1 0.885. These synthetic results demonstrate the controlled branch-aware task, not real-world production accuracy.

## Responsible-use limitations

English-first synthetic data, including informal Sri Lankan English spellings, cannot establish Sinhala/Tamil-script accuracy, production bias, live route time, safety or property truth. Laravel enforces eligibility/privacy and branch clarification separately, and the system exposes deterministic fallback when this model is unavailable.
