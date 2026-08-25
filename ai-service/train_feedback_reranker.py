"""Train a privacy-safe learning-to-rank calibrator from explicit outcomes.

Input JSONL rows must contain `features` (the numeric score breakdown), `label`
(1 for favourite/enquiry/moved-in, 0 for explicit hide/not-helpful), and a
`sessionGroup`. No query text, address, contact details or message bodies are
accepted by this trainer.
"""
from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone
from pathlib import Path

import joblib
import numpy as np
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import average_precision_score, roc_auc_score

FEATURES = ["semantic", "commute", "budgetValue", "residentEvidence", "nearbyEssentials", "preferredFacilities", "personalizationBoost"]


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("input", type=Path)
    parser.add_argument("--output", type=Path, default=Path("models/feedback-reranker-v1"))
    args = parser.parse_args()
    rows = [json.loads(line) for line in args.input.read_text(encoding="utf-8").splitlines() if line.strip()]
    if len(rows) < 100:
        raise SystemExit("At least 100 human interaction rows are required; refusing to fit a misleading model.")
    groups = sorted({str(row["sessionGroup"]) for row in rows})
    cutoff = max(1, int(len(groups) * .8))
    train_groups = set(groups[:cutoff])
    train = [row for row in rows if str(row["sessionGroup"]) in train_groups]
    test = [row for row in rows if str(row["sessionGroup"]) not in train_groups]
    if not test or len({int(row["label"]) for row in train}) < 2 or len({int(row["label"]) for row in test}) < 2:
        raise SystemExit("Both train and held-out groups need positive and negative outcomes.")
    matrix = lambda values: np.asarray([[float(row["features"].get(name, 0)) / 100 for name in FEATURES] for row in values])
    x_train, y_train = matrix(train), np.asarray([int(row["label"]) for row in train])
    x_test, y_test = matrix(test), np.asarray([int(row["label"]) for row in test])
    model = LogisticRegression(class_weight="balanced", max_iter=500, random_state=42).fit(x_train, y_train)
    probability = model.predict_proba(x_test)[:, 1]
    metrics = {"timestampUtc": datetime.now(timezone.utc).isoformat(), "rows": len(rows), "trainRows": len(train), "testRows": len(test), "groupOverlap": 0, "rocAuc": round(float(roc_auc_score(y_test, probability)), 6), "averagePrecision": round(float(average_precision_score(y_test, probability)), 6), "features": FEATURES, "limitations": "Interaction propensity is not housing suitability; deploy only after bias slices and A/B review."}
    args.output.mkdir(parents=True, exist_ok=True)
    joblib.dump({"model": model, "features": FEATURES, "version": "feedback-reranker-v1"}, args.output / "model.joblib")
    (args.output / "evaluation.json").write_text(json.dumps(metrics, indent=2), encoding="utf-8")
    print(json.dumps(metrics, indent=2))


if __name__ == "__main__":
    main()
