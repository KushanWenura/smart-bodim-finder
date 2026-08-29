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
from query_intent_runtime import QueryIntentRuntime

VERSION = "fixture-tfidf-1.0.0"
SENTIMENT_VERSION = "fixture-lexicon-1.0.0"
SAFETY_ASPECT_VERSION = "buddy-safety-aspects-v1.0.0"
SECRET = os.environ.get("AI_INTERNAL_SECRET", "change-this-in-every-shared-environment")
PORT = int(os.environ.get("AI_PORT", "5100"))
HOST = os.environ.get("AI_HOST", "127.0.0.1")
MAX_BODY = 1_000_000
PROFILE = os.environ.get("AI_PROFILE", "fixture")
runtime = ModelRuntime(profile=PROFILE)
intent_runtime = QueryIntentRuntime()
app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = MAX_BODY

POSITIVE = {"clean", "quiet", "safe", "friendly", "reliable", "good", "great", "excellent", "helpful", "peaceful", "convenient", "quickly", "comfortable", "පිරිසිදු", "නිස්කලංක", "ආරක්ෂිත", "හොඳ", "සුවපහසු", "சுத்தம்", "அமைதி", "பாதுகாப்பான", "நல்ல", "வசதியான"}
NEGATIVE = {"dirty", "noisy", "noise", "traffic", "unsafe", "rude", "poor", "bad", "broken", "slow", "expensive", "crowded", "smell", "unreliable", "අපිරිසිදු", "ශබ්ද", "අනාරක්ෂිත", "නරක", "කැඩුණු", "அழுக்கு", "சத்தம்", "பாதுகாப்பற்ற", "மோசம்", "உடைந்த"}
NEGATIONS = {"not", "no", "never", "without", "isn't", "wasn't", "නැහැ", "නොවේ", "නෑ", "இல்லை", "அல்ல"}
ASPECTS = {
    "cleanliness": {"clean", "cleaner", "dirty", "tidy", "smell", "පිරිසිදු", "අපිරිසිදු", "சுத்தம்", "அழுக்கு"},
    "owner responsiveness": {"owner", "responds", "reply", "helpful", "friendly", "rude"},
    "noise": {"noise", "noisy", "quiet", "peaceful", "traffic", "ශබ්ද", "නිස්කලංක", "சத்தம்", "அமைதி"},
    "safety": {"safe", "unsafe", "security", "cctv", "ආරක්ෂිත", "අනාරක්ෂිත", "பாதுகாப்பான", "பாதுகாப்பற்ற"},
    "WiFi": {"wifi", "internet", "online", "වයිෆයි", "ඉන්ටර්නෙට්", "வைஃபை", "இணையம்"},
    "food": {"food", "meals", "meal", "කෑම", "ආහාර", "உணவு", "சாப்பாடு"},
    "bathroom": {"bathroom", "washroom", "hot water", "බාත්රූම්", "නාන කාමරය", "குளியலறை"},
    "transport": {"bus", "train", "transport", "commute", "බස්", "දුම්රිය", "பேருந்து", "ரயில்"},
    "location": {"location", "near", "convenient"},
    "price/value": {"price", "value", "rent", "expensive", "affordable"},
    "utilities": {"electricity", "water", "utilities"},
}

