import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import { ListingCard } from '../components/ListingCard';
import { AreaSafetyInsight } from '../components/AreaSafetyInsight';
import { AvailabilityCalendar } from '../components/AvailabilityCalendar';
import { NeighbourhoodExplorer } from '../components/NeighbourhoodExplorer';
import { PriceIntelligence, type PriceInsight } from '../components/PriceIntelligence';
import { BuddyMark, PublicLayout } from '../components/Shell';
import type { Listing } from '../types';

const money = (value: number) => new Intl.NumberFormat('en-LK', { style: 'currency', currency: 'LKR', maximumFractionDigits: 0 }).format(value).replace('LKR', 'Rs.');
type Detail = { data: Listing; favorite: boolean; reviews: Array<{ id: number; rating: number; body: string; created_at?: string; tenant: { name: string } }>; reviewSummary: { summary: string; sampleSize: number; online?: boolean }; priceIntelligence:PriceInsight; related: Listing[] };

export default function ListingDetail() {
  const { id } = useParams();
  const { user } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [enquiring, setEnquiring] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  const [saveMessage, setSaveMessage] = useState('');
  const [reviewsOpen, setReviewsOpen] = useState(false);
  const { data, isLoading } = useQuery({ queryKey: ['listing', id], queryFn: async () => (await api.get(`/listings/${id}`)).data as Detail });
  useEffect(() => { if (data) setSaved(Boolean(data.favorite)); }, [data]);
  useEffect(() => { setReviewsOpen(false); }, [id]);
  const review = useMutation({ mutationFn: (body: unknown) => api.post('/reviews', body), onSuccess: () => queryClient.invalidateQueries({ queryKey: ['listing', id] }) });
  const favorite = useMutation({ mutationFn: () => saved ? api.delete(`/favorites/${id}`) : api.put(`/favorites/${id}`), onSuccess: response => { const next = Boolean(response.data.favorite); setSaved(next); setSaveMessage(next ? 'Saved to your shortlist.' : 'Removed from your shortlist.'); void queryClient.invalidateQueries({ queryKey: ['favorites'] }); }, onError: exception => setError((exception as { message?: string }).message || 'Could not update your shortlist.') });
  const enquiry = useMutation({ mutationFn: (text: string) => api.post('/conversations', { listingId: Number(id), text }), onSuccess: () => navigate('/tenant/messages') });
  if (isLoading || !data) return <PublicLayout><section className="sb-section"><div className="container sb-loading"><span className="spinner-border" /></div></section></PublicLayout>;

  const item = data.data;
  const submitReview = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); const fields = new FormData(event.currentTarget); review.mutate({ listingId: item.id, rating: Number(fields.get('rating')), text: fields.get('text') }); };
  const startEnquiry = () => { if (!user) return navigate('/login'); if (user.role !== 'tenant') return setError('Only tenant accounts can start property enquiries.'); setEnquiring(true); };
  const openNeighbourhood = (type?: string) => window.dispatchEvent(new CustomEvent('smartbodim:open-neighbourhood', { detail: { type } }));
  const nearby = item.nearbyPlaces?.slice().sort((a, b) => a.distanceM - b.distanceM) || [];
  const publicAreaLabel = item.area.trim().toLowerCase() === item.city.trim().toLowerCase() ? item.area : `${item.area}, ${item.city}`;
  const reviewCount = data.reviews.length;
  const reviewRegionId = `listing-${item.id}-original-reviews`;

  return <PublicLayout><section className="bb-detail"><div className="container">
    <nav className="bb-breadcrumb"><Link to="/search"><i className="bi bi-arrow-left" /> Back to stays</Link><span>{item.slug}</span></nav>

    <div className="bb-property-hero">
      <figure><img src={item.image || item.images?.[0]?.url} alt={`Interior of ${item.title}`} /><figcaption><span><i className="bi bi-patch-check-fill" /> Verified listing</span><small>Approximate area shown for privacy</small></figcaption></figure>
      <aside className="bb-property-decision">
        <div className="bb-property-tags"><span>{item.propertyType.replaceAll('_', ' ')}</span><span>{item.genderRule.replaceAll('_', ' ')}</span></div>
        <h1>{item.title}</h1>
        <div className="bb-property-place"><i className="bi bi-geo-alt-fill" /><span><strong>{item.area}</strong><small>{item.city}, {item.district}</small></span></div>
        <div className="bb-property-facts"><span><i className="bi bi-star-fill" /><b>{item.rating}</b><small>{item.reviewCount} reviews</small></span><span><i className="bi bi-people" /><b>Up to {item.occupancy}</b><small>residents</small></span><span><i className="bi bi-house-check" /><b>{item.furnished ? 'Furnished' : 'Unfurnished'}</b><small>move-in setup</small></span></div>
        <div className="bb-property-price"><span><small>Monthly rent</small><strong>{money(item.price)}</strong></span>{item.deposit ? <span><small>Refundable deposit</small><b>{money(item.deposit)}</b></span> : null}</div>
        <div className={`bb-availability is-${item.availabilityStatus || 'available'}`}><i className={`bi ${item.availabilityStatus === 'available' ? 'bi-calendar2-check' : item.availabilityStatus === 'held' ? 'bi-hourglass-split' : 'bi-calendar2-x'}`} /><span><strong>{item.availabilityLabel || 'Available for enquiries and viewings'}</strong>{item.nextAvailableFrom && item.availabilityStatus !== 'available' && <small>Next expected availability: {new Date(`${item.nextAvailableFrom}T00:00:00`).toLocaleDateString('en-LK', { dateStyle: 'medium' })}</small>}</span></div>
        {error && <div className="alert alert-warning" role="alert">{error}</div>}
        <div className="bb-property-actions"><button className="bb-primary-action" onClick={startEnquiry}><i className="bi bi-chat-heart" /> {item.availabilityStatus === 'available' ? 'Ask for a viewing' : 'Ask about future availability'}</button><button className="bb-secondary-action" onClick={() => openNeighbourhood()}><i className="bi bi-map" /> See daily-life map</button></div>
        {user?.role === 'tenant' && <button className={`bb-favorite ${saved ? 'saved' : ''}`} aria-pressed={saved} disabled={favorite.isPending} onClick={() => favorite.mutate()}><i className={`bi ${saved ? 'bi-heart-fill' : 'bi-heart'}`} /> {favorite.isPending ? 'Updating…' : saved ? 'Saved to my shortlist' : 'Save for later'}</button>}
        {saveMessage && <div className="sb-inline-success" role="status"><i className="bi bi-check-circle-fill" /> {saveMessage}</div>}
      </aside>
    </div>

    <div className="bb-detail-story">
      <div>
        <section className="bb-story-intro"><span>Get to know this place</span><h2>A home base for the life you are building.</h2><p>{item.description}</p></section>
        <section className="bb-facilities"><header><div><span>Included in the rent</span><h2>Comforts already covered</h2></div><b>{item.facilities.length} facilities</b></header><div>{item.facilities.map((facility, index) => <span key={facility}><i className={`bi ${index === 0 ? 'bi-wifi' : index === 1 ? 'bi-house-heart' : index === 2 ? 'bi-shield-check' : 'bi-check2'}`} />{facility}</span>)}</div></section>
      </div>
      <aside className="bb-local-snapshot"><header><BuddyMark /><div><span>Buddy’s neighbourhood snapshot</span><h2>What is close enough to matter?</h2></div></header>{nearby.slice(0, 5).map(place => <div key={`${place.type}-${place.name}`}><i className={`bi ${place.type === 'bus_station' ? 'bi-bus-front' : place.type === 'train_station' ? 'bi-train-front' : place.type === 'supermarket' ? 'bi-cart3' : place.type === 'hospital' ? 'bi-hospital' : 'bi-cup-hot'}`} /><span><small>{place.type.replaceAll('_', ' ')}</small><strong>{place.name}</strong></span><b>{place.distanceM < 1000 ? `${place.distanceM} m` : `${(place.distanceM / 1000).toFixed(1)} km`}</b></div>)}</aside>
    </div>

    <AvailabilityCalendar listingId={item.id} />

    <PriceIntelligence insight={data.priceIntelligence}/>

    {item.nearbyPlaces?.length ? <section className="bb-neighbourhood-block"><div className="bb-block-heading"><div><span>Explore the everyday</span><h2>See the neighbourhood, not just the room.</h2><p>Compare transport, groceries, healthcare and food around the privacy-safe property marker.</p></div><button onClick={() => openNeighbourhood()}>Open interactive map <i className="bi bi-arrow-down" /></button></div><NeighbourhoodExplorer latitude={item.latitude} longitude={item.longitude} label={publicAreaLabel} places={item.nearbyPlaces} autoOpen={location.hash === '#neighbourhood-explorer'} /></section> : null}

    <AreaSafetyInsight listingId={item.id} area={publicAreaLabel} onOpenMap={openNeighbourhood} canReport={user?.role === 'tenant'} />

    <section className="bb-resident-section">
      <div className="bb-block-heading"><div><span>Resident experiences</span><h2>What people noticed after moving in.</h2><p>Start with Buddy’s quick summary, then open the original reviews whenever you want the full story.</p></div></div>
      <div className="bb-review-experience">
        <div className="sb-review-summary">
          <BuddyMark />
          <div><span className="bb-ai-summary-label"><BuddyMark className="is-symbol" /> Buddy review summary</span><strong>Buddy found the recurring themes</strong><p>{data.reviewSummary.summary}</p><small>Based on {data.reviewSummary.sampleSize} public {data.reviewSummary.sampleSize === 1 ? 'review' : 'reviews'}. This summarizes opinions, not verified facts.</small></div>
        </div>
        {reviewCount > 0 ? <button
          type="button"
          className={`bb-review-reveal ${reviewsOpen ? 'is-open' : ''}`}
          aria-expanded={reviewsOpen}
          aria-controls={reviewRegionId}
          onClick={() => setReviewsOpen(open => !open)}
        >
          <span className="bb-review-reveal-icon" aria-hidden="true"><BuddyMark /></span>
          <span><strong>{reviewsOpen ? 'Hide original reviews' : `Read all ${reviewCount} original ${reviewCount === 1 ? 'review' : 'reviews'}`}</strong><small>{reviewsOpen ? 'Keep the page focused on Buddy’s summary' : 'See the exact, unedited words residents submitted'}</small></span>
          <i className={`bi ${reviewsOpen ? 'bi-chevron-up' : 'bi-arrow-down'}`} aria-hidden="true" />
        </button> : <div className="bb-review-empty"><i className="bi bi-chat-heart" /><span><strong>No public reviews yet</strong><small>When residents share an experience, Buddy will summarize the common themes here.</small></span></div>}
      </div>

      {reviewsOpen && <div className="bb-original-reviews" id={reviewRegionId} role="region" aria-label="Original resident reviews">
        <header><div><span>Original resident reviews</span><h3>Read it in their own words.</h3></div><b><i className="bi bi-shield-check" /> Never rewritten by AI</b></header>
        <div className="bb-original-review-grid">{data.reviews.map(entry => <article className="sb-review" key={entry.id}>
          <div className="bb-review-author"><span aria-hidden="true">{entry.tenant?.name?.split(' ').map(part => part[0]).join('').slice(0, 2) || 'R'}</span><div><strong>{entry.tenant?.name || 'Verified resident'}</strong><small>Resident review</small></div><b aria-label={`${entry.rating} out of 5 stars`}>{'★'.repeat(entry.rating)}<i>{'★'.repeat(5 - entry.rating)}</i></b></div>
          <p>“{entry.body}”</p>
        </article>)}</div>
      </div>}

      {user?.role === 'tenant' && <form className="sb-review-form" onSubmit={submitReview}><h3>Share your experience</h3><div className="row g-3"><div className="col-md-3"><select className="form-select" name="rating"><option value="5">5 — Excellent</option><option value="4">4 — Good</option><option value="3">3 — Fair</option><option value="2">2 — Poor</option><option value="1">1 — Very poor</option></select></div><div className="col-md-9"><textarea className="form-control" aria-label="Review text" name="text" required minLength={15} maxLength={2000} placeholder="Describe cleanliness, safety, noise, owner response…" /></div></div><button className="btn sb-btn-primary mt-3">Publish or update review</button></form>}
    </section>

    <section className="bb-host-promise"><div className="bb-host"><span>{item.ownerName?.slice(0, 2)}</span><div><small>Your host</small><strong>{item.ownerName}</strong><b><i className="bi bi-patch-check-fill" /> Verified owner</b></div></div><div><i className="bi bi-shield-heart" /><span><strong>Buddy’s safe-renting reminder</strong><small>Visit the property, meet the owner and verify everything independently before paying.</small></span></div></section>

    {data.related.length > 0 && <section className="bb-related"><header className="bb-section-heading"><div><span>Keep looking nearby</span><h2>More places around {item.city}</h2></div><Link to="/search">View all stays <i className="bi bi-arrow-right" /></Link></header><div className="bb-related-grid">{data.related.map(related => <ListingCard item={related} key={related.id} />)}</div></section>}
  </div></section>

  {enquiring && <div className="sb-modal-backdrop" onMouseDown={event => event.target === event.currentTarget && setEnquiring(false)}><form className="sb-modal" onSubmit={event => { event.preventDefault(); enquiry.mutate(String(new FormData(event.currentTarget).get('text'))); }}><button type="button" className="sb-modal-close" aria-label="Close" onClick={() => setEnquiring(false)}><i className="bi bi-x-lg" /></button><BuddyMark /><span className="sb-kicker">Private owner message</span><h2>Ask about {item.title}</h2><p>Your message is visible only to you and this listing’s owner.</p><textarea className="form-control" name="text" minLength={2} maxLength={2000} required placeholder="Ask about availability, a viewing or house rules…" /><div className="d-flex justify-content-end gap-2 mt-4"><button type="button" className="btn btn-outline-dark" onClick={() => setEnquiring(false)}>Cancel</button><button className="btn sb-btn-primary">Send securely</button></div></form></div>}
  </PublicLayout>;
}
