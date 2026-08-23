import importlib.util
import pathlib

MODULE_PATH = pathlib.Path(__file__).parents[1] / "app.py"
SPEC = importlib.util.spec_from_file_location("smart_bodim_ai_api", MODULE_PATH)
ai = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ai)


def client():
    ai.app.config.update(TESTING=True)
    return ai.app.test_client()


def auth():
    return {"X-Internal-Secret": ai.SECRET, "X-Correlation-ID": "contract-test-1"}


def test_health_reports_readiness_without_authentication():
    response = client().get("/health")
    assert response.status_code == 200
    assert {"service", "modelReady", "indexReady", "mode", "indexSize"} <= response.json.keys()


def test_internal_routes_require_shared_secret():
    response = client().get("/v1/models")
    assert response.status_code == 401
    assert response.json["error"]["code"] == "UNAUTHORIZED"


def test_search_contract_and_correlation_id():
    response = client().post("/v1/search", headers=auth(), json={"query": "quiet wifi room", "limit": 5, "listings": [{"id": 9, "title": "Quiet room", "description": "WiFi included", "city": "Colombo", "facilities": ["WiFi"]}]})
    assert response.status_code == 200
    assert response.json["results"][0]["id"] == 9
    assert response.headers["X-Correlation-ID"] == "contract-test-1"


def test_review_summary_does_not_invent_evidence():
    response = client().post("/v1/reviews/summarize", headers=auth(), json={"reviews": ["Clean and quiet", "The room was clean"]})
    assert response.status_code == 200
    assert response.json["sampleSize"] == 2
    assert "Based on 2 reviews" in response.json["summary"]


def test_index_upsert_requires_id():
    response = client().post("/v1/index/upsert", headers=auth(), json={"text": "room"})
    assert response.status_code == 422
    assert response.json["error"]["code"] == "VALIDATION_ERROR"
