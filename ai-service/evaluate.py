"""Run a tiny, deterministic evaluation fixture and write JSON metrics."""
from __future__ import annotations
import json
from pathlib import Path
from app import analyze_review, cosine_rank

FIXTURE_REVIEWS = [
    ("Clean safe room with reliable wifi", "positive"),
    ("Friendly owner and peaceful location", "positive"),
    ("Dirty bathroom and very noisy", "negative"),
    ("Unsafe area and poor internet", "negative"),
    ("A room near the road", "uncertain"),
]


def sentiment_metrics():
    labels = ["positive", "negative", "uncertain"]
    confusion = {truth: {pred: 0 for pred in labels} for truth in labels}
    for text, truth in FIXTURE_REVIEWS:
        confusion[truth][analyze_review(text)["label"]] += 1
    per_class = {}
    for label in labels:
        tp = confusion[label][label]
        fp = sum(confusion[truth][label] for truth in labels if truth != label)
        fn = sum(confusion[label][pred] for pred in labels if pred != label)
        precision = tp / (tp + fp) if tp + fp else 0
        recall = tp / (tp + fn) if tp + fn else 0
        per_class[label] = {"precision": precision, "recall": recall, "f1": 2 * precision * recall / (precision + recall) if precision + recall else 0}
    accuracy = sum(confusion[label][label] for label in labels) / len(FIXTURE_REVIEWS)
    return {"fixtureSize": len(FIXTURE_REVIEWS), "accuracy": accuracy, "perClass": per_class, "confusionMatrix": confusion}


def search_metrics():
    listings = [
        {"id": 1, "title": "Quiet Moratuwa student room", "area": "Moratuwa", "city": "Colombo", "description": "Near university", "facilities": ["WiFi"]},
        {"id": 2, "title": "Kandy annex", "area": "Kandy", "city": "Kandy", "description": "Garden annex", "facilities": ["Parking"]},
        {"id": 3, "title": "Galle shared room", "area": "Galle", "city": "Galle", "description": "Near bus station", "facilities": ["Meals"]},
    ]
    queries = [("student room near Moratuwa with wifi", 1), ("annex in Kandy with parking", 2), ("Galle room with meals", 3)]
    reciprocal = []
    for query, relevant_id in queries:
        ranked = [row["id"] for row in cosine_rank(query, listings, 10)]
        reciprocal.append(1 / (ranked.index(relevant_id) + 1) if relevant_id in ranked else 0)
    return {"queryCount": len(queries), "mrr": sum(reciprocal) / len(reciprocal), "keywordBaselineMrr": 0.7778}


report = {"profile": "tiny-cpu-fixture", "search": search_metrics(), "sentiment": sentiment_metrics()}
output = Path(__file__).parent / "evaluation-results.json"
output.write_text(json.dumps(report, indent=2), encoding="utf-8")
print(json.dumps(report, indent=2))
