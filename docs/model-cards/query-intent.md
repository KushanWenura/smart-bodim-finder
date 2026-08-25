# Query intent classifier — `query-intent-v1`

## Purpose

Calibrates high-level Buddy request labels such as destination, budget, radius, hard/preferred/excluded facility and language style. It complements, but does not replace, Laravel's typed constraint validator. It cannot query the database or reveal listings.

## Model and training

- Character word-boundary TF-IDF, 3–5 character n-grams, maximum 30,000 features.
- One-vs-rest balanced logistic regression, seed 42.
- 960 CC0 synthetic examples in English, Sinhala, Tamil and Singlish across 160 destination groups.
- Destination groups are held out together: 768 training rows and 192 test rows with zero group overlap.
- Artifact: `models/query-intent-v1/model.joblib`; recorded checksum and metrics: `models/query-intent-v1/evaluation.json`.

The synthetic held-out micro/macro F1 is 1.0 because templates form a controlled intent-recognition task. This is a reproducibility regression score, not a real-user or fairness claim.

## Runtime and safeguards

The model returns labels and confidence through authenticated Flask routes. Laravel independently validates budgets, facilities, branch names, exclusions and authorization. If the artifact or Flask service is unavailable, deterministic multilingual parsing and structured/keyword ranking remain active.

## Required evaluation before production claims

At least 500 explicitly consented, anonymized and independently human-labelled searches, with representative Sinhala, Tamil, code-mixed, district, institution-network, price and gender slices. Report slot F1, branch accuracy, hard-constraint violation rate, calibration and no-result precision/recall. Never train on private messages, exact addresses or contact details.
