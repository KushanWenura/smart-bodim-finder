"""Shared, deterministic data-pipeline utilities."""
from __future__ import annotations

import hashlib
import json
import platform
import random
from datetime import datetime, timezone
from pathlib import Path


def read_jsonl(path: Path) -> list[dict]:
    rows=[]
    for number,line in enumerate(path.read_text(encoding="utf-8").splitlines(),1):
        if line.strip():
            try: rows.append(json.loads(line))
            except json.JSONDecodeError as exc: raise ValueError(f"{path}:{number}: {exc}") from exc
    return rows


def write_jsonl(path: Path, rows: list[dict]) -> None:
    path.parent.mkdir(parents=True,exist_ok=True)
    path.write_text("\n".join(json.dumps(row,ensure_ascii=False) for row in rows)+"\n",encoding="utf-8")


def normalized_text(value: str) -> str:
    return " ".join(str(value).replace("\x00","").split()).strip()


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def split_rows(rows: list[dict], seed: int, ratios=(0.7,0.15,0.15)) -> dict[str,list[dict]]:
    shuffled=list(rows);random.Random(seed).shuffle(shuffled);n=len(shuffled)
    if n>=3:valid=max(1,int(n*ratios[1]));test=max(1,int(n*ratios[2]));train=n-valid-test
    else:train=n;valid=test=0
    return {"train":shuffled[:train],"validation":shuffled[train:train+valid],"test":shuffled[train+valid:]}


def run_manifest(**values) -> dict:
    return {"timestampUtc":datetime.now(timezone.utc).isoformat(),"python":platform.python_version(),"platform":platform.platform(),**values}
