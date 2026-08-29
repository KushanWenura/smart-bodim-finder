import {useMutation,useQuery,useQueryClient} from '@tanstack/react-query';
import {FormEvent,useEffect,useState} from 'react';
import {Link} from 'react-router-dom';
import {api} from '../api';
import {useAuth} from '../auth';
import {ListingCard} from '../components/ListingCard';
import {BuddyMark,RoleLayout} from '../components/Shell';
import type {Listing} from '../types';

export function TenantDashboard(){
  const{user}=useAuth();
  const{data=[]}=useQuery({queryKey:['featured'],queryFn:async()=>(await api.get('/listings/featured')).data.data as Listing[]});
  const openAi=(message?:string)=>window.dispatchEvent(new CustomEvent('smartbodim:open-assistant',{detail:message?{message}:undefined}));
  const actions=[
    ['/nearby','bi-geo-alt','Near campus or work','Rank stays by real distance'],
    ['/tenant/favorites','bi-heart','Saved places','Review your shortlist'],
    ['/tenant/messages','bi-chat-dots','Messages','Continue owner conversations'],
    ['/tenant/journey','bi-signpost-split','Rental journey','Visits, holds and reservations'],
    ['/search','bi-sliders','Explore listings','Search with detailed filters'],
  ];
  return <RoleLayout role="tenant">
    <section className="client-welcome">
      <div className="client-welcome-copy"><span className="client-kicker"><BuddyMark className="is-symbol"/> Your personal stay Buddy</span><h1>Welcome back, {user?.name.split(' ')[0]}.</h1><p>Tell Buddy where you study or work, what you can spend and what you need nearby. It will turn that into a practical shortlist.</p><div className="client-welcome-actions"><button className="btn client-primary" onClick={()=>openAi()}><BuddyMark className="is-symbol"/> Ask Buddy</button><Link className="btn client-secondary" to="/nearby"><i className="bi bi-signpost-split"/> Find by destination</Link></div>
      </div>
      <aside className="client-prompt"><div className="client-prompt-icon"><i className="bi bi-chat-quote"/></div><span>Try a natural request</span><strong>Find a WiFi room within 10 km of University of Moratuwa under Rs. 35,000.</strong><button onClick={()=>openAi('Find a WiFi room within 10 km of University of Moratuwa under Rs. 35,000')}>Use this request <i className="bi bi-arrow-right"/></button></aside>
    </section>
    <nav className="client-action-grid" aria-label="Tenant quick actions">{actions.map(([to,icon,title,description])=><Link to={to} key={to}><span><i className={`bi ${icon}`}/></span><div><strong>{title}</strong><small>{description}</small></div><i className="bi bi-arrow-up-right"/></Link>)}</nav>
    <section className="client-content-card"><header className="client-section-head"><div><span className="client-kicker">Selected for you</span><h2>Trusted places worth exploring</h2><p>Recently published stays with clear pricing and owner details.</p></div><Link to="/search">View all listings <i className="bi bi-arrow-right"/></Link></header>{data.length?<div className="cards">{data.slice(0,3).map(x=><ListingCard key={x.id} item={x}/>)}</div>:<div className="empty"><h3>No recommendations yet</h3><p>Use the smart finder to build your first shortlist.</p></div>}</section>
  </RoleLayout>
}

export function Favorites(){const qc=useQueryClient();const{data=[]}=useQuery({queryKey:['favorites'],queryFn:async()=>(await api.get('/favorites')).data.data as Listing[]});const remove=useMutation({mutationFn:(id:number)=>api.delete(`/favorites/${id}`),onSuccess:()=>qc.invalidateQueries({queryKey:['favorites']})});return <RoleLayout role="tenant"><div className="dash-head"><div><span className="eyebrow">Your shortlist</span><h1>Saved places</h1></div>{data.length>1&&<Link className="btn btn-primary" to="/tenant/compare">Compare saved places</Link>}</div>{data.length?<div className="cards">{data.map(x=><ListingCard key={x.id} item={x} favorite onFavorite={id=>remove.mutate(id)}/>)}</div>:<div className="empty"><h3>No saved places yet</h3><p>Browse listings and select the heart icon to build a shortlist.</p></div>}</RoleLayout>}

