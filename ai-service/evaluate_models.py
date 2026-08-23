"""Evaluate trained/base search and sentiment artifacts; writes JSON and Markdown reports."""
from __future__ import annotations
import argparse,json
from pathlib import Path
from pipeline_common import read_jsonl,run_manifest

def evaluate(args):
    import numpy as np
    from sentence_transformers import SentenceTransformer
    from sklearn.metrics import accuracy_score,classification_report,confusion_matrix
    from transformers import pipeline
    search_rows=read_jsonl(args.search_test);model=SentenceTransformer(args.search_model);rr=[];baseline=[]
    for row in search_rows:
        docs=[row["positive"],row["negative"]];vec=model.encode([row["query"]]+docs,normalize_embeddings=True,convert_to_numpy=True);order=np.argsort(-(vec[1:]@vec[0])).tolist();rr.append(1/(order.index(0)+1));terms=set(row["query"].casefold().split());scores=[len(terms&set(x.casefold().split())) for x in docs];base=sorted(range(2),key=lambda i:-scores[i]);baseline.append(1/(base.index(0)+1))
    reviews=[x for x in read_jsonl(args.review_test) if x["label"] in {"positive","negative"}];classifier=pipeline("text-classification",model=args.sentiment_model,device=-1);truth=[x["label"] for x in reviews];pred=[]
    for out in classifier([x["text"] for x in reviews],truncation=True):pred.append("positive" if "pos" in out["label"].lower() or out["label"].endswith("1") else "negative")
    report=run_manifest(profile="trained-or-base",search={"queryCount":len(rr),"mrr":sum(rr)/max(len(rr),1),"keywordBaselineMrr":sum(baseline)/max(len(baseline),1)},sentiment={"testSize":len(truth),"accuracy":accuracy_score(truth,pred),"perClass":classification_report(truth,pred,output_dict=True,zero_division=0),"confusionMatrix":confusion_matrix(truth,pred,labels=["negative","positive"]).tolist()})
    args.output.parent.mkdir(parents=True,exist_ok=True);args.output.write_text(json.dumps(report,indent=2),encoding="utf-8");args.output.with_suffix(".md").write_text(f"# Model evaluation\n\n- Search MRR: {report['search']['mrr']:.4f}\n- Keyword baseline MRR: {report['search']['keywordBaselineMrr']:.4f}\n- Sentiment accuracy: {report['sentiment']['accuracy']:.4f}\n",encoding="utf-8")
if __name__=="__main__":
    p=argparse.ArgumentParser();p.add_argument("--search-test",type=Path,required=True);p.add_argument("--review-test",type=Path,required=True);p.add_argument("--search-model",required=True);p.add_argument("--sentiment-model",required=True);p.add_argument("--output",type=Path,required=True);evaluate(p.parse_args())