# This vocabulary is deliberately small and inspectable. It supports the
# fixture/offline profile and produces evidence labels, never a crime
# prediction. A trained multilingual classifier can replace it only after a
# representative, consented dataset passes the documented evaluation gates.
SAFETY_ASPECTS = {
    "lighting": {
        "label": "Street lighting",
        "supportive": {"well lit", "good lighting", "street lights", "bright at night", "හොඳ ආලෝකය", "වීදි ලාම්පු", "நல்ல வெளிச்சம்", "தெரு விளக்கு"},
        "concern": {"poor lighting", "no street lights", "very dark", "dark lane", "අඳුරු", "වීදි ලාම්පු නැහැ", "இருட்டு", "தெரு விளக்கு இல்லை"},
    },
    "night_activity": {
        "label": "Evening activity",
        "supportive": {"busy at night", "people around", "shops open late", "active in the evening", "රෑටත් මිනිස්සු ඉන්නවා", "කඩ විවෘතයි", "இரவில் மக்கள் நடமாட்டம்", "கடைகள் திறந்திருக்கும்"},
        "concern": {"empty at night", "deserted", "quiet after dark", "isolated at night", "රෑට පාළුයි", "රාත්‍රියේ නිහඬයි", "இரவில் வெறிச்சோடி", "தனிமையாக உள்ளது"},
    },
    "transport": {
        "label": "Late transport",
        "supportive": {"buses run late", "easy to get a bus", "transport available", "three wheeler available", "රෑටත් බස් තියෙනවා", "ප්‍රවාහනය පහසුයි", "இரவு பேருந்து உள்ளது", "போக்குவரத்து வசதி"},
        "concern": {"no buses after", "transport stops early", "hard to find transport", "no late transport", "රෑට බස් නැහැ", "ප්‍රවාහනය අමාරුයි", "இரவில் பேருந்து இல்லை", "போக்குவரத்து கிடைக்காது"},
    },
    "isolation": {
        "label": "Isolation",
        "supportive": {"houses nearby", "neighbours around", "visible from main road", "populated lane", "අසල්වැසියන් ඉන්නවා", "ගෙවල් ළඟයි", "அருகில் வீடுகள்", "அக்கம் பக்கத்தில் மக்கள்"},
        "concern": {"isolated road", "lonely lane", "far from houses", "hidden alley", "පාළු පාර", "ගෙවල් වලින් දුරයි", "தனிமையான சாலை", "ஒதுக்குப்புறமான வழி"},
    },
    "harassment": {
        "label": "Harassment concerns",
        "supportive": {"no harassment", "felt respected", "people were helpful", "කිසිදු හිරිහැරයක් නැහැ", "ගෞරවයෙන් හැසිරුණා", "தொந்தரவு இல்லை", "மரியாதையாக நடந்தனர்"},
        "concern": {"harassed", "catcalling", "followed me", "unwanted attention", "හිරිහැර", "පසුපස ආවා", "தொந்தரவு", "பின்தொடர்ந்தார்"},
    },
    "theft": {
        "label": "Theft concerns",
        "supportive": {"no theft", "belongings were secure", "secure parking", "හොරකම් නැහැ", "බඩු ආරක්ෂිතයි", "திருட்டு இல்லை", "பொருட்கள் பாதுகாப்பாக"},
        "concern": {"theft", "stolen", "break in", "phone snatching", "හොරකම", "සොරකම් කළා", "திருட்டு", "பறிப்பு"},
    },
    "road_safety": {
        "label": "Road and walking safety",
        "supportive": {"safe crossing", "walkable pavement", "low traffic", "good footpath", "පදික මාර්ගය හොඳයි", "පාර මාරුවීම පහසුයි", "நடைபாதை உள்ளது", "சாலை கடக்க வசதி"},
        "concern": {"dangerous crossing", "no pavement", "speeding vehicles", "heavy traffic", "පදික මාර්ග නැහැ", "වේගයෙන් වාහන යනවා", "நடைபாதை இல்லை", "வேகமாக வாகனங்கள்"},
    },
    "flooding": {
        "label": "Flooding and drainage",
        "supportive": {"does not flood", "good drainage", "road stayed dry", "ගංවතුර නැහැ", "ජලාපවහනය හොඳයි", "வெள்ளம் இல்லை", "வடிகால் நன்றாக"},
        "concern": {"road floods", "waterlogging", "poor drainage", "flooded after rain", "පාර යට වෙනවා", "ජලාපවහනය නරකයි", "சாலை வெள்ளம்", "மழையில் நீர் தேங்கும்"},
    },
    "emergency_access": {
        "label": "Access to help",
        "supportive": {"police nearby", "hospital nearby", "quick emergency access", "pharmacy open late", "පොලිසිය ළඟයි", "රෝහල ළඟයි", "காவல் நிலையம் அருகில்", "மருத்துவமனை அருகில்"},
        "concern": {"far from hospital", "no emergency help", "police are far", "emergency access is difficult", "ambulance access is difficult", "රෝහල දුරයි", "හදිසි සේවා අමාරුයි", "மருத்துவமனை தூரம்", "அவசர உதவி இல்லை"},
    },
}


