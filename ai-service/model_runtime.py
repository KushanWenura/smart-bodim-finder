"""Lazy Hugging Face and FAISS runtime. Models load once at process startup."""
from __future__ import annotations
import json
import os
from pathlib import Path
from typing import Any

class ModelRuntime:
    def __init__(self, profile: str = "fixture"):
        self.profile = profile
        self.mode = "fixture-tfidf"
        self.search_version = os.getenv("SEARCH_MODEL_VERSION", "fixture-tfidf-1.0.0")
        self.sentiment_version = os.getenv("SENTIMENT_MODEL_VERSION", "fixture-lexicon-1.0.0")
        self.search_model = None; self.sentiment_model = None; self.faiss = None; self.np = None
        self.ids: list[int] = []; self.vectors = None; self.index = None
        if profile in {"base", "production"}: self._load_models()

    def _load_models(self):
        try:
            import faiss, numpy as np
            from sentence_transformers import SentenceTransformer
            search_path = os.getenv("SEARCH_MODEL_PATH", "sentence-transformers/all-MiniLM-L6-v2")
            local_search_path = Path(search_path)
            if not local_search_path.is_absolute() and (Path(__file__).parent / local_search_path).exists():
                search_path = str((Path(__file__).parent / local_search_path).resolve())
            self.search_model = SentenceTransformer(search_path, device=os.getenv("MODEL_DEVICE", "cpu"))
            self.faiss, self.np = faiss, np
            self.search_version = os.getenv("SEARCH_MODEL_VERSION", search_path)
            self.mode = "huggingface-faiss"
        except Exception as exc:
            if self.profile == "production": raise RuntimeError(f"Production AI models could not load: {exc}") from exc
            self.mode = "fixture-tfidf"
        if self.search_model is not None:
            try:
                self._load_index()
            except Exception as exc:
                self.ids, self.vectors, self.index = [], None, None
                if self.profile == "production": raise RuntimeError(f"Production AI index could not load: {exc}") from exc
        # Review sentiment has a deterministic lexicon fallback in app.py. Keep
        # it optional so the trained retrieval model can serve searches without
        # blocking first startup on a separate network model download.
        if os.getenv("LOAD_SENTIMENT_MODEL", "0").casefold() in {"1", "true", "yes"}:
            try:
                from transformers import pipeline
                sentiment_path = os.getenv("SENTIMENT_MODEL_PATH", "distilbert-base-uncased-finetuned-sst-2-english")
                self.sentiment_model = pipeline("text-classification", model=sentiment_path, device=-1)
                self.sentiment_version = os.getenv("SENTIMENT_MODEL_VERSION", sentiment_path)
            except Exception as exc:
                if self.profile == "production": raise RuntimeError(f"Production sentiment model could not load: {exc}") from exc

    @property
    def model_ready(self): return self.search_model is not None
    @property
    def sentiment_ready(self): return self.sentiment_model is not None
    @property
    def index_ready(self): return self.model_ready and (self.index is not None or not self.ids)
    @property
    def index_size(self): return len(self.ids)

    def model_metadata(self):
        return [{"purpose":"search","version":self.search_version,"ready":self.model_ready,"mode":self.mode},{"purpose":"sentiment","version":self.sentiment_version,"ready":self.sentiment_ready,"mode":self.mode}]

    def _canonical(self, item: dict[str, Any]):
        return " ".join(str(x) for x in [item.get("title",""),item.get("description",""),item.get("propertyType",""),item.get("area",""),item.get("city","")," ".join(item.get("facilities",[]))])

    def rank(self, query: str, listings: list[dict[str, Any]], limit: int):
        if not listings: return []
        vectors=self.search_model.encode([query]+[self._canonical(x) for x in listings],normalize_embeddings=True,convert_to_numpy=True)
        matrix=self.np.asarray(vectors[1:],dtype="float32");query_vector=self.np.asarray(vectors[0:1],dtype="float32")
        eligible_index=self.faiss.IndexFlatIP(matrix.shape[1]);eligible_index.add(matrix)
        scores,order=eligible_index.search(query_vector,max(1,min(limit,50,len(listings))))
        return [{"id":listings[int(i)]["id"],"score":round(float(score),6)} for i,score in zip(order[0],scores[0]) if i>=0]

    def analyze_sentiment(self, text: str):
        result=self.sentiment_model(text[:4000],truncation=True)[0]; label=str(result["label"]).lower(); confidence=float(result["score"])
        if confidence < float(os.getenv("SENTIMENT_UNCERTAIN_THRESHOLD","0.65")): label="uncertain"
        elif "pos" in label or label.endswith("1"): label="positive"
        else: label="negative"
        return {"label":label,"confidence":round(confidence,5),"modelVersion":self.sentiment_version}

    def index_upsert(self, listing_id: int, text: str):
        if not self.model_ready: return {"status":"pending","reason":"model_not_ready"}
        vector=self.search_model.encode([text],normalize_embeddings=True,convert_to_numpy=True)
        if listing_id in self.ids:
            pos=self.ids.index(listing_id); self.vectors[pos]=vector[0]
        else:
            self.ids.append(listing_id); self.vectors=vector if self.vectors is None else self.np.vstack([self.vectors,vector])
        self._rebuild_faiss()
        self._save_index(); return {"status":"indexed","id":listing_id,"indexSize":len(self.ids)}

    def index_delete(self, listing_id: int):
        if listing_id in self.ids:
            pos=self.ids.index(listing_id); self.ids.pop(pos); self.vectors=self.np.delete(self.vectors,pos,axis=0) if len(self.ids) else None; self._rebuild_faiss(); self._save_index()
        return {"status":"deleted","id":listing_id,"indexSize":len(self.ids)}

    def index_rebuild(self, listings: list[dict[str, Any]]):
        if not self.model_ready: return {"status":"pending","reason":"model_not_ready"}
        self.ids=[int(x["id"]) for x in listings]; self.vectors=self.search_model.encode([self._canonical(x) for x in listings],normalize_embeddings=True,convert_to_numpy=True) if listings else None; self._rebuild_faiss(); self._save_index(); return {"status":"indexed","indexSize":len(self.ids)}

    def _paths(self):
        root=Path(os.getenv("INDEX_DIR","../storage/ai-index"));root.mkdir(parents=True,exist_ok=True);return root/"vectors.faiss",root/"metadata.json"
    def _rebuild_faiss(self):
        self.index = None
        if self.vectors is not None and len(self.vectors):
            matrix = self.np.asarray(self.vectors, dtype="float32")
            self.index = self.faiss.IndexFlatIP(matrix.shape[1])
            self.index.add(matrix)
    def _save_index(self):
        vectors,meta=self._paths()
        if self.index is not None:self.faiss.write_index(self.index,str(vectors))
        elif vectors.exists():vectors.unlink()
        meta.write_text(json.dumps({"ids":self.ids,"modelVersion":self.search_version}),encoding="utf-8")
    def _load_index(self):
        vectors,meta=self._paths()
        if vectors.exists() and meta.exists():
            metadata=json.loads(meta.read_text(encoding="utf-8"))
            if metadata.get("modelVersion")==self.search_version:
                self.ids=metadata["ids"];self.index=self.faiss.read_index(str(vectors));self.vectors=self.index.reconstruct_n(0,self.index.ntotal)
