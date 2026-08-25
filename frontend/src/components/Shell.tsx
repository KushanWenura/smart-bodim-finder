import { type FormEvent, type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { roleHome, useAuth } from '../auth';
import type { Listing, Role } from '../types';

const money = (value: number) => `Rs. ${Number(value).toLocaleString('en-LK')}`;
const initials = (name = '') => name.split(/\s+/).map(part => part[0]).slice(0, 2).join('').toUpperCase();

export function Header({ workspaceRole }: { workspaceRole?: Role } = {}) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [expanded, setExpanded] = useState(false);

  useEffect(() => setExpanded(false), [location.pathname]);

  return <>
    {!workspaceRole && <div className="bw-trustbar"><div className="container"><span><i className="bi bi-patch-check-fill" /> Owner-verified stays</span><span><i className="bi bi-geo-alt-fill" /> Campus distance intelligence</span><span><i className="bi bi-shield-check" /> No platform payments</span></div></div>}
    <header className={`sb-header ${workspaceRole ? 'sb-header-workspace' : ''}`}>
      <nav className="navbar navbar-expand-lg container py-3" aria-label="Main navigation">
        <Link className="navbar-brand sb-brand" to="/" aria-label="Bodimwise home">
          <span className="sb-brand-icon"><i className="bi bi-house-heart-fill" /></span>
          <span className="sb-brand-copy"><b>bodim<span>wise</span></b><small>Find your better base</small></span>
        </Link>
        <button className="navbar-toggler border-0" type="button" aria-label="Toggle navigation" aria-expanded={expanded} onClick={() => setExpanded(!expanded)}><i className={`bi ${expanded ? 'bi-x-lg' : 'bi-list'}`} /></button>
        <div className={`collapse navbar-collapse ${expanded ? 'show' : ''}`}>
          <div className="navbar-nav mx-auto">{workspaceRole
            ? <span className="sb-workspace-label"><i className="bi bi-grid-1x2-fill" /> {workspaceRole === 'admin' ? 'Operations' : workspaceRole === 'owner' ? 'Property workspace' : 'My workspace'}</span>
            : <><NavLink className="nav-link" to="/search">Explore stays</NavLink><NavLink className="nav-link" to="/nearby">Near campus or work</NavLink><NavLink className="nav-link" to="/about">How it works</NavLink><NavLink className="nav-link" to="/safety">Stay safe</NavLink></>}
          </div>
          <div className="sb-nav-actions">{user ? <>
            <Link className="sb-icon-btn" aria-label="Notifications" to={`/${user.role}/notifications`}><i className="bi bi-bell" /></Link>
            <Link className="sb-user-chip" to={roleHome(user.role)}><span>{initials(user.name)}</span><b>{user.name.split(' ')[0]}</b></Link>
            <button className="sb-text-button" onClick={async () => { await logout(); navigate('/'); }}>Log out</button>
          </> : <>
            <Link className="sb-text-button" to="/login">Log in</Link>
            <Link className="btn sb-btn-primary" to="/register">Create free account <i className="bi bi-arrow-up-right" /></Link>
          </>}</div>
        </div>
      </nav>
    </header>
  </>;
}

export function Footer() {
  const openAi = () => window.dispatchEvent(new CustomEvent('smartbodim:open-assistant'));
  return <footer className="sb-footer">
    <div className="container">
      <section className="bw-footer-cta"><div><span><i className="bi bi-stars" /> Bodim AI</span><h2>Find a place around your real daily life.</h2><p>Describe your campus, workplace, budget and essentials in one sentence.</p></div><button onClick={openAi}>Start a smart search <i className="bi bi-arrow-right" /></button></section>
      <div className="bw-footer-grid">
        <div><Link className="sb-brand text-white" to="/"><span className="sb-brand-icon"><i className="bi bi-house-heart-fill" /></span><span className="sb-brand-copy"><b>bodim<span>wise</span></b><small>Find your better base</small></span></Link><p>A privacy-aware Sri Lankan accommodation platform combining verified listings, explainable AI matching and useful neighbourhood data.</p></div>
        <div><h3>Find a place</h3><Link to="/search">Explore all stays</Link><Link to="/nearby">Search by destination</Link><button onClick={openAi}>Ask Bodim AI</button></div>
        <div><h3>Learn</h3><Link to="/about">How matching works</Link><Link to="/safety">Safety guide</Link><Link to="/privacy">Privacy</Link></div>
        <div><h3>For property owners</h3><Link to="/register">Create an account</Link><Link to="/owner/create">List a property</Link><Link to="/terms">Listing standards</Link></div>
      </div>
      <div className="sb-footer-bottom"><span>© 2026 Bodimwise · Smart Bodim Finder</span><span>Built for safer, shorter moves across Sri Lanka</span></div>
    </div>
  </footer>;
}

