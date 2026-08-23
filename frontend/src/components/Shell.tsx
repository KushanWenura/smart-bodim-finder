import { type FormEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import { Link, NavLink, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { roleHome, useAuth } from '../auth';
import type { Listing, Role } from '../types';

const money = (value: number) => `Rs. ${Number(value).toLocaleString('en-LK')}`;
const initials = (name = '') => name.split(/\s+/).map(part => part[0]).slice(0, 2).join('').toUpperCase();

export function Header() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [expanded, setExpanded] = useState(false);
  return <>
    <div className="sb-announcement"><div className="container d-flex justify-content-center gap-3"><span><i className="bi bi-stars" /> AI proximity matching</span><span className="d-none d-md-inline">Verified listings</span><span className="d-none d-md-inline">No platform payments</span></div></div>
    <header className="sb-header"><nav className="navbar navbar-expand-lg container py-3" aria-label="Main navigation">
      <Link className="navbar-brand sb-brand" to="/"><span className="sb-brand-icon"><i className="bi bi-house-heart-fill" /></span><span>bodim<span>wise</span><small>AI stay intelligence</small></span></Link>
      <button className="navbar-toggler border-0" type="button" aria-label="Toggle navigation" aria-expanded={expanded} onClick={() => setExpanded(!expanded)}><i className={`bi ${expanded ? 'bi-x-lg' : 'bi-list'} fs-3`} /></button>
      <div className={`collapse navbar-collapse ${expanded ? 'show' : ''}`}><div className="navbar-nav mx-auto gap-lg-2"><NavLink className="nav-link" to="/search">Discover</NavLink><NavLink className="nav-link" to="/nearby">Near campus or work</NavLink><NavLink className="nav-link" to="/about">About</NavLink><NavLink className="nav-link" to="/safety">Safety</NavLink></div>
        <div className="d-flex align-items-center gap-2 mt-3 mt-lg-0">{user ? <><Link className="sb-icon-btn" aria-label="Notifications" to={`/${user.role}/notifications`}><i className="bi bi-bell" /></Link><Link className="sb-user-chip" to={roleHome(user.role)}><span>{initials(user.name)}</span><b className="d-none d-xl-inline">Dashboard</b></Link><button className="btn btn-sm btn-outline-dark rounded-pill px-3" onClick={async () => { await logout(); navigate('/'); }}>Log out</button></> : <><Link className="btn btn-link text-dark text-decoration-none fw-semibold" to="/login">Log in</Link><Link className="btn sb-btn-primary rounded-pill px-4" to="/register">Create account</Link></>}</div>
      </div>
    </nav></header>
  </>;
}

export function Footer() {
  return <footer className="sb-footer"><div className="container"><div className="row g-5 py-5"><div className="col-lg-5"><Link className="sb-brand text-white" to="/"><span className="sb-brand-icon"><i className="bi bi-house-heart-fill" /></span><span>bodim<span>wise</span><small>AI stay intelligence</small></span></Link><p className="mt-4 mb-0">A university-ready Sri Lankan accommodation platform combining verified listings, fine-tuned semantic search and transparent distance intelligence.</p></div><div className="col-6 col-lg-2"><h3>Explore</h3><Link to="/search">All listings</Link><Link to="/nearby">Campus finder</Link><Link to="/about">How it works</Link></div><div className="col-6 col-lg-2"><h3>Trust</h3><Link to="/safety">Safety</Link><Link to="/privacy">Privacy</Link><Link to="/terms">Terms</Link></div><div className="col-lg-3"><h3>Start with AI</h3><p>Describe your campus, workplace, budget and facilities.</p><button className="btn btn-light rounded-pill" onClick={() => window.dispatchEvent(new CustomEvent('smartbodim:open-assistant'))}><i className="bi bi-stars me-2" />Ask Bodim AI</button></div></div><div className="sb-footer-bottom"><span>© 2026 Bodimwise · Smart Bodim Finder</span><span>Academic system · Sri Lanka</span></div></div></footer>;
}

type AssistantMessage = { id: number; role: 'assistant' | 'user'; text: string; results?: Listing[]; disclaimer?: string };
type AssistantResponse = { answer: string; results: Listing[]; disclaimer: string };

export function AiChatbot() {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState(false);
  const [messages, setMessages] = useState<AssistantMessage[]>([{ id: 1, role: 'assistant', text: 'Ayubowan! Tell me your campus or workplace, budget and must-have facilities. I can calculate distance and show nearby essentials.' }]);
  const listRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  useEffect(() => { const listener = (event: Event) => { const message = (event as CustomEvent<{ message?: string }>).detail?.message; setOpen(true); if (message) setDraft(message); window.setTimeout(() => inputRef.current?.focus(), 50); }; window.addEventListener('smartbodim:open-assistant', listener); return () => window.removeEventListener('smartbodim:open-assistant', listener); }, []);
  useEffect(() => { listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: 'smooth' }); }, [messages, busy]);
  const ask = async (question: string) => { const clean = question.trim(); if (!clean || busy) return; setOpen(true); setDraft(''); setMessages(current => [...current, { id: Date.now(), role: 'user', text: clean }]); setBusy(true); try { const response = (await api.post('/assistant/chat', { message: clean })).data as AssistantResponse; setMessages(current => [...current, { id: Date.now() + 1, role: 'assistant', text: response.answer, results: response.results, disclaimer: response.disclaimer }]); } catch (error) { setMessages(current => [...current, { id: Date.now() + 1, role: 'assistant', text: (error as { message?: string }).message || 'I could not complete that search. Please try again.' }]); } finally { setBusy(false); } };
  const submit = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); void ask(draft); };
  const suggestions = ['Near University of Moratuwa with WiFi under Rs. 35,000', 'Within 8 km of SLIIT Malabe Campus', 'Female room near University of Colombo'];
  return <div className={`sb-ai ${open ? 'is-open' : ''}`}>
    {open && <section className="sb-ai-window" role="dialog" aria-label="Bodim AI assistant"><header><span className="sb-ai-avatar"><i className="bi bi-stars" /></span><div><strong>Bodim AI</strong><small>Fine-tuned stay assistant</small></div><button aria-label="Close Bodim AI assistant" onClick={() => setOpen(false)}><i className="bi bi-x-lg" /></button></header><div className="sb-ai-messages" ref={listRef}>{messages.map(message => <div className={`sb-ai-turn ${message.role}`} key={message.id}><div className="sb-ai-bubble">{message.text}</div>{message.results?.length ? <div className="sb-ai-results">{message.results.map(listing => <Link to={`/listing/${listing.id}`} onClick={() => setOpen(false)} key={listing.id}><img src={listing.image || listing.images?.[0]?.thumbnail} alt="" /><div><strong>{listing.title}</strong><span>{listing.area} · {money(listing.price)}</span>{listing.distanceKm !== undefined && <small><i className="bi bi-geo-alt-fill" /> {listing.distanceKm} km · ≈ {listing.commuteEstimateMinutes} min</small>}</div><i className="bi bi-arrow-up-right" /></Link>)}</div> : null}{message.disclaimer && <small className="sb-ai-disclaimer">{message.disclaimer}</small>}</div>)}{busy && <div className="sb-ai-turn assistant"><div className="sb-ai-bubble"><span className="spinner-grow spinner-grow-sm" /> Calculating matches…</div></div>}</div>{messages.length === 1 && <div className="sb-ai-suggestions">{suggestions.map(suggestion => <button key={suggestion} onClick={() => void ask(suggestion)}>{suggestion}</button>)}</div>}<form className="sb-ai-compose" onSubmit={submit}><label className="visually-hidden" htmlFor="bodim-ai-question">Ask Bodim AI</label><input ref={inputRef} id="bodim-ai-question" value={draft} onChange={event => setDraft(event.target.value)} maxLength={500} placeholder="Campus, workplace, budget, facilities…" /><button aria-label="Send to Bodim AI" disabled={busy || draft.trim().length < 2}><i className="bi bi-arrow-up" /></button></form><footer>Distances are straight-line estimates · Verify routes before deciding</footer></section>}
    <button className="sb-ai-launcher" aria-label={open ? 'Close Bodim AI assistant' : 'Open Bodim AI assistant'} aria-expanded={open} onClick={() => setOpen(!open)}><span><i className="bi bi-stars" /></span><b>Ask Bodim AI</b></button>
  </div>;
}

