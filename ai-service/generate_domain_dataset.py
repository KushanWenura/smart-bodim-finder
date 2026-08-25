"""Generate the deterministic CC0 Smart Bodim semantic-search dataset.

The generator keeps branch-sensitive examples together with hard negatives so a
model learns that ICBT Kandy and ICBT Colombo are different destinations.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path


ICBT_SOURCE = "https://icbt.lk/branches/"

DESTINATIONS = [
    ("University of Moratuwa", "University of Moratuwa", None, "Moratuwa", "campus"),
    ("University of Colombo", "University of Colombo", None, "Colombo", "campus"),
    ("University of Sri Jayewardenepura", "University of Sri Jayewardenepura", None, "Nugegoda", "campus"),
    ("SLIIT Malabe Campus", "SLIIT", "Malabe", "Malabe", "campus"),
    ("NSBM Green University", "NSBM Green University", None, "Homagama", "campus"),
    ("University of Kelaniya", "University of Kelaniya", None, "Kelaniya", "campus"),
    ("University of Peradeniya", "University of Peradeniya", None, "Peradeniya", "campus"),
    ("University of Ruhuna", "University of Ruhuna", None, "Matara", "campus"),
    ("University of Jaffna", "University of Jaffna", None, "Jaffna", "campus"),
    ("Kotelawala Defence University", "Kotelawala Defence University", None, "Ratmalana", "campus"),
    *[(f"ICBT Campus - {branch}", "ICBT Campus", branch, branch, "campus") for branch in
      ["Colombo", "Kandy", "Galle", "Nugegoda", "Batticaloa", "Matara", "Jaffna", "Kurunegala", "Gampaha", "Anuradhapura"]],
    ("World Trade Center Colombo", "World Trade Center Colombo", None, "Colombo Fort", "workplace"),
    ("Orion City IT Park", "Orion City IT Park", None, "Colombo", "workplace"),
    ("TRACE Expert City", "TRACE Expert City", None, "Maradana", "workplace"),
    ("Kandy City Centre", "Kandy City Centre", None, "Kandy", "workplace"),
    ("Galle City Centre", "Galle City Centre", None, "Galle", "workplace"),
    ("Colombo South Teaching Hospital", "Colombo South Teaching Hospital", None, "Kalubowila", "workplace"),
    ("National Hospital of Sri Lanka", "National Hospital of Sri Lanka", None, "Colombo", "workplace"),
    ("Teaching Hospital Karapitiya", "Teaching Hospital Karapitiya", None, "Karapitiya", "workplace"),
]

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


def generate() -> list[dict]:
    rows: list[dict] = []
    counter = 1
    for destination_index, (destination, organization, branch, area, destination_type) in enumerate(DESTINATIONS):
        for scenario_index, (property_type, gender, facilities, budget, radius) in enumerate(SCENARIOS, 1):
            group_id = f"{slug(destination)}-{scenario_index:02d}"
            if organization == "ICBT Campus":
                other_branches = [item for item in DESTINATIONS if item[1] == organization and item[0] != destination]
                wrong_destination = other_branches[(scenario_index + destination_index) % len(other_branches)][0]
            else:
                wrong_destination = DESTINATIONS[(destination_index + scenario_index + 5) % len(DESTINATIONS)][0]
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
                    "destinationSource": ICBT_SOURCE if organization == "ICBT Campus" else "project-curated synthetic academic directory",
                    "source": "smart-bodim-synthetic-domain-v2",
                    "license": "CC0-1.0",
                })
                counter += 1
    return rows


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=Path("../datasets/raw/smart_bodim_search_pairs.jsonl"))
    args = parser.parse_args()
    rows = generate()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text("\n".join(json.dumps(row, ensure_ascii=False) for row in rows) + "\n", encoding="utf-8")
    print(json.dumps({"output": str(args.output), "rows": len(rows), "groups": len({row['groupId'] for row in rows}), "branches": len({row['destination'] for row in rows})}, indent=2))


if __name__ == "__main__":
    main()