type AssistantPrompt = { id?: number; name: string; query?: string };
type AssistantSearch = { mode?: string; aiOnline?: boolean; warning?: string; rankingMethod?: string };
type AssistantMessage = {
  id: number;
  role: 'assistant' | 'user';
  text: string;
  results?: Listing[];
  prompts?: AssistantPrompt[];
  followUps?: AssistantPrompt[];
  requirements?: string[];
  disclaimer?: string;
  search?: AssistantSearch;
};
type AssistantResponse = {
  answer: string;
  results: Listing[];
  suggestions?: AssistantPrompt[];
  followUps?: AssistantPrompt[];
  requirements?: string[];
  disclaimer: string;
  search?: AssistantSearch;
};

const starterPrompts = [
  { icon: 'bi-mortarboard', label: 'Near a campus', query: 'Near University of Moratuwa - Katubedda with WiFi, AC and parking under Rs. 35,000' },
  { icon: 'bi-briefcase', label: 'Near my workplace', query: 'Find a furnished room within 8 km of World Trade Center Colombo under Rs. 45,000' },
  { icon: 'bi-gender-female', label: 'Female-only stay', query: 'Female-only room near University of Colombo with WiFi under Rs. 35,000' },
  { icon: 'bi-wallet2', label: 'Best value', query: 'Cheapest WiFi room with a private bathroom and parking' },
];

