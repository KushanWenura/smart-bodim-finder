import{useQuery}from'@tanstack/react-query';
import{api}from'../api';
import{BuddyMark,RoleLayout}from'../components/Shell';

type Row={listingId:number;title:string;status:string;views:number;favorites:number;enquiries:number;viewings:number;confirmedRentals:number;viewToEnquiryRate:number|null;priceIntelligence:{available:boolean;label:string;priceVsMedianPercent?:number;confidence:string};recommendations:string[]};

const csvCell=(value:unknown)=>`"${String(value??'').replaceAll('"','""')}"`;

export default function OwnerAnalytics(){
  const{data,isLoading}=useQuery({queryKey:['owner-analytics'],queryFn:async()=>(await api.get('/owner/analytics')).data});
  if(isLoading)return <RoleLayout role="owner"><div className="sb-loading"><span className="spinner-border"/></div></RoleLayout>;
  const summary=data?.summary||{};
  const rows:Row[]=data?.listings||[];
  const exportCsv=()=>{
    const headers=['Listing','Status','Views','Saves','Enquiries','Viewings','Confirmed rentals','View-to-enquiry rate','Price position','Price vs median','Recommendations'];
    const body=rows.map(row=>[row.title,row.status,row.views,row.favorites,row.enquiries,row.viewings,row.confirmedRentals,row.viewToEnquiryRate==null?'':`${Math.round(row.viewToEnquiryRate*100)}%`,row.priceIntelligence.label,row.priceIntelligence.priceVsMedianPercent??'',row.recommendations.join(' | ')]);
    const csv=[headers,...body].map(record=>record.map(csvCell).join(',')).join('\r\n');
    const url=URL.createObjectURL(new Blob([`\ufeff${csv}`],{type:'text/csv;charset=utf-8'}));
    const link=document.createElement('a');
    link.href=url;
    link.download=`bodimbuddy-owner-performance-${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  };
  return <RoleLayout role="owner">
    <div className="client-page-head"><div><span className="client-kicker">Privacy-safe performance</span><h1>See what turns attention into tenants.</h1><p>Counts and conversion signals only—private messages and tenant contact details are never included.</p></div><button type="button" className="btn client-secondary" onClick={exportCsv} disabled={!rows.length}><i className="bi bi-download"/> Export CSV</button></div>
    <div className="client-metrics client-metrics-six">{[['bi-eye','Views',summary.views],['bi-heart','Saves',summary.favorites],['bi-chat-dots','Enquiries',summary.enquiries],['bi-calendar-check','Viewings',summary.viewings],['bi-house-check','Rentals',summary.confirmedRentals],['bi-buildings','Listings',summary.listings]].map(([icon,label,value])=><article key={String(label)}><span><i className={`bi ${icon}`}/></span><div><small>{label}</small><strong>{String(value||0)}</strong></div></article>)}</div>
    <section className="owner-insight-grid">{rows.map(row=><article key={row.listingId}><header><div><span>{row.status.replaceAll('_',' ')}</span><h2>{row.title}</h2></div><b>{row.viewToEnquiryRate==null?'—':`${Math.round(row.viewToEnquiryRate*100)}%`}<small>view → enquiry</small></b></header><div className="owner-funnel"><span><strong>{row.views}</strong><small>views</small></span><i/><span><strong>{row.enquiries}</strong><small>enquiries</small></span><i/><span><strong>{row.viewings}</strong><small>viewings</small></span><i/><span><strong>{row.confirmedRentals}</strong><small>rentals</small></span></div><div className="owner-price-signal"><i className="bi bi-graph-up-arrow"/><span><small>Price position</small><strong>{row.priceIntelligence.label}</strong></span>{row.priceIntelligence.available&&<b>{(row.priceIntelligence.priceVsMedianPercent||0)>0?'+':''}{row.priceIntelligence.priceVsMedianPercent||0}%</b>}</div><ul>{row.recommendations.map(item=><li key={item}><BuddyMark className="is-symbol"/>{item}</li>)}</ul></article>)}</section>
    {!rows.length&&<div className="empty"><h3>Add a listing to begin</h3><p>Performance insights appear after tenants start discovering your property.</p></div>}
  </RoleLayout>
}
