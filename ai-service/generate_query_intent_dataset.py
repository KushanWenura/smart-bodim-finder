"""Generate a reproducible CC0 multilingual intent-classification dataset."""
from __future__ import annotations

import json
import random
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "datasets" / "catalog" / "sri_lanka_higher_education_destinations.json"
OUTPUT = ROOT / "datasets" / "raw" / "query_intent_examples.jsonl"
SEED = 42

FACILITIES = [
    ("WiFi", "වයිෆයි", "வைஃபை"),
    ("AC", "ඒසී", "ஏசி"),
    ("parking", "වාහන නැවැත්වීම", "கார் பார்க்கிங்"),
    ("attached bathroom", "වෙනම බාත්රූම්", "தனி குளியலறை"),
    ("kitchen", "කුස්සිය", "சமையலறை"),
]


def main() -> None:
    random.seed(SEED)
    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    destinations = catalog.get("destinations", catalog) if isinstance(catalog, dict) else catalog
    rows = []
    for index, destination in enumerate(destinations):
        name = destination["name"]
        facility = FACILITIES[index % len(FACILITIES)]
        budget = 20000 + (index % 7) * 5000
        radius = 3 + (index % 8)
        examples = [
            (f"Find a room near {name} under Rs. {budget} with {facility[0]}", ["find_stay", "destination", "budget", "hard_facility"]),
            (f"Prefer {facility[0]} near {name}, but keep it below {budget}", ["find_stay", "destination", "budget", "preferred_facility"]),
            (f"Room within {radius} km of {name} without {facility[0]}", ["find_stay", "destination", "radius", "excluded_facility"]),
            (f"{name} ළඟ {budget} ට අඩුවෙන් {facility[1]} තියෙන බෝඩිමක්", ["find_stay", "destination", "budget", "hard_facility", "sinhala"]),
            (f"{name} அருகில் {budget} கீழ் {facility[2]} உள்ள அறை", ["find_stay", "destination", "budget", "hard_facility", "tamil"]),
            (f"{name} lagin {facility[0]} thibunoth hondai, budget {budget}", ["find_stay", "destination", "budget", "preferred_facility", "singlish"]),
        ]
        for variant, (text, labels) in enumerate(examples):
            rows.append({"id": f"intent-{index:03d}-{variant}", "groupId": f"destination-{index:03d}", "text": text, "labels": labels, "source": "CC0 synthetic academic dataset", "license": "CC0-1.0"})
    random.shuffle(rows)
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text("".join(json.dumps(row, ensure_ascii=False) + "\n" for row in rows), encoding="utf-8")
    print(json.dumps({"output": str(OUTPUT), "rows": len(rows), "groups": len(destinations), "seed": SEED}))


if __name__ == "__main__":
    main()