type Saved={id:number;name:string;natural_query:string;filters:Record<string,unknown>;notifications_enabled:boolean};
export function SavedSearches(){const qc=useQueryClient();const[open,setOpen]=useState(false);const{data=[]}=useQuery({queryKey:['saved-searches'],queryFn:async()=>(await api.get('/saved-searches')).data.data as Saved[]});const create=useMutation({mutationFn:(body:unknown)=>api.post('/saved-searches',body),onSuccess:()=>{setOpen(false);void qc.invalidateQueries({queryKey:['saved-searches']})}});const remove=useMutation({mutationFn:(id:number)=>api.delete(`/saved-searches/${id}`),onSuccess:()=>qc.invalidateQueries({queryKey:['saved-searches']})});const submit=(e:FormEvent<HTMLFormElement>)=>{e.preventDefault();const f=new FormData(e.currentTarget);create.mutate({name:f.get('name'),query:f.get('query'),filters:{city:f.get('city'),maxPrice:Number(f.get('maxPrice')||0)||null},notificationsEnabled:true})};return <RoleLayout role="tenant"><div className="dash-head"><div><span className="eyebrow">Matching alerts</span><h1>Saved searches</h1></div><button className="btn btn-primary" onClick={()=>setOpen(true)}>New saved search</button></div><div className="panel">{data.map(x=><div className="list-row" key={x.id}><div className="notif-icon">⌕</div><div className="list-copy"><strong>{x.name}</strong><span>{x.natural_query||Object.entries(x.filters).filter(([,v])=>v).map(([k,v])=>`${k}: ${v}`).join(' • ')}</span></div><span className="status status-visible">{x.notifications_enabled?'alerts on':'alerts off'}</span><button className="btn btn-danger btn-sm" onClick={()=>remove.mutate(x.id)}>Delete</button></div>)}{!data.length&&<div className="empty"><p>No saved searches yet.</p></div>}</div>{open&&<div className="modal-backdrop" onMouseDown={e=>e.target===e.currentTarget&&setOpen(false)}><form className="modal" onSubmit={submit}><h2>Save a property search</h2><div className="form"><label className="field">Name<input className="input" name="name" required maxLength={120}/></label><label className="field">Natural-language query<input className="input" name="query" maxLength={500}/></label><div className="form-row"><label className="field">City<input className="input" name="city"/></label><label className="field">Maximum LKR<input className="input" name="maxPrice" type="number" min="0"/></label></div></div><div className="modal-actions"><button type="button" className="btn btn-ghost" onClick={()=>setOpen(false)}>Cancel</button><button className="btn btn-primary">Save and enable alerts</button></div></form></div>}</RoleLayout>}