def tokens(text: str) -> list[str]:
    return [word for word in re.findall(r"[^\W_]+|[0-9]+", text.lower(), flags=re.UNICODE) if len(word) > 1]


def detect_language(text: str) -> str:
    sinhala = bool(re.search(r"[\u0d80-\u0dff]", text))
    tamil = bool(re.search(r"[\u0b80-\u0bff]", text))
    english = bool(re.search(r"[a-zA-Z]", text))
    if sinhala:
        return "si-en" if english else "si"
    if tamil:
        return "ta-en" if english else "ta"
    return "en"


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
    positive = negative = 0
    negate_for = 0
    for word in words:
        if word in NEGATIONS:
            negate_for = 3
            continue
        if word in POSITIVE:
            negative += 1 if negate_for else 0
            positive += 0 if negate_for else 1
        elif word in NEGATIVE:
            positive += 1 if negate_for else 0
            negative += 0 if negate_for else 1
        negate_for = max(0, negate_for - 1)
    total = positive + negative
    score = (positive - negative) / max(total, 1)
    if total == 0 or abs(score) < 0.2:
        label, confidence = "uncertain", 0.5 if total == 0 else 0.55
    else:
        label = "positive" if score > 0 else "negative"
        confidence = min(0.97, 0.62 + abs(score) * 0.33)
    found, evidence, aspect_sentiment = [], {}, {}
    lowered = text.lower()
    sentences = [part.strip() for part in re.split(r"(?<=[.!?])\s+|[\n]+", text) if part.strip()]
    for aspect, phrases in ASPECTS.items():
        if any(phrase in lowered for phrase in phrases):
            found.append(aspect)
            snippets = [sentence[:240] for sentence in sentences if any(phrase in sentence.lower() for phrase in phrases)][:2]
            evidence[aspect] = snippets
            joined = " ".join(snippets).lower()
            positive_hits = sum(term in joined for term in POSITIVE)
            negative_hits = sum(term in joined for term in NEGATIVE)
            aspect_sentiment[aspect] = "positive" if positive_hits > negative_hits else "negative" if negative_hits > positive_hits else "mixed"
    return {"label": label, "confidence": round(confidence, 4), "aspects": found, "aspectSentiment": aspect_sentiment, "evidence": evidence, "language": detect_language(text), "modelVersion": SENTIMENT_VERSION}


def summarize_reviews(reviews: list[str]) -> dict[str, Any]:
    clean_reviews = [str(value)[:4000] for value in reviews if str(value).strip()]
    if len(clean_reviews) < 2:
        return {"summary": "Not enough reviews for a reliable summary.", "sampleSize": len(clean_reviews), "aspects": [], "modelVersion": SENTIMENT_VERSION}
    analyses = [analyze_review(review) for review in clean_reviews]
    positive_aspects, negative_aspects = Counter(), Counter()
    for analysis in analyses:
        for aspect in analysis["aspects"]:
            aspect_label = analysis["aspectSentiment"].get(aspect, "mixed")
            if aspect_label == "positive":
                positive_aspects[aspect] += 1
            elif aspect_label == "negative":
                negative_aspects[aspect] += 1
    praised = [name for name, count in positive_aspects.most_common() if count > negative_aspects[name]][:2]
    concerns = [name for name, count in negative_aspects.most_common() if count > positive_aspects[name]][:1]
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


