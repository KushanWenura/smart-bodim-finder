"""Build a version-bound FAISS cosine-similarity index from published listings JSON."""
from __future__ import annotations
import argparse,json
from pathlib import Path

def canonical(x):return " ".join(str(v) for v in [x.get("title",""),x.get("description",""),x.get("propertyType",""),x.get("area",""),x.get("city","")," ".join(x.get("facilities",[]))])
def build(args):
    import faiss,numpy as np
    from sentence_transformers import SentenceTransformer
    rows=json.loads(args.listings.read_text(encoding="utf-8"));eligible=[x for x in rows if x.get("status")=="published" and x.get("available",True)];model=SentenceTransformer(args.model);vectors=np.asarray(model.encode([canonical(x) for x in eligible],normalize_embeddings=True,convert_to_numpy=True),dtype="float32");index=faiss.IndexFlatIP(vectors.shape[1]);index.add(vectors);args.output.mkdir(parents=True,exist_ok=True);faiss.write_index(index,str(args.output/"vectors.faiss"));(args.output/"metadata.json").write_text(json.dumps({"modelVersion":args.version,"ids":[int(x["id"]) for x in eligible],"count":len(eligible)},indent=2),encoding="utf-8")
if __name__=="__main__":
    p=argparse.ArgumentParser();p.add_argument("--listings",type=Path,required=True);p.add_argument("--model",default="sentence-transformers/all-MiniLM-L6-v2");p.add_argument("--version",default="all-MiniLM-L6-v2-base");p.add_argument("--output",type=Path,required=True);build(p.parse_args())
