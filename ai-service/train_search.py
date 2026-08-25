"""Fine-tune SentenceTransformer embeddings with branch-aware hard negatives."""
from __future__ import annotations
import argparse,json,math,random
from pathlib import Path
from pipeline_common import read_jsonl,run_manifest

def train(args):
    import torch
    import torch.nn.functional as functional
    from sentence_transformers import SentenceTransformer

    rows=read_jsonl(args.train)
    random.seed(args.seed);torch.manual_seed(args.seed)
    model=SentenceTransformer(args.base_model);model.train()
    optimizer=torch.optim.AdamW(model.parameters(),lr=args.learning_rate)
    margin=.20;loss_history=[]
    for epoch in range(args.epochs):
        shuffled=list(rows);random.Random(args.seed+epoch).shuffle(shuffled)
        epoch_losses=[]
        for start in range(0,len(shuffled),args.batch_size):
            batch=shuffled[start:start+args.batch_size]
            def embed(field):
                features={key:value.to(model.device) for key,value in model.tokenize([row[field] for row in batch]).items()}
                return model(features)["sentence_embedding"]
            anchor=embed("query");positive=embed("positive");negative=embed("negative")
            positive_distance=1-functional.cosine_similarity(anchor,positive)
            negative_distance=1-functional.cosine_similarity(anchor,negative)
            loss=torch.relu(positive_distance-negative_distance+margin).mean()
            optimizer.zero_grad();loss.backward();torch.nn.utils.clip_grad_norm_(model.parameters(),1.0);optimizer.step()
            epoch_losses.append(float(loss.detach().cpu()))
        mean_loss=sum(epoch_losses)/max(len(epoch_losses),1);loss_history.append(round(mean_loss,6))
        print(json.dumps({"epoch":epoch+1,"epochs":args.epochs,"steps":math.ceil(len(shuffled)/args.batch_size),"meanLoss":mean_loss}))
    readme_path=args.output/"README.md";preserved_readme=readme_path.read_text(encoding="utf-8") if readme_path.exists() else None
    model.save_pretrained(str(args.output))
    if preserved_readme is not None:readme_path.write_text(preserved_readme,encoding="utf-8")
    manifest=run_manifest(task="semantic-search",baseModel=args.base_model,loss="CosineTripletMarginLoss",margin=margin,seed=args.seed,hyperparameters={"epochs":args.epochs,"batchSize":args.batch_size,"learningRate":args.learning_rate},trainSize=len(rows),epochMeanLoss=loss_history,artifact=str(args.output));(args.output/"training-run.json").write_text(json.dumps(manifest,indent=2),encoding="utf-8")

if __name__=="__main__":
    p=argparse.ArgumentParser();p.add_argument("--train",type=Path,required=True);p.add_argument("--output",type=Path,required=True);p.add_argument("--base-model",default="sentence-transformers/all-MiniLM-L6-v2");p.add_argument("--epochs",type=int,default=2);p.add_argument("--batch-size",type=int,default=16);p.add_argument("--learning-rate",type=float,default=2e-5);p.add_argument("--seed",type=int,default=42);a=p.parse_args();a.output.mkdir(parents=True,exist_ok=True);train(a)
