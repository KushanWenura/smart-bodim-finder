"""Flask AI service with production model adapters and a deterministic CPU fallback."""
from __future__ import annotations

import json
import math
import os
import re
import sys
import time
from collections import Counter
from pathlib import Path
from typing import Any

from flask import Flask, g, jsonify, request

sys.path.insert(0, str(Path(__file__).resolve().parent))
from model_runtime import ModelRuntime

VERSION = "fixture-tfidf-1.0.0"
SENTIMENT_VERSION = "fixture-lexicon-1.0.0"
SECRET = os.environ.get("AI_INTERNAL_SECRET", "change-this-in-every-shared-environment")
PORT = int(os.environ.get("AI_PORT", "5100"))
HOST = os.environ.get("AI_HOST", "127.0.0.1")
MAX_BODY = 1_000_000
PROFILE = os.environ.get("AI_PROFILE", "fixture")
runtime = ModelRuntime(profile=PROFILE)
app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = MAX_BODY

POSITIVE = {"clean", "quiet", "safe", "friendly", "reliable", "good", "great", "excellent", "helpful", "peaceful", "convenient", "quickly", "comfortable"}
NEGATIVE = {"dirty", "noisy", "noise", "unsafe", "rude", "poor", "bad", "broken", "slow", "expensive", "crowded", "smell", "unreliable"}
ASPECTS = {
    "cleanliness": {"clean", "cleaner", "dirty", "tidy", "smell"},
    "owner responsiveness": {"owner", "responds", "reply", "helpful", "friendly", "rude"},
    "noise": {"noise", "noisy", "quiet", "peaceful", "traffic"},
    "safety": {"safe", "unsafe", "security", "cctv"},
    "WiFi": {"wifi", "internet", "online"},
    "food": {"food", "meals", "meal"},
    "bathroom": {"bathroom", "washroom", "hot water"},
    "transport": {"bus", "train", "transport", "commute"},
    "location": {"location", "near", "convenient"},
    "price/value": {"price", "value", "rent", "expensive", "affordable"},
    "utilities": {"electricity", "water", "utilities"},
}


def tokens(text: str) -> list[str]:
    return [word for word in re.findall(r"[a-z0-9]+", text.lower()) if len(word) > 1]


def canonical_listing(item: dict[str, Any]) -> str:
    return " ".join(str(part) for part in [
        item.get("title", ""), item.get("description", ""), item.get("propertyType", ""),
        item.get("area", ""), item.get("city", ""), item.get("district", ""),
        " ".join(item.get("facilities", [])), item.get("genderRule", ""),
        "furnished" if item.get("furnished") else "unfurnished",
    ])


def cosine_rank(query: str, listings: list[dict[str, Any]], limit: int = 12) -> list[dict[str, Any]]:
    """Rank using log-scaled TF-IDF cosine similarity with light phrase bonuses."""
    query_terms = tokens(query)[:80]
    if not query_terms:
        return [{"id": item["id"], "score": 1.0 - idx / 1000} for idx, item in enumerate(listings[:limit])]
    docs = [tokens(canonical_listing(item)) for item in listings]
    document_frequency = Counter(term for term in set(query_terms) for doc in docs if term in doc)
    query_vector = Counter(query_terms)
    scored = []
    for item, doc in zip(listings, docs):
        document = Counter(doc)
        dot = q_norm = d_norm = 0.0
        for term, query_count in query_vector.items():
            idf = math.log((len(docs) + 1) / (document_frequency.get(term, 0) + 1)) + 1
            q_value = (1 + math.log(query_count)) * idf
            d_value = (1 + math.log(document.get(term, 1))) * idf if term in document else 0
            dot += q_value * d_value
            q_norm += q_value * q_value
            d_norm += d_value * d_value
        score = dot / math.sqrt(q_norm * d_norm) if dot and d_norm else 0.0
        if query.lower() in canonical_listing(item).lower():
            score += 0.15
        if score > 0:
            scored.append({"id": item["id"], "score": round(score, 6)})
    return sorted(scored, key=lambda row: (-row["score"], row["id"]))[: max(1, min(limit, 50))]