type DecisionRow={listingId:number;title:string;rank:number;score:number;monthlyCost:{rent:number;utilities:number;meals:number;transport:number;total:number};distanceKm?:number;rating:number;ownerVerified:boolean;facilities:string[];reasons:string[];disclaimer:string};
export function Compare(){
  const{data=[]}=useQuery({queryKey:['favorites'],queryFn:async()=>(await api.get('/favorites')).data.data as Listing[]});
  const{data:destinations=[]}=useQuery({queryKey:['destinations'],queryFn:async()=>(await api.get('/destinations')).data.data as Array<{id:number;name:string}>});
  const[selectedIds,setSelectedIds]=useState<number[]>([]);
  const[decision,setDecision]=useState<{data:DecisionRow[];recommendation:string;method:string}|null>(null);
  useEffect(()=>{
    if(data.length&&selectedIds.length===0)setSelectedIds(data.slice(0,Math.min(4,data.length)).map(item=>item.id));
  },[data,selectedIds.length]);
  const items=data.filter(item=>selectedIds.includes(item.id)).slice(0,4);
  const compare=useMutation({mutationFn:async(body:unknown)=>(await api.post('/decision-support/compare',body)).data,onSuccess:setDecision});
  const toggle=(id:number)=>{
    setDecision(null);
    setSelectedIds(current=>current.includes(id)?current.filter(item=>item!==id):current.length<4?[...current,id]:current);
  };
  const run=(event:FormEvent<HTMLFormElement>)=>{
    event.preventDefault();
    const form=new FormData(event.currentTarget);
    compare.mutate({listingIds:items.map(item=>item.id),destinationId:Number(form.get('destinationId'))||null,maxMonthlyTotalLkr:Number(form.get('maxMonthlyTotalLkr'))||null});
  };
  const money=(value:number)=>`Rs. ${value.toLocaleString('en-LK')}`;
  const rows:Array<[string,(item:Listing)=>string]>=[['Monthly rent',item=>money(item.price)],['Location',item=>`${item.area}, ${item.city}`],['Type',item=>item.propertyType.replaceAll('_',' ')],['Gender rule',item=>item.genderRule.replaceAll('_',' ')],['Occupancy',item=>String(item.occupancy)],['Facilities',item=>item.facilities.join(', ')||'—'],['Rating',item=>`${item.rating} / 5`],['Available',item=>item.available?'Yes':'No']];
  return <RoleLayout role="tenant">
    <div className="dash-head"><div><span className="eyebrow">Buddy decision lab</span><h1>Compare the real monthly picture</h1><p>Choose two to four saved places. Buddy compares estimated living cost, commute, essentials and trust evidence.</p></div></div>
    {data.length<2?<div className="empty"><h3>Save at least two listings to compare</h3><p>Your saved places will appear here automatically.</p></div>:<>
      <section className="compare-picker"><header><div><span className="client-kicker">Build your comparison</span><h2>Select 2–4 places</h2></div><strong className={items.length>=2?'is-ready':''}>{items.length} selected</strong></header><div>{data.map(item=>{const selected=selectedIds.includes(item.id);const limitReached=!selected&&selectedIds.length>=4;return <button type="button" className={selected?'is-selected':''} aria-pressed={selected} disabled={limitReached} onClick={()=>toggle(item.id)} key={item.id}><img src={item.image||item.images?.[0]?.thumbnail||item.images?.[0]?.url} alt=""/><span><strong>{item.title}</strong><small>{item.area}, {item.city} · {money(item.price)}</small></span><i className={`bi ${selected?'bi-check-circle-fill':'bi-plus-circle'}`}/></button>})}</div><small><i className="bi bi-info-circle"/> Select a place again to remove it. A maximum of four keeps the table easy to read.</small></section>
      <form className="compare-intelligence-form" onSubmit={run}><label>Campus or workplace<select name="destinationId"><option value="">No destination selected</option>{destinations.map(destination=><option key={destination.id} value={destination.id}>{destination.name}</option>)}</select></label><label>Maximum total monthly budget<input type="number" name="maxMonthlyTotalLkr" min="5000" placeholder="e.g. 55000"/></label><button disabled={compare.isPending||items.length<2}><BuddyMark className="is-symbol"/> Build Buddy comparison</button></form>
      {items.length<2&&<div className="notice notice-info">Choose at least two saved places before building the comparison.</div>}
      {decision&&<section className="decision-summary"><header><BuddyMark className="is-symbol"/><div><span>Buddy’s transparent recommendation</span><h2>{decision.recommendation}</h2><p>{decision.method}</p></div></header><div className="decision-grid">{decision.data.map(row=><article className={row.rank===1?'is-winner':''} key={row.listingId}><span>#{row.rank} · {row.score}% fit</span><h3><Link to={`/listing/${row.listingId}`}>{row.title}</Link></h3><strong>{money(row.monthlyCost.total)} <small>estimated monthly total</small></strong><div><small>Rent {money(row.monthlyCost.rent)}</small><small>Utilities {money(row.monthlyCost.utilities)}</small><small>Meals {money(row.monthlyCost.meals)}</small><small>Transport {money(row.monthlyCost.transport)}</small></div><ul>{row.reasons.map(reason=><li key={reason}><i className="bi bi-check2"/>{reason}</li>)}</ul></article>)}</div><small className="decision-disclaimer">{decision.data[0]?.disclaimer}</small></section>}
      {items.length>=2&&<div className="table-wrap"><table className="table compare-table"><thead><tr><th>Attribute</th>{items.map(item=><th key={item.id}><Link to={`/listing/${item.id}`}>{item.title}</Link></th>)}</tr></thead><tbody>{rows.map(([label,read])=><tr key={label}><th>{label}</th>{items.map(item=><td key={item.id}>{read(item)}</td>)}</tr>)}</tbody></table></div>}
    </>}
  </RoleLayout>
}

type MyReview={id:number;rating:number;body:string;moderation_status:string;listing:{id:number;title:string}};
export function MyReviews(){const qc=useQueryClient();const{data=[]}=useQuery({queryKey:['my-reviews'],queryFn:async()=>(await api.get('/my-reviews')).data.data as MyReview[]});const remove=useMutation({mutationFn:(id:number)=>api.delete(`/reviews/${id}`),onSuccess:()=>qc.invalidateQueries({queryKey:['my-reviews']})});return <RoleLayout role="tenant"><div className="dash-head"><div><span className="eyebrow">Your opinions</span><h1>My reviews</h1></div></div><div className="panel">{data.map(r=><article className="review" key={r.id}><div className="review-head"><Link to={`/listing/${r.listing.id}`}><strong>{r.listing.title}</strong></Link><span className="stars">{'★'.repeat(r.rating)}</span></div><p>{r.body}</p><div className="table-actions"><span className={`status status-${r.moderation_status}`}>{r.moderation_status}</span><button className="btn btn-danger btn-sm" onClick={()=>remove.mutate(r.id)}>Delete</button></div></article>)}{!data.length&&<div className="empty"><p>You have not reviewed a property.</p></div>}</div></RoleLayout>}
