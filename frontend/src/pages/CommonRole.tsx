import {useMutation,useQuery,useQueryClient} from '@tanstack/react-query';
import {FormEvent,useEffect,useState} from 'react';
import {api} from '../api';
import {useAuth} from '../auth';
import {RoleLayout} from '../components/Shell';

type Conversation={id:number;subject:string;listing?:{title:string};messages?:Message[]};
type Message={id:number;sender_id:number;body:string;created_at:string};

export function Messages(){
  const{user}=useAuth();const qc=useQueryClient();const[selected,setSelected]=useState<number|null>(null);
  const{data}=useQuery({queryKey:['conversations'],queryFn:async()=>(await api.get('/conversations')).data});
  const items:Conversation[]=data?.data||[];
  useEffect(()=>{if(!selected&&items[0])setSelected(items[0].id)},[items,selected]);
  const{data:thread,isLoading}=useQuery({queryKey:['messages',selected],enabled:!!selected,queryFn:async()=>(await api.get(`/conversations/${selected}/messages`)).data.data});
  const send=useMutation({mutationFn:(text:string)=>api.post(`/conversations/${selected}/messages`,{text}),onSuccess:async()=>{await qc.invalidateQueries({queryKey:['messages',selected]});await qc.invalidateQueries({queryKey:['conversations']})}});
  const messages:Message[]=[...(thread?.data||[])].reverse();const active=items.find(x=>x.id===selected);
  const submit=(e:FormEvent<HTMLFormElement>)=>{e.preventDefault();const form=e.currentTarget;const text=String(new FormData(form).get('text')||'').trim();if(text.length>=2){send.mutate(text);form.reset()}};
  return <RoleLayout role={user!.role}><div className="dash-head"><div><span className="eyebrow">Private conversations</span><h1>Messages</h1></div></div><div className="message-layout"><div className="conversation-list">{items.map(c=><button type="button" className={`conversation ${selected===c.id?'active':''}`} key={c.id} onClick={()=>{setSelected(c.id);void api.post(`/conversations/${c.id}/read`)}}><div className="avatar">✉</div><div className="conversation-copy"><strong>{c.subject}</strong><span>{c.listing?.title}</span></div></button>)}{!items.length&&<div className="empty"><p>No conversations yet.</p></div>}</div><div className="chat">{active?<><div className="chat-head"><strong>{active.subject}</strong><small>{active.listing?.title}</small></div><div className="chat-messages" aria-live="polite">{isLoading?<div className="skeleton"/>:messages.map(m=><div className={`bubble ${m.sender_id===user?.id?'me':''}`} key={m.id}>{m.body}<time>{new Date(m.created_at).toLocaleString('en-LK',{timeZone:'Asia/Colombo'})}</time></div>)}</div><form className="chat-compose" onSubmit={submit}><label className="sr-only" htmlFor="reply">Reply</label><input id="reply" className="input" name="text" minLength={2} maxLength={2000} required placeholder="Write a reply…"/><button className="btn btn-primary" disabled={send.isPending}>Send</button></form></>:<div className="empty"><h3>Select a conversation</h3></div>}</div></div></RoleLayout>
}

type Note={id:string;data:{title:string;message:string;link?:string};read_at?:string;created_at:string};
export function Notifications(){
  const{user}=useAuth();const qc=useQueryClient();const{data}=useQuery({queryKey:['notifications'],queryFn:async()=>(await api.get('/notifications')).data});const items:Note[]=data?.data||[];
  const read=useMutation({mutationFn:(id:string)=>api.post(`/notifications/${id}/read`),onSuccess:()=>qc.invalidateQueries({queryKey:['notifications']})});
  const remove=useMutation({mutationFn:(id:string)=>api.delete(`/notifications/${id}`),onSuccess:()=>qc.invalidateQueries({queryKey:['notifications']})});
  const all=useMutation({mutationFn:()=>api.post('/notifications/read-all'),onSuccess:()=>qc.invalidateQueries({queryKey:['notifications']})});
  return <RoleLayout role={user!.role}><div className="dash-head"><div><span className="eyebrow">Stay up to date</span><h1>Notifications</h1></div><button className="btn btn-ghost" onClick={()=>all.mutate()} disabled={all.isPending}>Mark all read</button></div><div className="panel">{items.map(n=><div className={`notification ${n.read_at?'':'unread'}`} key={n.id}><span className="notif-icon">♢</span><div><strong>{n.data.title}</strong><p>{n.data.message}</p><time>{new Date(n.created_at).toLocaleString('en-LK',{timeZone:'Asia/Colombo'})}</time></div><div className="table-actions">{!n.read_at&&<button className="btn btn-ghost btn-sm" onClick={()=>read.mutate(n.id)}>Read</button>}<button className="btn btn-danger btn-sm" onClick={()=>remove.mutate(n.id)}>Delete</button></div></div>)}{!items.length&&<div className="empty"><h3>You’re all caught up</h3></div>}</div></RoleLayout>
}