def analyze_review(text: str) -> dict[str, Any]:
    words = tokens(text)[:600]
    positive = sum(word in POSITIVE for word in words)
    negative = sum(word in NEGATIVE for word in words)
    total = positive + negative
    score = (positive - negative) / max(total, 1)
    if total == 0 or abs(score) < 0.2:
        label, confidence = "uncertain", 0.5 if total == 0 else 0.55
    else:
        label = "positive" if score > 0 else "negative"
        confidence = min(0.97, 0.62 + abs(score) * 0.33)
    found = []
    lowered = text.lower()
    for aspect, phrases in ASPECTS.items():
        if any(phrase in lowered for phrase in phrases):
            found.append(aspect)
    return {"label": label, "confidence": round(confidence, 4), "aspects": found, "modelVersion": SENTIMENT_VERSION}


def summarize_reviews(reviews: list[str]) -> dict[str, Any]:
    clean_reviews = [str(value)[:4000] for value in reviews if str(value).strip()]
    if len(clean_reviews) < 2:
        return {"summary": "Not enough reviews for a reliable summary.", "sampleSize": len(clean_reviews), "aspects": [], "modelVersion": SENTIMENT_VERSION}
    analyses = [analyze_review(review) for review in clean_reviews]
    positive_aspects, negative_aspects = Counter(), Counter()
    for text, analysis in zip(clean_reviews, analyses):
        lowered = text.lower()
        for aspect in analysis["aspects"]:
            phrases = ASPECTS[aspect]
            positive_hits = sum(word in lowered for word in phrases & POSITIVE)
            negative_hits = sum(word in lowered for word in phrases & NEGATIVE)
            if analysis["label"] == "positive" or positive_hits > negative_hits:
                positive_aspects[aspect] += 1
            elif analysis["label"] == "negative" or negative_hits > positive_hits:
                negative_aspects[aspect] += 1
    praised = [name for name, count in positive_aspects.most_common(2) if count >= 1]
    concerns = [name for name, count in negative_aspects.most_common(1) if count >= 1 and name not in praised]
    if praised:
        summary = "Most reviewers praised " + " and ".join(praised)
        if concerns:
            summary += "; some mentioned " + concerns[0]
        summary += f". Based on {len(clean_reviews)} reviews."
    else:
        positive_count = sum(row["label"] == "positive" for row in analyses)
        if positive_count > len(analyses) / 2:
            summary = f"Feedback was generally positive, with mixed comments on specific facilities. Based on {len(clean_reviews)} reviews."
        else:
            summary = f"Reviews were mixed and no single aspect was mentioned consistently. Based on {len(clean_reviews)} reviews."
    return {"summary": summary, "sampleSize": len(clean_reviews), "aspects": {"praised": praised, "concerns": concerns}, "modelVersion": SENTIMENT_VERSION, "analyses": analyses}


def extract_constraints(query: str) -> dict[str, Any]:
    result: dict[str, Any] = {}
    lowered = query.lower()
    budget = re.search(r"(?:under|below|max(?:imum)?)\s*(?:rs\.?|lkr)?\s*([0-9,]+)", lowered)
    if budget:
        result["maxPrice"] = int(budget.group(1).replace(",", ""))
    if "female" in lowered:
        result["genderRule"] = "female_only"
    elif "male" in lowered:
        result["genderRule"] = "male_only"
    facility_map = {"wifi": "WiFi", "parking": "Parking", "bathroom": "Attached bathroom", "kitchen": "Kitchen access", "meals": "Meals"}
    result["facilities"] = [value for key, value in facility_map.items() if key in lowered]
    return result


