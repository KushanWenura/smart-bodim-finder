"""Normalize, deduplicate and create deterministic train/validation/test JSONL splits."""
from __future__ import annotations
import argparse,json
from pathlib import Path
from pipeline_common import digest,normalized_text,read_jsonl,run_manifest,split_rows,write_jsonl

def prepare(source:Path,out:Path,kind:str,seed:int):
    rows=read_jsonl(source);seen=set();clean=[]
    text_fields=["text"] if kind=="reviews" else ["query","positive","negative"]
    for row in rows:
        row={**row};
        for field in text_fields:row[field]=normalized_text(row[field])
        key="|".join(row[field].casefold() for field in text_fields)
        if key not in seen:clean.append(row);seen.add(key)
    group_key="groupId" if kind=="search" and clean and all(row.get("groupId") for row in clean) else None
    splits=split_rows(clean,seed,group_key=group_key)
    for name,items in splits.items():write_jsonl(out/f"{kind}-{name}.jsonl",items)
    manifest=run_manifest(preprocessingVersion="2.0.0",seed=seed,source=str(source),sourceSha256=digest(source),licenseSummary=sorted({x["license"] for x in clean}),splitStrategy="group-aware" if group_key else "row-random",groupField=group_key,sizes={k:len(v) for k,v in splits.items()},groups={k:len({row.get(group_key) for row in v}) for k,v in splits.items()} if group_key else None)
    (out/f"{kind}-manifest.json").write_text(json.dumps(manifest,indent=2),encoding="utf-8")
    return manifest

if __name__=="__main__":
    p=argparse.ArgumentParser();p.add_argument("source",type=Path);p.add_argument("--out",type=Path,default=Path("../datasets/processed"));p.add_argument("--kind",choices=["reviews","search"],required=True);p.add_argument("--seed",type=int,default=42);a=p.parse_args();print(json.dumps(prepare(a.source,a.out,a.kind,a.seed),indent=2))