def analyze_safety_reports(reports: list[Any]) -> dict[str, Any]:
    """Extract safety themes without converting opinions into verified facts."""
    normalized = []
    for item in reports[:200]:
        if isinstance(item, dict):
            text = str(item.get("text", ""))[:4000].strip()
            verified = bool(item.get("verified", False))
        else:
            text = str(item)[:4000].strip()
            verified = False
        if text:
            normalized.append({"text": text, "verified": verified})

    themes = []
    for key, definition in SAFETY_ASPECTS.items():
        supportive = concern = verified_mentions = 0
        for report in normalized:
            lowered = report["text"].lower()
            concern_hit = any(phrase in lowered for phrase in definition["concern"])
            # Concern phrases such as "no street lights" may contain a shorter
            # supportive phrase ("street lights"). Prefer the explicit caution.
            supportive_hit = not concern_hit and any(phrase in lowered for phrase in definition["supportive"])
            if supportive_hit:
                supportive += 1
            if concern_hit:
                concern += 1
            if report["verified"] and (supportive_hit or concern_hit):
                verified_mentions += 1
        mentions = supportive + concern
        if mentions:
            themes.append({
                "key": key,
                "label": definition["label"],
                "supportive": supportive,
                "concerns": concern,
                "mentions": mentions,
                "verifiedMentions": verified_mentions,
                "direction": "concern" if concern > supportive else "supportive" if supportive > concern else "mixed",
            })

    themes.sort(key=lambda row: (-row["concerns"], -row["mentions"], row["key"]))
    verified_count = sum(report["verified"] for report in normalized)
    return {
        "reportCount": len(normalized),
        "verifiedReportCount": verified_count,
        "themes": themes,
        "concernCount": sum(theme["concerns"] for theme in themes),
        "languageMix": dict(Counter(detect_language(report["text"]) for report in normalized)),
        "modelVersion": SAFETY_ASPECT_VERSION,
        "method": "Transparent multilingual safety-aspect extraction",
        "evidencePolicy": "Opinion themes provide cautions and context. They are not crime statistics or proof that an area is safe.",
    }


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
    return jsonify(service="healthy", modelReady=runtime.model_ready, indexReady=runtime.index_ready, mode=runtime.mode, searchModel=runtime.search_version, sentimentModel=runtime.sentiment_version, queryIntentReady=intent_runtime.ready, queryIntentModel=intent_runtime.version, indexSize=runtime.index_size)


@app.get("/v1/models")
def models():
    return jsonify(models=runtime.model_metadata(), queryIntent={"ready": intent_runtime.ready, "version": intent_runtime.version})


@app.post("/v1/query/understand")
def query_understand():
    body = request.get_json() or {}
    text = str(body.get("text", ""))[:500]
    if len(text.strip()) < 2:
        raise ValueError("text must contain at least two characters")
    return jsonify(intent_runtime.predict(text))


@app.post("/v1/search")
def search():
    body = request.get_json(silent=False) or {}
    query = str(body.get("query", ""))[:500]
    listings = body.get("listings", [])
    if not isinstance(listings, list) or len(listings) > 1000:
        raise ValueError("listings must be an array with at most 1000 items")
    results = runtime.rank(query, listings, int(body.get("limit", 12))) if runtime.model_ready else cosine_rank(query, listings, int(body.get("limit", 12)))
    return jsonify(mode=runtime.mode, modelVersion=runtime.search_version, constraints=extract_constraints(query), intent=intent_runtime.predict(query), results=results)


@app.post("/v1/recommendations")
def recommendations():
    body = request.get_json() or {}
    results = runtime.rank(str(body.get("preference", ""))[:1000], body.get("listings", []), int(body.get("limit", 12))) if runtime.model_ready else cosine_rank(str(body.get("preference", "")), body.get("listings", []), int(body.get("limit", 12)))
    return jsonify(mode=runtime.mode, results=results)


@app.post("/v1/reviews/analyze")
def review_analyze():
    body = request.get_json() or {}
    text = str(body.get("text", ""))[:4000]
    evidence_result = analyze_review(text)
    result = runtime.analyze_sentiment(text) if runtime.sentiment_ready and evidence_result["language"] == "en" else evidence_result
    result["aspects"] = evidence_result["aspects"]
    result["aspectSentiment"] = evidence_result["aspectSentiment"]
    result["evidence"] = evidence_result["evidence"]
    result["language"] = evidence_result["language"]
    return jsonify(result)


@app.post("/v1/reviews/summarize")
def review_summarize():
    body = request.get_json() or {}
    reviews = body.get("reviews", [])
    if not isinstance(reviews, list) or len(reviews) > 500:
        raise ValueError("reviews must be an array with at most 500 items")
    return jsonify(summarize_reviews(reviews))


@app.post("/v1/safety/analyze")
def safety_analyze():
    body = request.get_json() or {}
    reports = body.get("reports", [])
    if not isinstance(reports, list) or len(reports) > 200:
        raise ValueError("reports must be an array with at most 200 items")
    return jsonify(analyze_safety_reports(reports))


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