@app.before_request
def authenticate_internal_request():
    g.started = time.perf_counter()
    g.correlation_id = request.headers.get("X-Correlation-ID", os.urandom(8).hex())
    if request.path != "/health" and request.headers.get("X-Internal-Secret", "") != SECRET:
        return jsonify(error={"code": "UNAUTHORIZED", "message": "Internal authentication required.", "correlationId": g.correlation_id}), 401


@app.after_request
def response_metadata(response):
    response.headers["X-Correlation-ID"] = g.get("correlation_id", "")
    print(json.dumps({"service": "ai", "endpoint": request.path, "method": request.method, "status": response.status_code, "latencyMs": round((time.perf_counter() - g.get("started", time.perf_counter())) * 1000, 2), "correlationId": g.get("correlation_id", "")}))
    return response


@app.get("/health")
def health():
    return jsonify(service="healthy", modelReady=runtime.model_ready, indexReady=runtime.index_ready, mode=runtime.mode, searchModel=runtime.search_version, sentimentModel=runtime.sentiment_version, indexSize=runtime.index_size)


@app.get("/v1/models")
def models():
    return jsonify(models=runtime.model_metadata())


@app.post("/v1/search")
def search():
    body = request.get_json(silent=False) or {}
    query = str(body.get("query", ""))[:500]
    listings = body.get("listings", [])
    if not isinstance(listings, list) or len(listings) > 1000:
        raise ValueError("listings must be an array with at most 1000 items")
    results = runtime.rank(query, listings, int(body.get("limit", 12))) if runtime.model_ready else cosine_rank(query, listings, int(body.get("limit", 12)))
    return jsonify(mode=runtime.mode, modelVersion=runtime.search_version, constraints=extract_constraints(query), results=results)


@app.post("/v1/recommendations")
def recommendations():
    body = request.get_json() or {}
    results = runtime.rank(str(body.get("preference", ""))[:1000], body.get("listings", []), int(body.get("limit", 12))) if runtime.model_ready else cosine_rank(str(body.get("preference", "")), body.get("listings", []), int(body.get("limit", 12)))
    return jsonify(mode=runtime.mode, results=results)


@app.post("/v1/reviews/analyze")
def review_analyze():
    body = request.get_json() or {}
    text = str(body.get("text", ""))[:4000]
    result = runtime.analyze_sentiment(text) if runtime.sentiment_ready else analyze_review(text)
    result["aspects"] = analyze_review(text)["aspects"]
    return jsonify(result)


@app.post("/v1/reviews/summarize")
def review_summarize():
    body = request.get_json() or {}
    reviews = body.get("reviews", [])
    if not isinstance(reviews, list) or len(reviews) > 500:
        raise ValueError("reviews must be an array with at most 500 items")
    return jsonify(summarize_reviews(reviews))


@app.post("/v1/index/upsert")
def index_upsert():
    body = request.get_json() or {}
    return jsonify(runtime.index_upsert(int(body["id"]), str(body["text"])[:12000]))


@app.post("/v1/index/delete")
def index_delete():
    body = request.get_json() or {}
    return jsonify(runtime.index_delete(int(body["id"])))


@app.post("/v1/index/rebuild")
def index_rebuild():
    body = request.get_json() or {}
    return jsonify(runtime.index_rebuild(body.get("listings", [])))


@app.errorhandler(ValueError)
def validation_error(exc):
    return jsonify(error={"code": "VALIDATION_ERROR", "message": str(exc), "correlationId": g.get("correlation_id")}), 422


@app.errorhandler(KeyError)
def missing_field(exc):
    return jsonify(error={"code": "VALIDATION_ERROR", "message": f"Missing required field: {exc.args[0]}", "correlationId": g.get("correlation_id")}), 422


@app.errorhandler(413)
def body_too_large(_exc):
    return jsonify(error={"code": "PAYLOAD_TOO_LARGE", "message": "Request body exceeds the configured maximum.", "correlationId": g.get("correlation_id")}), 413


def run() -> None:
    app.run(host=HOST, port=PORT, debug=False, threaded=True)


if __name__ == "__main__":
    run()
