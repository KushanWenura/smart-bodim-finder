import { useQuery } from '@tanstack/react-query';
import { type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { ListingCard } from '../components/ListingCard';
import { BuddyMark, PublicLayout } from '../components/Shell';
import type { Destination, Listing } from '../types';

const popularNames = [
  'University of Moratuwa - Katubedda',
  'University of Colombo - Main Campus',
  'Sri Lanka Institute of Information Technology - Malabe Campus',
  'NSBM Green University',
  'ICBT Campus - Colombo',
  'ICBT Campus - Kandy',
  'World Trade Center Colombo',
  'Kandy City Centre',
];

const destinationFallbackLabels: Record<string, string> = {
  'NSBM Green University': 'Homagama',
  'World Trade Center Colombo': 'Colombo 01',
  'Kandy City Centre': 'Kandy',
};

export default function Home() {
  const { data: featured = [] } = useQuery({ queryKey: ['featured'], queryFn: async () => (await api.get('/listings/featured')).data.data as Listing[] });
  const { data: destinations = [] } = useQuery({ queryKey: ['destinations'], queryFn: async () => (await api.get('/destinations')).data.data as Destination[] });
  const popularDestinations = popularNames.map(name => destinations.find(place => place.name === name)).filter((place): place is Destination => Boolean(place));
  const openAssistant = (message?: string) => window.dispatchEvent(new CustomEvent('smartbodim:open-assistant', { detail: { message } }));
  const submit = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); openAssistant(String(new FormData(event.currentTarget).get('q') || '')); };
  const lead = featured[0];

  return <PublicLayout>
    <section className="bb-home-hero"><div className="container bb-home-grid">
      <div className="bb-home-copy">
        <span className="bb-pill"><BuddyMark className="is-mini" /> Sri Lanka’s friendly AI stay companion</span>
        <h1>A better bodim starts with <em>a better buddy.</em></h1>
        <p>Tell us where you study or work, what you can spend, and what matters at home. Buddy checks every must-have before ranking the best verified matches.</p>
        <form className="bb-home-command" onSubmit={submit}>
          <div className="bb-command-title"><BuddyMark /><span><b>What would make a place feel right?</b><small>Write naturally—Buddy understands Sri Lankan places and everyday needs.</small></span></div>
          <textarea name="q" required minLength={2} aria-label="Describe the accommodation you need" placeholder="I need a furnished room near Moratuwa campus with WiFi, AC and parking under 35k…" />
          <div className="bb-command-actions"><span><i className="bi bi-shield-check" /> Hard requirements stay strict</span><button>Ask Buddy <i className="bi bi-arrow-up-right" /></button></div>
        </form>
        <div className="bb-prompt-row">{[
          ['🎓', 'Near SLIIT Malabe', 'Find a WiFi room near SLIIT Malabe Campus under Rs. 40,000'],
          ['👩', 'Female-only near UoC', 'Female-only room near University of Colombo with WiFi'],
          ['🚗', 'Parking + AC under 35k', 'Room with parking, AC and WiFi under Rs. 35,000'],
        ].map(([icon, label, query]) => <button key={label} onClick={() => openAssistant(query)}><span>{icon}</span>{label}</button>)}</div>
      </div>

      <div className="bb-match-preview" aria-label="Example Buddy AI result">
        <header className="bb-match-preview-head">
          <div><BuddyMark /><span><small>Live match preview</small><strong>Buddy checked 24 verified stays</strong></span></div>
          <span className="bb-match-ready"><i className="bi bi-stars" /> Shortlist ready</span>
        </header>

        <div className="bb-match-preview-main">
          <figure className="bb-match-property">
            {lead?.image && <img src={lead.image} alt={lead.title} />}
            <figcaption><span><i className="bi bi-trophy-fill" /> #1 ranked stay</span><strong>{lead?.title || 'Campus-ready private room'}</strong><small><i className="bi bi-geo-alt-fill" /> {lead?.area || 'Near your destination'}</small></figcaption>
          </figure>

          <div className="bb-match-decision">
            <div className="bb-match-score"><div><strong>94</strong><small>%</small></div><span><b>Excellent fit</b><small>Based on your complete request</small></span></div>
            <div className="bb-match-reasons">
              <span><i className="bi bi-check2" /><b>Within budget</b><small>Rs. 10,500 left</small></span>
              <span><i className="bi bi-check2" /><b>All must-haves</b><small>WiFi · AC · parking</small></span>
              <span><i className="bi bi-signpost-split" /><b>Easy commute</b><small>1.8 km · about 8 min</small></span>
            </div>
            <button onClick={() => openAssistant()}>Create my shortlist <i className="bi bi-arrow-up-right" /></button>
          </div>
        </div>

        <div className="bb-match-nearby">
          <span><small>Daily-life check</small><b>Useful places around the stay</b></span>
          <div><i className="bi bi-bus-front" /><span><b>Bus</b><small>0.3 km</small></span></div>
          <div><i className="bi bi-cart3" /><span><b>Food City</b><small>0.8 km</small></span></div>
          <div><i className="bi bi-hospital" /><span><b>Hospital</b><small>1.4 km</small></span></div>
        </div>
      </div>
    </div></section>

    <section className="bb-proof"><div className="container"><div><strong>24</strong><span>original verified stays</span></div><div><strong>{destinations.length || 160}+</strong><span>campuses, branches & workplaces</span></div><div><strong>9</strong><span>nearby essential categories</span></div><div><i className="bi bi-heart-fill" /><span><b>Human first</b> AI explains every rank</span></div></div></section>

    <section className="bb-home-section bb-featured"><div className="container">
      <header className="bb-section-heading"><div><span>Places worth meeting</span><h2>Fresh stays with the details already checked.</h2></div><p>Every card brings the decision-making information forward—real rent, facilities, owner status and the neighbourhood around it.</p><Link to="/search">See all stays <i className="bi bi-arrow-right" /></Link></header>
      <div className="bb-featured-grid">{featured.slice(0, 6).map((item, index) => <div className={index === 0 ? 'is-lead' : ''} key={item.id}><ListingCard item={item} /></div>)}</div>
    </div></section>

    <section className="bb-commute"><div className="container bb-commute-layout">
      <div className="bb-commute-intro"><span>Start from your daily destination</span><h2>Pick the exact branch. Buddy handles the radius.</h2><p>ICBT Colombo and ICBT Kandy are not the same search. We calculate from the branch you choose, then show nearby transport, groceries, healthcare and food.</p><Link to="/nearby">Open commute matcher <i className="bi bi-arrow-up-right" /></Link></div>
      <div className="bb-destination-list">{popularDestinations.map((place, index) => <Link to={`/nearby?destination=${encodeURIComponent(place.name)}`} key={place.id}><b>{String(index + 1).padStart(2, '0')}</b><span><small>{place.organizationName || (place.type === 'campus' ? 'Campus' : 'Workplace')}</small><strong>{place.branchName || destinationFallbackLabels[place.name] || place.name}</strong></span><i className={`bi ${place.type === 'campus' ? 'bi-mortarboard' : 'bi-briefcase'}`} /></Link>)}</div>
    </div></section>

    <section className="bb-how"><div className="container">
      <header><BuddyMark /><div><span>How your Buddy thinks</span><h2>Friendly on the surface. Strict underneath.</h2></div></header>
      <div className="bb-how-grid">{[
        ['01', 'Listen', 'Understands the full sentence—branch, budget, room type, who it is for and must-have facilities.'],
        ['02', 'Protect', 'Removes anything that breaks a hard requirement. No fake “almost perfect” match gets promoted.'],
        ['03', 'Compare', 'Ranks only eligible stays using distance, value, rating, owner verification and nearby essentials.'],
        ['04', 'Explain', 'Shows why each place ranked where it did, then lets you refine the same conversation.'],
      ].map(([number, title, copy]) => <article key={number}><span>{number}</span><i className={`bi ${number === '01' ? 'bi-chat-heart' : number === '02' ? 'bi-shield-check' : number === '03' ? 'bi-bar-chart' : 'bi-lightbulb'}`} /><h3>{title}</h3><p>{copy}</p></article>)}</div>
    </div></section>

    <section className="bb-home-end"><div className="container"><div><BuddyMark /><span><small>Friendly does not mean careless</small><h2>View the place. Meet the owner. Pay only when you are sure.</h2></span></div><Link to="/safety">Our safety promise <i className="bi bi-arrow-right" /></Link></div></section>
  </PublicLayout>;
}
