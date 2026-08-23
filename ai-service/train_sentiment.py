"""Fine-tune DistilBERT sentiment classification with deterministic run metadata."""
from __future__ import annotations
import argparse,json
from pathlib import Path
from pipeline_common import read_jsonl,run_manifest

def train(args):
    import numpy as np
    from datasets import Dataset
    from sklearn.metrics import accuracy_score,precision_recall_fscore_support
    from transformers import AutoModelForSequenceClassification,AutoTokenizer,DataCollatorWithPadding,Trainer,TrainingArguments
    labels={"negative":0,"positive":1};rows=[x for x in read_jsonl(args.train) if x["label"] in labels];valid=[x for x in read_jsonl(args.validation) if x["label"] in labels];tokenizer=AutoTokenizer.from_pretrained(args.base_model)
    def encode(batch):return tokenizer(batch["text"],truncation=True,max_length=256)
    def dataset(values):return Dataset.from_list([{"text":x["text"],"label":labels[x["label"]]} for x in values]).map(encode,batched=True)
    model=AutoModelForSequenceClassification.from_pretrained(args.base_model,num_labels=2,id2label={0:"negative",1:"positive"},label2id=labels)
    def metrics(pred):truth=pred.label_ids;guess=np.argmax(pred.predictions,axis=-1);precision,recall,f1,_=precision_recall_fscore_support(truth,guess,average="weighted",zero_division=0);return {"accuracy":accuracy_score(truth,guess),"precision":precision,"recall":recall,"f1":f1}
    config=TrainingArguments(output_dir=str(args.output),num_train_epochs=args.epochs,per_device_train_batch_size=args.batch_size,learning_rate=args.learning_rate,seed=args.seed,eval_strategy="epoch",save_strategy="epoch",report_to=[])
    trainer=Trainer(model=model,args=config,train_dataset=dataset(rows),eval_dataset=dataset(valid),processing_class=tokenizer,data_collator=DataCollatorWithPadding(tokenizer),compute_metrics=metrics);trainer.train();trainer.save_model(str(args.output));tokenizer.save_pretrained(str(args.output))
    manifest=run_manifest(task="review-sentiment",baseModel=args.base_model,seed=args.seed,hyperparameters={"epochs":args.epochs,"batchSize":args.batch_size,"learningRate":args.learning_rate},trainSize=len(rows),validationSize=len(valid),artifact=str(args.output));(args.output/"training-run.json").write_text(json.dumps(manifest,indent=2),encoding="utf-8")

if __name__=="__main__":
    p=argparse.ArgumentParser();p.add_argument("--train",type=Path,required=True);p.add_argument("--validation",type=Path,required=True);p.add_argument("--output",type=Path,required=True);p.add_argument("--base-model",default="distilbert-base-uncased-finetuned-sst-2-english");p.add_argument("--epochs",type=int,default=2);p.add_argument("--batch-size",type=int,default=16);p.add_argument("--learning-rate",type=float,default=2e-5);p.add_argument("--seed",type=int,default=42);a=p.parse_args();a.output.mkdir(parents=True,exist_ok=True);train(a)
