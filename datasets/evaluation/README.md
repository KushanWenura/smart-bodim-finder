# Human evaluation queue

Production-quality claims require consented, human-labelled queries. The application therefore provides an opt-in **Donate this anonymized query** action for authenticated tenants. Laravel removes phone numbers, email addresses and identity-number patterns before storing a sample in `ai_evaluation_samples`.

An administrator labels the corrected structured intent and orders every genuinely relevant candidate. Do not infer labels from clicks alone. Export only consented, anonymized and labelled rows for research.

Recommended minimum before reporting real-world quality: 500 queries, at least 100 Sinhala or Sinhala-English, 100 Tamil or Tamil-English, branch-name and no-result cases, and independent review of at least 10% of annotations.

Report slot micro/macro F1, destination/branch accuracy, hard-constraint violation rate, Recall@1/3/5, MRR, NDCG@5, no-result precision/recall, calibration error and p50/p95 latency. Slice every result by language, district, institution type, gender constraint and price band.

Synthetic evaluation remains useful for regression testing but must never be described as real-user accuracy.
