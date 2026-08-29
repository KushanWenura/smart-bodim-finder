import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import { useI18n } from '../i18n';
import { BuddyMark, RoleLayout } from '../components/Shell';
import { AvailabilityCalendar } from '../components/AvailabilityCalendar';

type MiniListing = { id: number; title: string; public_area?: string; city?: string; images?: Array<{ thumbnail_path?: string; storage_path?: string }> };
type Conversation = { id: number; subject: string; listing_id: number; listing?: MiniListing };
type Viewing = { id: number; conversation_id: number; listing_id: number; proposed_at: string; alternative_at?: string; status: string; tenant_note?: string; owner_note?: string; tenant_checked_in_at?:string;owner_checked_in_at?:string;tenant_checked_out_at?:string;owner_checked_out_at?:string;tenant_attendance?:string;owner_attendance?:string; listing?: MiniListing; tenant?: { name: string }; owner?: { name: string } };
type Reservation = { id: number; conversation_id: number; viewing_request_id: number; listing_id: number; move_in_date: string; move_out_date: string; occupants: number; status: string; hold_expires_at?: string; tenant_message?: string; owner_message?: string; agreement?:{id:number;agreement_number:string;status:string;tenant_accepted_at?:string;owner_accepted_at?:string};disputes?:Array<{id:number;reporter_id:number;status:string;category:string}>; listing?: MiniListing; tenant?: { name: string }; owner?: { name: string } };
type JourneyData = { viewings: Viewing[]; reservations: Reservation[] };