export function PublicLayout({ children }: { children: ReactNode }) { return <><Header /><main id="main">{children}</main><Footer /><AiChatbot /></>; }

const links: Record<Role, Array<[string, string, string]>> = {
  tenant: [['dashboard', 'bi-grid', 'Overview'], ['favorites', 'bi-heart', 'Favorites'], ['messages', 'bi-chat-dots', 'Messages'], ['saved-searches', 'bi-bell', 'Search alerts'], ['reviews', 'bi-star', 'Reviews'], ['profile', 'bi-person', 'Preferences'], ['security', 'bi-shield-lock', 'Security'], ['notifications', 'bi-inbox', 'Notifications']],
  owner: [['dashboard', 'bi-grid', 'Overview'], ['listings', 'bi-buildings', 'Listings'], ['create', 'bi-plus-circle', 'Add property'], ['messages', 'bi-chat-dots', 'Messages'], ['reviews', 'bi-star', 'Reviews'], ['profile', 'bi-patch-check', 'Verification'], ['security', 'bi-shield-lock', 'Security'], ['notifications', 'bi-inbox', 'Notifications']],
  admin: [['dashboard', 'bi-grid', 'Overview'], ['search', 'bi-search', 'Global search'], ['listings', 'bi-buildings', 'Listings'], ['owners', 'bi-patch-check', 'Owners'], ['users', 'bi-people', 'Users'], ['reviews', 'bi-star', 'Reviews'], ['notifications', 'bi-megaphone', 'Notify'], ['ai', 'bi-cpu', 'AI & data'], ['audit', 'bi-journal-text', 'Audit log']],
};

export function RoleLayout({ role, children }: { role: Role; children: ReactNode }) {
  const { user } = useAuth();
  return <><Header /><main id="main" className="sb-workspace"><aside className="sb-sidebar"><div className="sb-side-profile"><span>{initials(user?.name)}</span><div><strong>{user?.name}</strong><small>{role} workspace</small></div></div><nav>{links[role].map(([path, icon, label]) => <NavLink key={path} className={({ isActive }) => isActive ? 'active' : ''} to={`/${role}/${path}`}><i className={`bi ${icon}`} /><span>{label}</span></NavLink>)}</nav><div className="sb-side-help"><i className="bi bi-stars" /><strong>Need a better match?</strong><small>Use proximity-aware AI search.</small><Link to="/nearby">Open finder</Link></div></aside><section className="sb-dashboard">{children}</section></main>{role === 'tenant' && <AiChatbot />}</>;
}
