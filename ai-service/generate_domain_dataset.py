"""Generate the deterministic CC0 Smart Bodim semantic-search dataset.

The generator keeps branch-sensitive examples together with hard negatives so a
model learns that ICBT Kandy and ICBT Colombo are different destinations.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parents[1]

SCENARIOS = [
    ("private room", "female-only", ["WiFi", "attached bathroom"], 30000, 5),
    ("boarding room", "open to anyone", ["WiFi", "study area"], 35000, 8),
    ("shared room", "male-only", ["meals", "hot water"], 24000, 10),
    ("annex", "open to anyone", ["kitchen access", "parking"], 45000, 12),
    ("studio", "open to anyone", ["air conditioning", "attached bathroom"], 55000, 7),
    ("private room", "male-only", ["WiFi", "parking"], 32000, 15),
    ("boarding room", "female-only", ["meals", "security"], 28000, 6),
    ("small house", "open to anyone", ["kitchen access", "washing machine"], 65000, 20),
    ("hostel", "female-only", ["WiFi", "bus access"], 22000, 4),
    ("shared room", "open to anyone", ["WiFi", "Cargills nearby"], 26000, 9),
    ("annex", "open to anyone", ["hospital nearby", "parking"], 50000, 18),
    ("private room", "female-only", ["train access", "WiFi"], 38000, 11),
]

QUERY_STYLES = [
    ("en-formal", "Find a {gender} {property} within {radius} km of {destination} under Rs. {budget} with {facility_text}."),
    ("en-conversational", "I {activity} at {destination}; can you find me a {property} below LKR {budget}, no more than {radius} km away, with {facility_text}?"),
    ("en-lk-casual", "Need {property} near {destination}, budget {budget} max, {gender}, must have {facility_text}, around {radius}km."),
    ("en-lk-noisy", "plz find {property} close to {destination} wit {facility_text} max {budget} lkr within {radius} km for {gender}"),
]


def slug(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", value.casefold()).strip("-")


def load_destinations(path: Path) -> list[dict]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    return payload["destinations"]


def generate(catalog_path: Path) -> list[dict]:
    destinations = load_destinations(catalog_path)
    rows: list[dict] = []
    counter = 1
    for destination_index, record in enumerate(destinations):
        destination = record["name"]
        organization = record["organization"]
        branch = record["branch"]
        area = record["town"]
        destination_type = record["type"]
        for scenario_index, (property_type, gender, facilities, budget, radius) in enumerate(SCENARIOS, 1):
            group_id = f"{slug(destination)}-{scenario_index:02d}"
            other_branches = [item for item in destinations if item["organization"] == organization and item["name"] != destination]
            if other_branches:
                wrong_destination = other_branches[(scenario_index + destination_index) % len(other_branches)]["name"]
            else:
                wrong_destination = destinations[(destination_index + scenario_index + 5) % len(destinations)]["name"]
            facility_text = " and ".join(facilities)
            positive_price = budget - 1500 - (scenario_index * 125)
            positive = (
                f"Verified {gender} {property_type} in {area}, {radius - 1 if radius > 1 else 1} km from {destination}, "
                f"with {facility_text}. Monthly rent Rs. {positive_price:,}; public transport and daily essentials are nearby."
            )
            negative = (
                f"{property_type.title()} near {wrong_destination}, not {destination}, at Rs. {budget + 12000:,} per month. "
                f"It is outside the requested branch area and does not provide {facilities[0]}."
            )
            for style, template in QUERY_STYLES:
                query = template.format(
                    gender=gender,
                    property=property_type,
                    radius=radius,
                    destination=destination,
                    budget=f"{budget:,}",
                    facility_text=facility_text,
                    activity="study" if destination_type == "campus" else "work",
                )
                rows.append({
                    "id": f"sb2-{counter:04d}",
                    "groupId": group_id,
                    "query": query,
                    "positive": positive,
                    "negative": negative,
                    "destination": destination,
                    "organization": organization,
                    "branch": branch,
                    "languageStyle": style,
                    "constraints": {"propertyType": property_type, "gender": gender, "facilities": facilities, "maxBudgetLkr": budget, "radiusKm": radius},
                    "hardNegativeReasons": ["wrong destination or branch", "over budget", "missing required facility"],
                    "destinationSource": record["sourceUrl"],
                    "source": "smart-bodim-synthetic-domain-v3",
                    "license": "CC0-1.0",
                })
                counter += 1
    return rows


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--catalog", type=Path, default=PROJECT_ROOT / "datasets/catalog/sri_lanka_higher_education_destinations.json")
    parser.add_argument("--output", type=Path, default=PROJECT_ROOT / "datasets/raw/smart_bodim_search_pairs.jsonl")
    args = parser.parse_args()
    rows = generate(args.catalog)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text("\n".join(json.dumps(row, ensure_ascii=False) for row in rows) + "\n", encoding="utf-8")
    print(json.dumps({"output": str(args.output), "rows": len(rows), "groups": len({row['groupId'] for row in rows}), "destinations": len({row['destination'] for row in rows})}, indent=2))


if __name__ == "__main__":
    main()