const statusLabel = (value: string) => value.replaceAll('_', ' ');
const dateTime = (value?: string) => value ? new Date(value).toLocaleString('en-LK', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Colombo' }) : 'Not set';
const dateOnly = (value?: string) => {
  if (!value) return 'Not set';
  const normalized = /^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00+05:30` : value;
  const parsed = new Date(normalized);
  return Number.isNaN(parsed.getTime()) ? 'Not set' : parsed.toLocaleDateString('en-LK', { dateStyle: 'medium', timeZone: 'Asia/Colombo' });
};
const errorText = (error: unknown) => (error as { message?: string })?.message || 'The request could not be completed.';

export default function RentalJourney() {
  const { user } = useAuth();
  const role = user!.role as 'tenant' | 'owner';
  const {t}=useI18n();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [alternative, setAlternative] = useState<Record<number, string>>({});
  const [shareLinks,setShareLinks]=useState<Record<number,string>>({});
  const [disputeFeedback,setDisputeFeedback]=useState<Record<number,string>>({});
  const { data: journey = { viewings: [], reservations: [] } } = useQuery({ queryKey: ['rental-journey'], queryFn: async () => (await api.get('/rental-journey')).data as JourneyData });
  const { data: conversationPage } = useQuery({ queryKey: ['conversations'], queryFn: async () => (await api.get('/conversations')).data, enabled: role === 'tenant' });
  const conversations: Conversation[] = conversationPage?.data || [];
  const {data:ownerListings=[]} = useQuery<MiniListing[]>({
    queryKey:['owner-listings'],
    queryFn:async()=>{
      const payload=(await api.get('/owner/listings')).data;
      return Array.isArray(payload?.data) ? payload.data : [];
    },
    enabled:role==='owner',
  });
  const selectedConversation = Number(params.get('conversation') || conversations[0]?.id || 0);
  const selected = conversations.find(item => item.id === selectedConversation);
  const selectedViewings = journey.viewings.filter(item => item.conversation_id === selectedConversation);
  const completedViewing = selectedViewings.find(item => item.status === 'completed');
  const activeViewing = selectedViewings.find(item => ['requested', 'accepted', 'alternative_proposed'].includes(item.status));
  const activeReservation = journey.reservations.find(item => item.conversation_id === selectedConversation && ['requested', 'held', 'confirmed'].includes(item.status));
  const calendarListingId=role==='tenant'?selected?.listing_id:Number(params.get('listing')||ownerListings[0]?.id||journey.viewings[0]?.listing_id||0);
  const checkedInAt=(viewing:Viewing)=>role==='tenant'?viewing.tenant_checked_in_at:viewing.owner_checked_in_at;
  const checkedOutAt=(viewing:Viewing)=>role==='tenant'?viewing.tenant_checked_out_at:viewing.owner_checked_out_at;
  const attendanceState=(viewing:Viewing)=>role==='tenant'?viewing.tenant_attendance:viewing.owner_attendance;
  const agreementAccepted=(reservation:Reservation)=>role==='tenant'?Boolean(reservation.agreement?.tenant_accepted_at):Boolean(reservation.agreement?.owner_accepted_at);
  const submittedDispute=(reservation:Reservation)=>reservation.disputes?.find(dispute=>dispute.reporter_id===user!.id);
  const refresh = async (message: string) => { setError(''); setNotice(message); await qc.invalidateQueries({ queryKey: ['rental-journey'] }); await qc.invalidateQueries({ queryKey: ['listing'] }); };
  const viewingRequest = useMutation({ mutationFn: (body: unknown) => api.post(`/conversations/${selectedConversation}/viewings`, body), onSuccess: () => void refresh('Viewing request sent to the owner.'), onError: e => setError(errorText(e)) });
  const reservationRequest = useMutation({ mutationFn: (body: unknown) => api.post(`/conversations/${selectedConversation}/reservations`, body), onSuccess: () => void refresh('Reservation request sent. The listing is not held until the owner accepts it.'), onError: e => setError(errorText(e)) });
  const action = useMutation({ mutationFn: ({ path, body }: { path: string; body?: unknown }) => api.post(path, body || {}), onSuccess: () => void refresh('Rental journey updated.'), onError: e => setError(errorText(e)) });
  const createSafetyLink=async(viewingId:number,event:FormEvent<HTMLFormElement>)=>{event.preventDefault();const f=new FormData(event.currentTarget);try{const response=await api.post(`/viewings/${viewingId}/safety-contact`,{emergencyContactName:f.get('emergencyContactName'),emergencyContactPhone:f.get('emergencyContactPhone')});setShareLinks(current=>({...current,[viewingId]:response.data.shareUrl}));setNotice('Private visit safety link created.')}catch(e){setError(errorText(e))}};
  const submitDispute=async(reservationId:number,event:FormEvent<HTMLFormElement>)=>{event.preventDefault();const form=event.currentTarget;const f=new FormData(form);setDisputeFeedback(current=>({...current,[reservationId]:''}));try{await api.post(`/reservations/${reservationId}/disputes`,{category:f.get('category'),details:f.get('details')});form.reset();setDisputeFeedback(current=>({...current,[reservationId]:'Your problem report was submitted for administrator review.'}));await refresh('Your report was submitted for administrator review.')}catch(e){const message=errorText(e);setDisputeFeedback(current=>({...current,[reservationId]:message}));setError(message)}};

  const submitViewing = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setNotice(''); setError('');
    const fields = new FormData(event.currentTarget); const proposed = String(fields.get('proposedAt') || '');
    viewingRequest.mutate({ proposedAt: new Date(proposed).toISOString(), note: fields.get('note') });
  };
  const submitReservation = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setNotice(''); setError('');
    const fields = new FormData(event.currentTarget);
    reservationRequest.mutate({ viewingId: completedViewing!.id, moveInDate: fields.get('moveInDate'), moveOutDate: fields.get('moveOutDate'), occupants: Number(fields.get('occupants')), message: fields.get('message') });
  };
  const steps = useMemo(() => [
    ['bi-chat-heart', t('askStep'), 'Message the owner without blocking the listing.'],
    ['bi-calendar2-check', t('visitStep'), 'Agree on a real-world viewing time.'],
    ['bi-file-earmark-check', t('requestStep'), 'Request dates only after you are satisfied.'],
    ['bi-hourglass-split', t('holdStep'), 'Owner approval creates a protected 48-hour hold.'],
    ['bi-house-check', t('confirmStep'), 'Tenant confirmation secures the rental period.'],
  ], [t]);

  return <RoleLayout role={role}><div className="journey-page">
    <section className="journey-hero"><div><span className="client-kicker"><i className="bi bi-signpost-split" /> Safe rental journey</span><h1>{t('journeyTitle')}</h1><p>{t('journeyIntro')}</p></div><BuddyMark /></section>
    <div className="journey-steps">{steps.map(([icon, title, copy], index) => <article key={title}><span>{index + 1}</span><i className={`bi ${icon}`} /><div><strong>{title}</strong><small>{copy}</small></div></article>)}</div>
    {notice && <div className="notice notice-info" role="status"><i className="bi bi-check-circle-fill" /> {notice}</div>}
    {error && <div className="notice notice-warning" role="alert"><i className="bi bi-exclamation-triangle-fill" /> {error}</div>}

    {role === 'tenant' && <section className="journey-action-card"><header><div><span>Your next step</span><h2>Continue from an owner conversation</h2></div><Link to="/tenant/messages">Open messages <i className="bi bi-arrow-right" /></Link></header>
      {conversations.length ? <><label className="journey-select">Property conversation<select value={selectedConversation} onChange={event => setParams({ conversation: event.target.value })}>{conversations.map(item => <option value={item.id} key={item.id}>{item.listing?.title || item.subject}</option>)}</select></label>
        {activeViewing ? <div className="journey-guidance"><i className="bi bi-calendar-check" /><div><strong>Your viewing is {statusLabel(activeViewing.status)}</strong><p>{dateTime(activeViewing.status === 'alternative_proposed' ? activeViewing.alternative_at : activeViewing.proposed_at)}. A visit does not reserve the property.</p></div></div>
          : !completedViewing ? <form className="journey-form" onSubmit={submitViewing}><div><label>Preferred viewing time<input type="datetime-local" name="proposedAt" min={new Date(Date.now() + 3600000).toISOString().slice(0, 16)} required /></label><label>Optional note<input name="note" maxLength={500} placeholder="I am available after lectures…" /></label></div><button disabled={viewingRequest.isPending}><i className="bi bi-calendar-plus" /> Request a viewing</button></form>
            : activeReservation ? <div className="journey-guidance"><i className="bi bi-house-check" /><div><strong>Reservation {statusLabel(activeReservation.status)}</strong><p>{dateOnly(activeReservation.move_in_date)} to {dateOnly(activeReservation.move_out_date)}. {activeReservation.status === 'held' ? 'Confirm before the temporary hold expires.' : 'Follow the status below.'}</p></div></div>
              : <form className="journey-form is-reservation" onSubmit={submitReservation}><div><label>Move-in date<input type="date" name="moveInDate" min={new Date().toISOString().slice(0, 10)} required /></label><label>Expected move-out date<input type="date" name="moveOutDate" min={new Date(Date.now() + 86400000).toISOString().slice(0, 10)} required /></label><label>Occupants<input type="number" name="occupants" min="1" defaultValue="1" required /></label><label>Message<input name="message" maxLength={700} placeholder="The viewing went well…" /></label></div><button disabled={reservationRequest.isPending}><i className="bi bi-file-earmark-check" /> Request reservation</button></form>}
      </> : <div className="empty"><h3>Start with a property enquiry</h3><p>Open a listing and send the owner a message. You can schedule the viewing here afterwards.</p><Link className="btn client-primary" to="/search">Explore stays</Link></div>}
    </section>}

    {role==='owner'&&ownerListings.length>0&&<label className="journey-select calendar-listing-select">Manage availability for<select value={calendarListingId} onChange={event=>setParams({listing:event.target.value})}>{ownerListings.map(item=><option value={item.id} key={item.id}>{item.title}</option>)}</select></label>}
    {!!calendarListingId&&<AvailabilityCalendar listingId={calendarListingId} ownerMode={role==='owner'}/>}

    <section className="journey-board"><header><div><span>Viewing calendar</span><h2>{role === 'owner' ? 'Tenant viewing requests' : 'Your property visits'}</h2></div><b>{journey.viewings.length} total</b></header><div className="journey-card-grid">{journey.viewings.map(viewing => <article className="journey-card" key={viewing.id}><div className="journey-card-top"><span className={`journey-status is-${viewing.status}`}>{statusLabel(viewing.status)}</span><small>Viewing #{viewing.id}</small></div><h3>{viewing.listing?.title}</h3><p><i className="bi bi-calendar-event" /> {dateTime(viewing.status === 'alternative_proposed' ? viewing.alternative_at : viewing.proposed_at)}</p><p><i className="bi bi-person" /> {role === 'owner' ? viewing.tenant?.name : viewing.owner?.name}</p>{viewing.owner_note && <blockquote>{viewing.owner_note}</blockquote>}
      <div className="journey-card-actions">{role === 'owner' && viewing.status === 'requested' && <><button onClick={() => action.mutate({ path: `/owner/viewings/${viewing.id}/accept` })}>Accept</button><button className="is-light" onClick={() => action.mutate({ path: `/owner/viewings/${viewing.id}/decline` })}>Decline</button><label>Alternative time<input type="datetime-local" value={alternative[viewing.id] || ''} onChange={e => setAlternative(current => ({ ...current, [viewing.id]: e.target.value }))} /><button disabled={!alternative[viewing.id]} onClick={() => action.mutate({ path: `/owner/viewings/${viewing.id}/propose`, body: { alternativeAt: new Date(alternative[viewing.id]).toISOString() } })}>Suggest</button></label></>}{viewing.status==='accepted'&&<>{!checkedInAt(viewing)&&attendanceState(viewing)!=='other_party_no_show'&&<><button disabled={action.isPending} onClick={()=>action.mutate({path:`/viewings/${viewing.id}/attendance/check-in`})}><i className="bi bi-geo-alt"/> Check in</button><button disabled={action.isPending} className="is-light" onClick={()=>action.mutate({path:`/viewings/${viewing.id}/attendance/no-show`})}>Report no-show</button></>}{checkedInAt(viewing)&&!checkedOutAt(viewing)&&<button disabled={action.isPending} className="is-light" onClick={()=>action.mutate({path:`/viewings/${viewing.id}/attendance/check-out`})}>Check out</button>}{checkedInAt(viewing)&&<span className="journey-attendance"><i className="bi bi-check-circle-fill"/> Checked in {dateTime(checkedInAt(viewing))}</span>}{checkedOutAt(viewing)&&<span className="journey-attendance"><i className="bi bi-door-open-fill"/> Checked out {dateTime(checkedOutAt(viewing))}</span>}{attendanceState(viewing)==='other_party_no_show'&&<span className="journey-attendance is-warning"><i className="bi bi-exclamation-triangle-fill"/> No-show reported</span>}</>}{role === 'owner' && viewing.status === 'accepted' && <button onClick={() => action.mutate({ path: `/owner/viewings/${viewing.id}/complete` })}><i className="bi bi-check2" /> Mark visit completed</button>}{role === 'tenant' && viewing.status === 'alternative_proposed' && <button onClick={() => action.mutate({ path: `/viewings/${viewing.id}/accept-alternative` })}>Accept new time</button>}{role === 'tenant' && ['requested', 'accepted', 'alternative_proposed'].includes(viewing.status) && <button className="is-light" onClick={() => action.mutate({ path: `/viewings/${viewing.id}/cancel` })}>Cancel</button>}</div>{role==='tenant'&&viewing.status==='accepted'&&<details className="journey-details"><summary><i className="bi bi-shield-check"/> Visit safety tools</summary><form onSubmit={event=>void createSafetyLink(viewing.id,event)}><label>Trusted contact name<input name="emergencyContactName" required maxLength={120}/></label><label>Trusted contact mobile<input name="emergencyContactPhone" required placeholder="0771234567"/></label><button>Create private safety link</button></form>{shareLinks[viewing.id]&&<div className="share-link"><strong>Share only with your trusted contact</strong><input readOnly value={shareLinks[viewing.id]}/></div>}</details>}</article>)}{!journey.viewings.length && <div className="empty"><p>No viewing requests yet.</p></div>}</div></section>

    <section className="journey-board"><header><div><span>Reservation desk</span><h2>{role === 'owner' ? 'Requests and active rentals' : 'Your reservation history'}</h2></div><b>{journey.reservations.length} total</b></header><div className="journey-card-grid">{journey.reservations.map(reservation => <article className="journey-card is-reservation" key={reservation.id}><div className="journey-card-top"><span className={`journey-status is-${reservation.status}`}>{statusLabel(reservation.status)}</span><small>Reservation #{reservation.id}</small></div><h3>{reservation.listing?.title}</h3><div className="journey-dates"><span><small>Move in</small><strong>{dateOnly(reservation.move_in_date)}</strong></span><i className="bi bi-arrow-right" /><span><small>Move out</small><strong>{dateOnly(reservation.move_out_date)}</strong></span></div><p><i className="bi bi-people" /> {reservation.occupants} {reservation.occupants === 1 ? 'occupant' : 'occupants'}</p>{reservation.hold_expires_at && reservation.status === 'held' && <div className="journey-hold"><i className="bi bi-hourglass-split" /><span><strong>48-hour protected hold</strong><small>Expires {dateTime(reservation.hold_expires_at)}</small></span></div>}
      <div className="journey-card-actions">{role === 'owner' && reservation.status === 'requested' && <><button onClick={() => action.mutate({ path: `/owner/reservations/${reservation.id}/accept` })}>Start 48-hour hold</button><button className="is-light" onClick={() => action.mutate({ path: `/owner/reservations/${reservation.id}/decline` })}>Decline</button></>}{role === 'tenant' && reservation.status === 'held' && <button onClick={() => action.mutate({ path: `/reservations/${reservation.id}/confirm` })}><i className="bi bi-house-check" /> Confirm reservation</button>}{role === 'tenant' && ['requested', 'held', 'confirmed'].includes(reservation.status) && <button className="is-light" onClick={() => action.mutate({ path: `/reservations/${reservation.id}/cancel` })}>Cancel</button>}{role === 'owner' && ['held', 'confirmed'].includes(reservation.status) && <button className="is-light" onClick={() => action.mutate({ path: `/owner/reservations/${reservation.id}/cancel` })}>Cancel reservation</button>}{['confirmed','completed'].includes(reservation.status)&&<>{agreementAccepted(reservation)?<span className="journey-agreement-accepted"><i className="bi bi-check-circle-fill"/> Agreement accepted by you</span>:<button disabled={action.isPending} onClick={()=>action.mutate({path:`/reservations/${reservation.id}/agreement/accept`,body:{confirm:true}})}><i className="bi bi-pen"/> Accept agreement</button>}<a className="journey-download" href={`/api/v1/reservations/${reservation.id}/agreement.pdf`}><i className="bi bi-file-earmark-pdf"/> Download PDF</a></>}</div>{reservation.agreement&&<div className="agreement-status"><i className="bi bi-file-earmark-check"/><span><strong>{reservation.agreement.agreement_number}</strong><small>{statusLabel(reservation.agreement.status)} · Tenant {reservation.agreement.tenant_accepted_at?'accepted':'pending'} · Owner {reservation.agreement.owner_accepted_at?'accepted':'pending'}</small></span></div>}{['confirmed','completed','cancelled'].includes(reservation.status)&&(submittedDispute(reservation)?<div className="journey-dispute-status"><i className="bi bi-shield-check"/><span><strong>Problem report already submitted</strong><small>{statusLabel(submittedDispute(reservation)!.category)} · {statusLabel(submittedDispute(reservation)!.status)}</small></span></div>:<details className="journey-details"><summary><i className="bi bi-flag"/> Report a rental problem</summary><form onSubmit={event=>void submitDispute(reservation.id,event)}><label>Issue<select name="category"><option value="no_show">No-show</option><option value="misrepresentation">Listing misrepresentation</option><option value="payment">Payment disagreement</option><option value="conduct">Unsafe conduct</option><option value="property_condition">Property condition</option><option value="other">Other</option></select></label><label>Details<textarea name="details" required minLength={20} maxLength={3000}/></label>{disputeFeedback[reservation.id]&&<div className="journey-inline-feedback">{disputeFeedback[reservation.id]}</div>}<button>Submit for admin review</button></form></details>)}</article>)}{!journey.reservations.length && <div className="empty"><p>No reservation requests yet.</p></div>}</div></section>
  </div></RoleLayout>;
}
