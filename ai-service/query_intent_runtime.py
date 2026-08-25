from __future__ import annotations

import os
from pathlib import Path
from typing import Any


class QueryIntentRuntime:
    def __init__(self) -> None:
        self.version = "unavailable"
        self.bundle: dict[str, Any] | None = None
        path = Path(os.environ.get("QUERY_INTENT_MODEL_PATH", Path(__file__).resolve().parents[1] / "models" / "query-intent-v1" / "model.joblib"))
        if path.exists():
            try:
                import joblib
                self.bundle = joblib.load(path)
                self.version = str(self.bundle.get("version", "query-intent-v1"))
            except Exception:
                self.bundle = None

    @property
    def ready(self) -> bool:
        return self.bundle is not None

    def predict(self, text: str) -> dict[str, Any]:
        if not self.bundle:
            return {"ready": False, "labels": [], "confidence": 0.0, "version": self.version}
        vector = self.bundle["vectorizer"].transform([text])
        probabilities = self.bundle["classifier"].predict_proba(vector)[0]
        names = self.bundle["labels"].classes_
        scores = sorted(({"label": str(name), "confidence": round(float(score), 4)} for name, score in zip(names, probabilities) if score >= .35), key=lambda row: -row["confidence"])
        return {"ready": True, "labels": scores, "confidence": max((row["confidence"] for row in scores), default=0.0), "version": self.version}
