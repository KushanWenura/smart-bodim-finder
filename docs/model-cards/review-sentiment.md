# Review sentiment and aspect model card

- Intended artifact: accommodation-review fine-tune of `distilbert-base-uncased-finetuned-sst-2-english`.
- Current runnable state: transparent fixture lexicon; configurable Hugging Face base adapter; no weights committed.
- Task: positive/negative classification with documented low-confidence `uncertain`, plus deterministic evidence phrase extraction.
- Summary: safe template only; the classifier is never represented as a generative summarizer.
- Training input: licensed accommodation reviews with source/license/checksum; no personal data.
- Required metrics: held-out per-class precision/recall/F1, accuracy, confusion matrix, calibration/threshold and error examples.
- Fixture result: perfect labels on five synthetic obvious examples; only a pipeline smoke test.
- Limitations: sarcasm, negation, mixed aspects, class imbalance, very short reviews, Sinhala/Tamil/transliterated language and domain shift.
- Human control: reviews remain visible domain records if AI is down; administrators—not AI—moderate content. Summaries are explicitly user-opinion aggregates.
