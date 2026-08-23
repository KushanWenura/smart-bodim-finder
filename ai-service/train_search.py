"""Fine-tune the configurable SentenceTransformer using MultipleNegativesRankingLoss."""
from __future__ import annotations
import argparse,json
from pathlib import Path
from pipeline_common import read_jsonl,run_manifest

def train(args):
    from datasets import Dataset
    from sentence_transformers import SentenceTransformer,SentenceTransformerTrainer,SentenceTransformerTrainingArguments,losses
    rows=read_jsonl(args.train);dataset=Dataset.from_list([{"anchor":x["query"],"positive":x["positive"]} for x in rows]);model=SentenceTransformer(args.base_model);loss=losses.MultipleNegativesRankingLoss(model)
    config=SentenceTransformerTrainingArguments(output_dir=str(args.output),num_train_epochs=args.epochs,per_device_train_batch_size=args.batch_size,learning_rate=args.learning_rate,warmup_ratio=.1,seed=args.seed,fp16=False,bf16=False,save_strategy="epoch",logging_steps=10,report_to=[])
    SentenceTransformerTrainer(model=model,args=config,train_dataset=dataset,loss=loss).train();model.save_pretrained(str(args.output))
    manifest=run_manifest(task="semantic-search",baseModel=args.base_model,seed=args.seed,hyperparameters={"epochs":args.epochs,"batchSize":args.batch_size,"learningRate":args.learning_rate},trainSize=len(rows),artifact=str(args.output));(args.output/"training-run.json").write_text(json.dumps(manifest,indent=2),encoding="utf-8")

if __name__=="__main__":
    p=argparse.ArgumentParser();p.add_argument("--train",type=Path,required=True);p.add_argument("--output",type=Path,required=True);p.add_argument("--base-model",default="sentence-transformers/all-MiniLM-L6-v2");p.add_argument("--epochs",type=int,default=2);p.add_argument("--batch-size",type=int,default=16);p.add_argument("--learning-rate",type=float,default=2e-5);p.add_argument("--seed",type=int,default=42);a=p.parse_args();a.output.mkdir(parents=True,exist_ok=True);train(a)
