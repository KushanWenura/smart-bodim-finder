"""Evaluate a sentence-search artifact against a pooled held-out corpus."""
from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from pipeline_common import read_jsonl


def metrics(model_path: str, rows: list[dict]) -> dict:
    from sentence_transformers import SentenceTransformer

    model = SentenceTransformer(model_path, device="cpu")
    corpus = list(dict.fromkeys([item for row in rows for item in (row["positive"], row["negative"])]))
    corpus_vectors = model.encode(corpus, normalize_embeddings=True, convert_to_numpy=True)
    reciprocal_ranks: list[float] = []
    hits = 0
    for row in rows:
        query_vector = model.encode([row["query"]], normalize_embeddings=True, convert_to_numpy=True)[0]
        ranking = np.argsort(-(corpus_vectors @ query_vector)).tolist()
        relevant_index = corpus.index(row["positive"])
        rank = ranking.index(relevant_index) + 1
        reciprocal_ranks.append(1 / rank)
        hits += int(rank == 1)
    return {"mrr": round(float(np.mean(reciprocal_ranks)), 6), "recallAt1": round(hits / len(rows), 6), "queries": len(rows), "corpusSize": len(corpus)}


def keyword_metrics(rows: list[dict]) -> dict:
    corpus = list(dict.fromkeys([item for row in rows for item in (row["positive"], row["negative"])]))
    reciprocal_ranks: list[float] = []
    hits = 0
    for row in rows:
        terms = set(row["query"].casefold().split())
        ranking = sorted(range(len(corpus)), key=lambda index: -len(terms & set(corpus[index].casefold().split())))
        rank = ranking.index(corpus.index(row["positive"])) + 1
        reciprocal_ranks.append(1 / rank)
        hits += int(rank == 1)
    return {"mrr": round(float(np.mean(reciprocal_ranks)), 6), "recallAt1": round(hits / len(rows), 6)}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--test", type=Path, required=True)
    parser.add_argument("--model", required=True)
    parser.add_argument("--base-model", default="sentence-transformers/all-MiniLM-L6-v2")
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    rows = read_jsonl(args.test)
    report = {
        "timestampUtc": datetime.now(timezone.utc).isoformat(),
        "dataset": str(args.test),
        "method": "pooled held-out retrieval; each positive ranked among all held-out positive and negative texts",
        "fineTuned": metrics(args.model, rows),
        "pretrainedBase": metrics(args.base_model, rows),
        "keywordBaseline": keyword_metrics(rows),
        "limitations": "Synthetic held-out academic data; not a real-world performance or bias claim.",
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
