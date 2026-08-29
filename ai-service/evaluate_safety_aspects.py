"""Evaluate transparent safety-theme extraction on the held-out synthetic split."""
from __future__ import annotations

import argparse
import json
from collections import defaultdict
from pathlib import Path

from app import SAFETY_ASPECT_VERSION, analyze_safety_reports
from pipeline_common import read_jsonl, run_manifest


def evaluate(test_path: Path) -> dict:
    rows = read_jsonl(test_path)
    totals = defaultdict(lambda: {"rows": 0, "aspectHits": 0, "directionHits": 0})
    errors = []
    for row in rows:
        expected_aspect, expected_direction = next(iter(row["safetyAspects"].items()))
        result = analyze_safety_reports([{"text": row["text"], "verified": False}])
        themes = {theme["key"]: theme for theme in result["themes"]}
        language = row["language"]
        for key in ("overall", language):
            totals[key]["rows"] += 1
            totals[key]["aspectHits"] += int(expected_aspect in themes)
            totals[key]["directionHits"] += int(themes.get(expected_aspect, {}).get("direction") == expected_direction)
        if expected_aspect not in themes or themes[expected_aspect]["direction"] != expected_direction:
            errors.append({"id": row["id"], "expectedAspect": expected_aspect, "expectedDirection": expected_direction, "themes": result["themes"]})

    slices = {
        key: {
            "rows": value["rows"],
            "aspectRecall": round(value["aspectHits"] / max(value["rows"], 1), 4),
            "directionAccuracy": round(value["directionHits"] / max(value["rows"], 1), 4),
        }
        for key, value in sorted(totals.items())
    }
    return run_manifest(
        task="safety-aspect-fixture-evaluation",
        modelVersion=SAFETY_ASPECT_VERSION,
        dataset=str(test_path),
        synthetic=True,
        realWorldClaim=False,
        slices=slices,
        errorCount=len(errors),
        errors=errors[:20],
        limitations="Synthetic held-out performance verifies deterministic language coverage only; it is not a crime-risk or real-world safety benchmark.",
    )


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--test", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    report = evaluate(args.test)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    lines = ["# Safety aspect baseline evaluation", "", f"- Model: `{report['modelVersion']}`", f"- Synthetic test rows: {report['slices']['overall']['rows']}", f"- Aspect recall: {report['slices']['overall']['aspectRecall']:.4f}", f"- Direction accuracy: {report['slices']['overall']['directionAccuracy']:.4f}", "", report["limitations"]]
    args.output.with_suffix(".md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(json.dumps(report["slices"], indent=2))
