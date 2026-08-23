import importlib.util
import json
import pathlib

import faiss
import numpy as np

MODULE_PATH=pathlib.Path(__file__).parents[1]/"model_runtime.py"
SPEC=importlib.util.spec_from_file_location("smart_bodim_model_runtime",MODULE_PATH)
module=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(module)


class FakeEmbeddingModel:
    def encode(self,texts,normalize_embeddings=True,convert_to_numpy=True):
        rows=[]
        for text in texts:
            value=text.casefold();vector=np.array([1.0,float("wifi" in value),float("quiet" in value),float("parking" in value)],dtype="float32")
            if normalize_embeddings:vector=vector/np.linalg.norm(vector)
            rows.append(vector)
        return np.asarray(rows,dtype="float32")


def runtime(tmp_path,monkeypatch,version="test-search-v1"):
    monkeypatch.setenv("INDEX_DIR",str(tmp_path));item=module.ModelRuntime("fixture");item.search_model=FakeEmbeddingModel();item.faiss=faiss;item.np=np;item.search_version=version;item.mode="test-faiss";return item


def test_faiss_build_rank_upsert_delete_and_reload(tmp_path,monkeypatch):
    item=runtime(tmp_path,monkeypatch);listings=[{"id":1,"title":"Quiet WiFi room"},{"id":2,"title":"Parking house"}]
    assert item.index_rebuild(listings)=={"status":"indexed","indexSize":2}
    assert item.index_ready and item.index.ntotal==2 and (tmp_path/"vectors.faiss").exists()
    assert item.rank("quiet wifi",listings,2)[0]["id"]==1
    assert item.index_upsert(3,"quiet annex")["indexSize"]==3
    assert item.index_delete(2)["indexSize"]==2
    loaded=runtime(tmp_path,monkeypatch);loaded._load_index();assert loaded.ids==[1,3] and loaded.index.ntotal==2


def test_index_version_mismatch_is_not_loaded(tmp_path,monkeypatch):
    item=runtime(tmp_path,monkeypatch,"v1");item.index_rebuild([{"id":1,"title":"Room"}]);metadata=json.loads((tmp_path/"metadata.json").read_text());assert metadata["modelVersion"]=="v1"
    different=runtime(tmp_path,monkeypatch,"v2");different._load_index();assert different.ids==[] and different.index is None
