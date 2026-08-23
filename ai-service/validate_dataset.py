"""Validate provenance, schema, labels, duplicates and unsafe empty records."""
from __future__ import annotations
import argparse
from pathlib import Path
from pipeline_common import normalized_text,read_jsonl

def validate(path:Path,kind:str)->dict:
    rows=read_jsonl(path);required={"id","source","license"}|({"text","label"} if kind=="reviews" else {"query","positive","negative"});seen=set();errors=[]
    for i,row in enumerate(rows,1):
        missing=required-row.keys()
        if missing:errors.append(f"row {i}: missing {sorted(missing)}");continue
        key=normalized_text(row["text"] if kind=="reviews" else row["query"]).casefold()
        if not key:errors.append(f"row {i}: empty text")
        if key in seen:errors.append(f"row {i}: duplicate normalized text")
        seen.add(key)
        if kind=="reviews" and row["label"] not in {"positive","negative","uncertain"}:errors.append(f"row {i}: invalid label")
        if not normalized_text(row["source"]) or not normalized_text(row["license"]):errors.append(f"row {i}: source/license required")
    if errors:raise SystemExit("\n".join(errors))
    return {"file":str(path),"kind":kind,"rows":len(rows),"valid":True}

if __name__=="__main__":
    parser=argparse.ArgumentParser();parser.add_argument("path",type=Path);parser.add_argument("--kind",choices=["reviews","search"],required=True);args=parser.parse_args();print(validate(args.path,args.kind))
