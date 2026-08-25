"""Train the small multilingual intent classifier used to calibrate Buddy."""
from __future__ import annotations

import hashlib
import json
import platform
import random
from datetime import datetime, timezone
from pathlib import Path

import joblib
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import f1_score
from sklearn.multiclass import OneVsRestClassifier
from sklearn.preprocessing import MultiLabelBinarizer

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "datasets" / "raw" / "query_intent_examples.jsonl"
OUTPUT = ROOT / "models" / "query-intent-v1"
SEED = 42


def main() -> None:
    random.seed(SEED)
    rows = [json.loads(line) for line in SOURCE.read_text(encoding="utf-8").splitlines() if line.strip()]
    groups = sorted({row["groupId"] for row in rows})
    random.shuffle(groups)
    cutoff = int(len(groups) * .8)
    train_groups = set(groups[:cutoff])
    train = [row for row in rows if row["groupId"] in train_groups]
    test = [row for row in rows if row["groupId"] not in train_groups]
    vectorizer = TfidfVectorizer(analyzer="char_wb", ngram_range=(3, 5), min_df=2, max_features=30000, sublinear_tf=True)
    x_train = vectorizer.fit_transform([row["text"] for row in train])
    x_test = vectorizer.transform([row["text"] for row in test])
    labels = MultiLabelBinarizer()
    y_train = labels.fit_transform([row["labels"] for row in train])
    y_test = labels.transform([row["labels"] for row in test])
    classifier = OneVsRestClassifier(LogisticRegression(max_iter=500, class_weight="balanced", random_state=SEED))
    classifier.fit(x_train, y_train)
    predicted = classifier.predict(x_test)
    metrics = {
        "timestampUtc": datetime.now(timezone.utc).isoformat(),
        "model": "character TF-IDF + one-vs-rest logistic regression",
        "dataset": str(SOURCE.relative_to(ROOT)),
        "datasetSha256": hashlib.sha256(SOURCE.read_bytes()).hexdigest(),
        "trainRows": len(train),
        "testRows": len(test),
        "groupOverlap": len(train_groups.intersection(set(groups[cutoff:]))),
        "microF1": round(float(f1_score(y_test, predicted, average="micro", zero_division=0)), 6),
        "macroF1": round(float(f1_score(y_test, predicted, average="macro", zero_division=0)), 6),
        "labels": labels.classes_.tolist(),
        "seed": SEED,
        "python": platform.python_version(),
        "limitations": "Synthetic multilingual intent evidence; production accuracy requires consented human-labelled queries.",
    }
    OUTPUT.mkdir(parents=True, exist_ok=True)
    joblib.dump({"vectorizer": vectorizer, "classifier": classifier, "labels": labels, "version": "query-intent-v1"}, OUTPUT / "model.joblib")
    (OUTPUT / "evaluation.json").write_text(json.dumps(metrics, indent=2), encoding="utf-8")
    print(json.dumps(metrics, indent=2))


if __name__ == "__main__":
    main()