export function AiChatbot() {
  const welcome: AssistantMessage = { id: 1, role: 'assistant', text: 'Ayubowan! I’m your Sri Lankan stay-finding assistant. Tell me where you study or work, your maximum monthly budget, and anything you cannot compromise on.' };
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState(false);
  const [messages, setMessages] = useState<AssistantMessage[]>([welcome]);
  const listRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const userContext = useMemo(() => messages.filter(message => message.role === 'user').slice(-3).map(message => message.text), [messages]);

  useEffect(() => {
    const listener = (event: Event) => {
      const message = (event as CustomEvent<{ message?: string }>).detail?.message;
      setOpen(true);
      if (message) setDraft(message);
      window.setTimeout(() => inputRef.current?.focus(), 80);
    };
    window.addEventListener('smartbodim:open-assistant', listener);
    return () => window.removeEventListener('smartbodim:open-assistant', listener);
  }, []);

  useEffect(() => {
    const close = (event: KeyboardEvent) => { if (event.key === 'Escape') setOpen(false); };
    window.addEventListener('keydown', close);
    return () => window.removeEventListener('keydown', close);
  }, []);

  useEffect(() => {
    const list = listRef.current;
    if (!list) return;
    const latest = list.querySelector<HTMLElement>('.sb-ai-turn:last-of-type');
    list.scrollTo({ top: latest?.offsetTop ? Math.max(0, latest.offsetTop - 14) : list.scrollHeight, behavior: 'smooth' });
  }, [messages, busy]);

  const reset = () => { setMessages([welcome]); setDraft(''); window.setTimeout(() => inputRef.current?.focus(), 50); };
  const ask = async (question: string) => {
    const clean = question.trim();
    if (!clean || busy) return;
    setOpen(true);
    setDraft('');
    setMessages(current => [...current, { id: Date.now(), role: 'user', text: clean }]);
    setBusy(true);
    try {
      const response = (await api.post('/assistant/chat', { message: clean, context: userContext })).data as AssistantResponse;
      setMessages(current => [...current, {
        id: Date.now() + 1,
        role: 'assistant',
        text: response.answer,
        results: response.results,
        prompts: response.suggestions,
        followUps: response.followUps,
        requirements: response.requirements,
        disclaimer: response.disclaimer,
        search: response.search,
      }]);
    } catch (error) {
      const detail = error as { response?: { data?: { message?: string } }; message?: string };
      setMessages(current => [...current, { id: Date.now() + 1, role: 'assistant', text: detail.response?.data?.message || detail.message || 'I could not complete that search. Your services may still be starting—please try once more.' }]);
    } finally {
      setBusy(false);
    }
  };
  const submit = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); void ask(draft); };

  return <div className={`sb-ai ${open ? 'is-open' : ''}`}>
    {open && <button className="sb-ai-backdrop" aria-label="Close Bodim AI assistant" onClick={() => setOpen(false)} />}
    {open && <section className="sb-ai-window" role="dialog" aria-modal="true" aria-label="Bodim AI assistant">
      <header className="sb-ai-head">
        <div className="sb-ai-identity"><span className="sb-ai-avatar"><i className="bi bi-stars" /></span><div><strong>Bodim AI</strong><small><i /> Strict matching + transparent ranking</small></div></div>
        <div className="sb-ai-head-actions"><button aria-label="Start a new Bodim AI conversation" onClick={reset}><i className="bi bi-arrow-counterclockwise" /></button><button aria-label="Close Bodim AI assistant" onClick={() => setOpen(false)}><i className="bi bi-x-lg" /></button></div>
      </header>
      <div className="sb-ai-context"><span><i className="bi bi-shield-check" /> Exact addresses stay private</span><span><i className="bi bi-geo" /> Distances are estimates</span></div>
      <div className="sb-ai-messages" ref={listRef} aria-live="polite">
        {messages.map(message => <div className={`sb-ai-turn ${message.role}`} key={message.id}>
          {message.role === 'assistant' && <span className="sb-ai-mini-avatar"><i className="bi bi-stars" /></span>}
          <div className="sb-ai-turn-content">
            <div className="sb-ai-bubble">{message.text}</div>
            {message.prompts?.length ? <div className="sb-ai-clarifications" aria-label="Choose a destination branch">{message.prompts.map(prompt => <button key={prompt.name} onClick={() => void ask(prompt.query || `Find a room near ${prompt.name}`)}><i className="bi bi-geo-alt" /><span>{prompt.name}</span><i className="bi bi-chevron-right" /></button>)}</div> : null}
            {message.requirements?.length ? <div className="sb-ai-criteria" aria-label="Applied requirements"><b>Requirements understood</b><div>{message.requirements.map(requirement => <span key={requirement}><i className="bi bi-check2" />{requirement}</span>)}</div></div> : null}
            {message.results?.length ? <div className="sb-ai-results">{message.results.map(listing => <Link className={listing.matchRank === 1 ? 'is-best' : ''} to={`/listing/${listing.id}`} onClick={() => setOpen(false)} key={listing.id}>
              <div className="sb-ai-result-photo"><img src={listing.image || listing.images?.[0]?.thumbnail} alt="" /><span>#{listing.matchRank}</span></div>
              <div className="sb-ai-result-copy"><div><span className="sb-ai-match-label">{listing.matchRank === 1 && <i className="bi bi-trophy-fill" />} {listing.matchLabel}</span><b>{listing.matchScore}% fit</b></div><strong>{listing.title}</strong><p>{listing.area} · {money(listing.price)} / month</p>{listing.distanceKm !== undefined && <small><i className="bi bi-signpost-split" /> {listing.distanceKm} km · about {listing.commuteEstimateMinutes} min</small>}<div className="sb-ai-result-reasons">{listing.matchReasons?.slice(0, 3).map(reason => <span key={reason}><i className="bi bi-check-circle-fill" />{reason}</span>)}</div></div>
              <i className="bi bi-arrow-up-right sb-ai-open-result" />
            </Link>)}</div> : null}
            {message.search?.warning && <div className="sb-ai-warning"><i className="bi bi-info-circle" /> {message.search.warning}</div>}
            {message.followUps?.length ? <div className="sb-ai-followups"><b>Refine this search</b>{message.followUps.map(prompt => <button key={prompt.name} onClick={() => void ask(prompt.query || prompt.name)}>{prompt.name}<i className="bi bi-arrow-right" /></button>)}</div> : null}
            {message.disclaimer && <small className="sb-ai-disclaimer"><i className="bi bi-shield-exclamation" /> {message.disclaimer}</small>}
          </div>
        </div>)}
        {busy && <div className="sb-ai-turn assistant"><span className="sb-ai-mini-avatar"><i className="bi bi-stars" /></span><div className="sb-ai-turn-content"><div className="sb-ai-bubble sb-ai-thinking"><span /><span /><span /><b>Checking every hard requirement, then ranking the eligible stays</b></div></div></div>}
      </div>
      {messages.length === 1 && <div className="sb-ai-starters"><span>Try a guided search</span><div>{starterPrompts.map(prompt => <button key={prompt.label} onClick={() => void ask(prompt.query)}><i className={`bi ${prompt.icon}`} /><span><b>{prompt.label}</b><small>{prompt.query}</small></span><i className="bi bi-arrow-right" /></button>)}</div></div>}
      <form className="sb-ai-compose" onSubmit={submit}>
        <label className="visually-hidden" htmlFor="bodim-ai-question">Ask Bodim AI</label>
        <textarea ref={inputRef} id="bodim-ai-question" rows={1} value={draft} onChange={event => setDraft(event.target.value)} onKeyDown={event => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void ask(draft); } }} maxLength={500} placeholder="Campus, budget, WiFi, AC, parking…" />
        <div><small>{draft.length}/500</small><button aria-label="Send to Bodim AI" disabled={busy || draft.trim().length < 2}><i className="bi bi-arrow-up" /></button></div>
      </form>
      <footer><span><i className="bi bi-funnel" /> Hard filters first</span><span><i className="bi bi-bar-chart" /> Suitability ranked second</span></footer>
    </section>}
    <button className="sb-ai-launcher" aria-label={open ? 'Close Bodim AI assistant' : 'Open Bodim AI assistant'} aria-expanded={open} onClick={() => setOpen(!open)}><span><i className="bi bi-stars" /></span><b>Ask Bodim AI</b><small>Find my best match</small></button>
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
  return <><Header workspaceRole={role} /><main id="main" className={`sb-workspace sb-workspace-${role}`}>
    <aside className="sb-sidebar">
      <div className="sb-side-profile"><span>{initials(user?.name)}</span><div><strong>{user?.name}</strong><small>{role === 'admin' ? 'Platform operations' : role === 'owner' ? 'Verified property partner' : 'Tenant account'}</small></div></div>
      <nav aria-label={`${role} workspace navigation`}>{links[role].map(([path, icon, label]) => <NavLink key={path} className={({ isActive }) => isActive ? 'active' : ''} to={`/${role}/${path}`}><i className={`bi ${icon}`} /><span>{label}</span></NavLink>)}</nav>
      <div className="sb-side-help"><span><i className="bi bi-stars" /></span><strong>{role === 'tenant' ? 'Need a sharper match?' : role === 'owner' ? 'Improve your ranking' : 'System overview'}</strong><small>{role === 'tenant' ? 'Describe the whole need to Bodim AI.' : role === 'owner' ? 'Complete details help the right tenants find you.' : 'Review trust, content and AI health.'}</small><Link to={role === 'tenant' ? '/nearby' : `/${role}/${role === 'owner' ? 'listings' : 'dashboard'}`}>{role === 'tenant' ? 'Open smart finder' : 'View workspace'} <i className="bi bi-arrow-right" /></Link></div>
    </aside>
    <section className="sb-dashboard"><div className="sb-dashboard-inner">{children}</div></section>
  </main>{role === 'tenant' && <AiChatbot />}</>;
}
