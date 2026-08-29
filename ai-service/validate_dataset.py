"""Validate provenance, schema, labels, duplicates and unsafe empty records."""
from __future__ import annotations
import argparse
from pathlib import Path
from pipeline_common import normalized_text,read_jsonl

def validate(path:Path,kind:str)->dict:
    text_kind = kind in {"reviews", "safety"}
    required={"id","source","license"}|({"text","label"} if text_kind else {"query","positive","negative"})
    if kind == "safety": required |= {"language", "safetyAspects", "synthetic", "evidenceUse"}
    rows=read_jsonl(path);seen=set();errors=[]
    for i,row in enumerate(rows,1):
        missing=required-row.keys()
        if missing:errors.append(f"row {i}: missing {sorted(missing)}");continue
        key=normalized_text(row["text"] if text_kind else row["query"]).casefold()
        if not key:errors.append(f"row {i}: empty text")
        if key in seen:errors.append(f"row {i}: duplicate normalized text")
        seen.add(key)
        if text_kind and row["label"] not in {"positive","negative","uncertain"}:errors.append(f"row {i}: invalid label")
        if kind=="safety" and (not isinstance(row["safetyAspects"],dict) or not row["safetyAspects"]):errors.append(f"row {i}: safetyAspects must be a non-empty object")
        if kind=="safety" and row["evidenceUse"] != "model-development-only":errors.append(f"row {i}: synthetic safety rows cannot be live evidence")
        if not normalized_text(row["source"]) or not normalized_text(row["license"]):errors.append(f"row {i}: source/license required")
    if errors:raise SystemExit("\n".join(errors))
    return {"file":str(path),"kind":kind,"rows":len(rows),"valid":True}

if __name__=="__main__":
    parser=argparse.ArgumentParser();parser.add_argument("path",type=Path);parser.add_argument("--kind",choices=["reviews","safety","search"],required=True);args=parser.parse_args();print(validate(args.path,args.kind))
