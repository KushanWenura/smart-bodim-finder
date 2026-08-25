import { useQuery } from '@tanstack/react-query';
import { type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { ListingCard } from '../components/ListingCard';
import { PublicLayout } from '../components/Shell';
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

export default function Home() {
  const { data: featured = [] } = useQuery({ queryKey: ['featured'], queryFn: async () => (await api.get('/listings/featured')).data.data as Listing[] });
  const { data: destinations = [] } = useQuery({ queryKey: ['destinations'], queryFn: async () => (await api.get('/destinations')).data.data as Destination[] });
  const popularDestinations = popularNames.map(name => destinations.find(place => place.name === name)).filter((place): place is Destination => Boolean(place));
  const openAssistant = (message?: string) => window.dispatchEvent(new CustomEvent('smartbodim:open-assistant', { detail: { message } }));
  const submit = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); openAssistant(String(new FormData(event.currentTarget).get('q') || '')); };

  return <PublicLayout>
    <section className="bw-hero">
      <div className="container bw-hero-grid">
        <div className="bw-hero-copy">
          <span className="sb-kicker"><i className="bi bi-stars" /> AI stay search built for Sri Lanka</span>
          <h1>Find the right room around <em>your real routine.</em></h1>
          <p>Start with your campus or workplace. Bodimwise checks the budget and facilities you cannot compromise on, then ranks only eligible verified stays.</p>
          <form className="bw-command" onSubmit={submit}>
            <span><i className="bi bi-chat-square-text" /></span>
            <label><small>Tell Bodim AI what you need</small><input name="q" required minLength={2} aria-label="Describe the accommodation you need" placeholder="Near Moratuwa campus · WiFi · AC · parking · under 35,000" /></label>
            <button>Find my match <i className="bi bi-arrow-right" /></button>
          </form>
          <div className="bw-quick-prompts"><span>Popular:</span>{[
            ['Near SLIIT Malabe', 'Find a WiFi room near SLIIT Malabe Campus under Rs. 40,000'],
            ['Female-only near UoC', 'Female-only room near University of Colombo with WiFi'],
            ['Parking + AC under 35k', 'Room with parking, AC and WiFi under Rs. 35,000'],
          ].map(([label, query]) => <button key={label} onClick={() => openAssistant(query)}>{label}</button>)}</div>
          <div className="bw-hero-proof"><div><i className="bi bi-patch-check-fill" /><span><strong>Owner verified</strong><small>Trust signals on every result</small></span></div><div><i className="bi bi-signpost-split" /><span><strong>Distance aware</strong><small>From the branch you choose</small></span></div><div><i className="bi bi-funnel-fill" /><span><strong>Hard filters first</strong><small>No fake “close enough” matches</small></span></div></div>
        </div>
        <div className="bw-hero-stage" aria-label="Example AI accommodation match">
          <div className="bw-photo-stack">
            <div className="bw-photo-main">{featured[0]?.image && <img src={featured[0].image} alt={featured[0].title} />}</div>
            <div className="bw-photo-small">{featured[1]?.image && <img src={featured[1].image} alt={featured[1].title} />}</div>
          </div>
          <div className="bw-match-card"><header><span><i className="bi bi-stars" /> Best match</span><b>94% fit</b></header><strong>{featured[0]?.title || 'Campus-ready private room'}</strong><p><i className="bi bi-geo-alt-fill" /> 1.8 km from your destination</p><div><span><i className="bi bi-check2" /> WiFi</span><span><i className="bi bi-check2" /> Under budget</span><span><i className="bi bi-check2" /> Parking</span></div></div>
          <div className="bw-nearby-card"><span><i className="bi bi-bus-front" /><b>Bus stop</b><small>0.3 km</small></span><span><i className="bi bi-cart3" /><b>Food City</b><small>0.8 km</small></span><span><i className="bi bi-hospital" /><b>Hospital</b><small>1.4 km</small></span></div>
        </div>
      </div>
    </section>

    <section className="bw-stat-strip"><div className="container"><div><strong>24</strong><span>distinct verified stays</span></div><div><strong>{destinations.length || 160}</strong><span>campus, branch & work destinations</span></div><div><strong>5</strong><span>nearby essential categories</span></div><div><strong>0</strong><span>hidden platform payment fees</span></div></div></section>

    <section className="bw-section bw-listing-showcase"><div className="container">
      <header className="bw-section-head"><div><span className="sb-kicker">Fresh places, clear decisions</span><h2>Start with stays people can trust.</h2><p>Original property records with clear prices, facilities, owner status and privacy-safe approximate locations.</p></div><Link to="/search">Explore all 24 stays <i className="bi bi-arrow-right" /></Link></header>
      <div className="row g-4">{featured.slice(0, 6).map(item => <div className="col-md-6 col-xl-4" key={item.id}><ListingCard item={item} /></div>)}</div>
    </div></section>

    <section className="bw-destinations"><div className="container">
      <header className="bw-section-head"><div><span className="sb-kicker">Destination-first discovery</span><h2>Search from where your day begins.</h2><p>Select the exact campus branch or workplace so the distance comparison starts from the right coordinates.</p></div><Link to="/nearby">Open proximity finder <i className="bi bi-arrow-right" /></Link></header>
      <div className="bw-destination-grid">{popularDestinations.map(place => <Link to={`/nearby?destination=${encodeURIComponent(place.name)}`} key={place.id}><span><i className={`bi ${place.type === 'campus' ? 'bi-mortarboard-fill' : 'bi-briefcase-fill'}`} /></span><div><small>{place.organizationName || (place.type === 'campus' ? 'Campus' : 'Workplace')}</small><strong>{place.branchName || place.name}</strong></div><i className="bi bi-arrow-up-right" /></Link>)}</div>
    </div></section>

    <section className="bw-process"><div className="container bw-process-grid">
      <div><span className="sb-kicker">Explainable by design</span><h2>AI helps with the search. You stay in control of the decision.</h2><p>Bodimwise separates requirements from preferences. Price, distance and must-have facilities filter the catalogue first. Only then does AI rank the eligible options.</p><button className="btn sb-btn-primary" onClick={() => openAssistant()}>Try Bodim AI <i className="bi bi-stars" /></button></div>
      <ol>{[
        ['Describe the whole need', 'Use normal language: destination, budget, room type, facilities and who the stay is for.'],
        ['Confirm the right branch', 'Multi-branch institutions are never treated as one interchangeable location.'],
        ['Compare ranked matches', 'See fit score, distance, price headroom, rating and the reasons behind every rank.'],
        ['Check the neighbourhood', 'Inspect nearby transport, supermarket, hospital, food and rail points on the map.'],
      ].map(([title, copy], index) => <li key={title}><span>{String(index + 1).padStart(2, '0')}</span><div><strong>{title}</strong><p>{copy}</p></div></li>)}</ol>
    </div></section>

    <section className="bw-safety-banner"><div className="container"><span><i className="bi bi-shield-check" /></span><div><small>Move with confidence</small><h2>View first. Verify independently. Pay safely.</h2><p>Bodimwise never asks you to transfer rent or a deposit through the platform.</p></div><Link to="/safety">Read the safety guide <i className="bi bi-arrow-right" /></Link></div></section>
  </PublicLayout>;
}
