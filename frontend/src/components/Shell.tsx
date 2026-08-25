import { type FormEvent, type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { roleHome, useAuth } from '../auth';
import type { Listing, Role } from '../types';

const money = (value: number) => `Rs. ${Number(value).toLocaleString('en-LK')}`;
const initials = (name = '') => name.split(/\s+/).map(part => part[0]).slice(0, 2).join('').toUpperCase();

export function BuddyMark({ className = '' }: { className?: string }) {
  return <span className={`bb-mark ${className}`} aria-hidden="true"><svg viewBox="0 0 72 72" role="img">
    <path className="bb-mark-home" d="M12.5 30.5h47v24A11.5 11.5 0 0 1 48 66H24A11.5 11.5 0 0 1 12.5 54.5Z" />
    <path className="bb-mark-roof" d="M7.5 30.8 30 10.3a9 9 0 0 1 12 0l22.5 20.5-5.2 5.7L36 15.4 12.7 36.5Z" />
    <rect className="bb-mark-face" x="18" y="31" width="36" height="27" rx="11" />
    <path className="bb-mark-antenna" d="M36 31v-4" />
    <circle className="bb-mark-antenna-dot" cx="36" cy="26.5" r="2" />
    <circle className="bb-mark-eye" cx="28" cy="42" r="2.7" /><circle className="bb-mark-eye" cx="44" cy="42" r="2.7" />
    <circle className="bb-mark-cheek" cx="24.5" cy="49" r="1.6" /><circle className="bb-mark-cheek" cx="47.5" cy="49" r="1.6" />
    <path className="bb-mark-smile" d="M29 49c4.2 3.4 9.8 3.4 14 0" />
    <path className="bb-mark-heart" d="M55.5 12.5c-4.5-5.3-13.2.7-4.6 8.4l4.6 3.9 4.6-3.9c8.6-7.7-.1-13.7-4.6-8.4Z" />
  </svg></span>;
}

function Brand({ inverse = false }: { inverse?: boolean }) {
  return <Link className={`bb-brand ${inverse ? 'is-inverse' : ''}`} to="/" aria-label="BodimBuddy.lk home"><BuddyMark /><span><b>Bodim<span>Buddy</span><em>.lk</em></b><small>Your friendly stay finder</small></span></Link>;
}

export function Header({ workspaceRole }: { workspaceRole?: Role } = {}) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [expanded, setExpanded] = useState(false);

  useEffect(() => setExpanded(false), [location.pathname]);

  return <>
    {!workspaceRole && <div className="bb-trustbar"><div className="container"><span><i className="bi bi-heart-fill" /> Made for Sri Lankan renters</span><span><i className="bi bi-patch-check-fill" /> Human-verified owners</span><span><i className="bi bi-shield-check" /> View first · pay safely</span></div></div>}
    <header className={`bb-header ${workspaceRole ? 'is-workspace' : ''}`}>
      <nav className="navbar navbar-expand-lg container bb-navbar" aria-label="Main navigation">
        <Brand />
        <button className="navbar-toggler border-0" type="button" aria-label="Toggle navigation" aria-expanded={expanded} onClick={() => setExpanded(!expanded)}><i className={`bi ${expanded ? 'bi-x-lg' : 'bi-list'}`} /></button>
        <div className={`collapse navbar-collapse ${expanded ? 'show' : ''}`}>
          <div className="navbar-nav bb-navlinks">{workspaceRole
            ? <span className="sb-workspace-label"><i className="bi bi-grid-1x2-fill" /> {workspaceRole === 'admin' ? 'Operations' : workspaceRole === 'owner' ? 'Property workspace' : 'My workspace'}</span>
            : <><NavLink className="nav-link" to="/search"><i className="bi bi-compass" /> Discover</NavLink><NavLink className="nav-link" to="/nearby"><i className="bi bi-signpost-split" /> Commute match</NavLink><NavLink className="nav-link" to="/about"><i className="bi bi-heart" /> Our story</NavLink><NavLink className="nav-link" to="/safety"><i className="bi bi-shield-check" /> Safety</NavLink></>}
          </div>
          <div className="bb-nav-actions">{!workspaceRole && <button className="bb-ask-nav" onClick={() => window.dispatchEvent(new CustomEvent('smartbodim:open-assistant'))}><BuddyMark className="is-mini" /><span>Ask Buddy</span></button>}{user ? <>
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
  return <footer className="bb-footer">
    <div className="container">
      <section className="bb-footer-cta"><BuddyMark /><div><span>Meet your new rental sidekick</span><h2>Tell Buddy where life happens. We’ll find the room around it.</h2></div><button onClick={openAi}>Chat with Buddy <i className="bi bi-arrow-up-right" /></button></section>
      <div className="bb-footer-grid">
        <div><Brand inverse /><p>A friendly, privacy-aware Sri Lankan accommodation companion combining verified listings, transparent AI matching and practical neighbourhood data.</p></div>
        <div><h3>Find your place</h3><Link to="/search">Browse every stay</Link><Link to="/nearby">Match my commute</Link><button onClick={openAi}>Ask Buddy AI</button></div>
        <div><h3>Learn</h3><Link to="/about">How matching works</Link><Link to="/safety">Safety guide</Link><Link to="/privacy">Privacy</Link></div>
        <div><h3>For property owners</h3><Link to="/register">Create an account</Link><Link to="/owner/create">List a property</Link><Link to="/terms">Listing standards</Link></div>
      </div>
      <div className="bb-footer-bottom"><span>© 2026 BodimBuddy.lk</span><span>Built with heart for safer, shorter moves across Sri Lanka 🇱🇰</span></div>
    </div>
  </footer>;
}

type AssistantPrompt = { id?: number; name: string; query?: string };
type AssistantSearch = { mode?: string; aiOnline?: boolean; warning?: string; rankingMethod?: string; rankingDetails?: string; searchLogId?: number; personalized?: boolean };
type Understanding = { language?: string; confidence?: { overall?: number; recognizedSlots?: number }; hardFacilities?: string[]; preferredFacilities?: string[]; excludedFacilities?: string[] };
type Relaxation = { constraint: string; blockedListings: number; matchesIfRelaxed: number };
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
  understanding?: Understanding;
  relaxationAnalysis?: Relaxation[];
};
type AssistantResponse = {
  answer: string;
  results: Listing[];
  suggestions?: AssistantPrompt[];
  followUps?: AssistantPrompt[];
  requirements?: string[];
  disclaimer: string;
  search?: AssistantSearch;
  understanding?: Understanding;
  relaxationAnalysis?: Relaxation[];
};

const starterPrompts = [
  { icon: 'bi-mortarboard', label: 'Near a campus', query: 'Near University of Moratuwa - Katubedda with WiFi, AC and parking under Rs. 35,000' },
  { icon: 'bi-briefcase', label: 'Near my workplace', query: 'Find a furnished room within 8 km of World Trade Center Colombo under Rs. 45,000' },
  { icon: 'bi-gender-female', label: 'Female-only stay', query: 'Female-only room near University of Colombo with WiFi under Rs. 35,000' },
  { icon: 'bi-wallet2', label: 'Best value', query: 'Cheapest WiFi room with a private bathroom and parking' },
];

export function AiChatbot() {
  const { user } = useAuth();
  const welcome: AssistantMessage = { id: 1, role: 'assistant', text: 'Ayubowan! I’m Buddy, your Sri Lankan stay-finding sidekick. Tell me where you study or work, your maximum monthly budget, and anything you cannot compromise on.' };
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState(false);
  const [messages, setMessages] = useState<AssistantMessage[]>([welcome]);
  const [feedbackSent, setFeedbackSent] = useState<Record<number, boolean>>({});
  const [donated, setDonated] = useState<Record<number, boolean>>({});
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
        understanding: response.understanding,
        relaxationAnalysis: response.relaxationAnalysis,
      }]);
    } catch (error) {
      const detail = error as { response?: { data?: { message?: string } }; message?: string };
      setMessages(current => [...current, { id: Date.now() + 1, role: 'assistant', text: detail.response?.data?.message || detail.message || 'I could not complete that search. Your services may still be starting—please try once more.' }]);
    } finally {
      setBusy(false);
    }
  };
  const recordFeedback = async (message: AssistantMessage, event: 'result_click' | 'helpful' | 'not_helpful', listing?: Listing) => {
    if (!user || user.role !== 'tenant') return;
    try {
      await api.post('/ai/feedback', { event, searchLogId: message.search?.searchLogId, listingId: listing?.id, position: listing?.matchRank, matchScore: listing?.matchScore, breakdown: listing?.matchBreakdown });
      if (event !== 'result_click') setFeedbackSent(current => ({ ...current, [message.id]: true }));
    } catch { /* Feedback must never interrupt search or navigation. */ }
  };
  const donateEvaluation = async (message: AssistantMessage) => {
    if (!message.search?.searchLogId || user?.role !== 'tenant') return;
    try {
      await api.post('/ai/evaluation-samples', { searchLogId: message.search.searchLogId, candidateListingIds: message.results?.map(item => item.id) || [], consentConfirmed: true });
      setDonated(current => ({ ...current, [message.id]: true }));
    } catch { /* Optional research participation cannot interrupt Buddy. */ }
  };
  const submit = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); void ask(draft); };

  return <div className={`sb-ai ${open ? 'is-open' : ''}`}>
    {open && <button className="sb-ai-backdrop" aria-label="Close Buddy AI assistant" onClick={() => setOpen(false)} />}
    {open && <section className="sb-ai-window" role="dialog" aria-modal="true" aria-label="Buddy AI assistant">
      <header className="sb-ai-head">
        <div className="sb-ai-identity"><span className="sb-ai-avatar"><BuddyMark /></span><div><strong>Buddy AI</strong><small><i /> Friendly guidance · honest ranking</small></div></div>
        <div className="sb-ai-head-actions"><button aria-label="Start a new Buddy AI conversation" onClick={reset}><i className="bi bi-arrow-counterclockwise" /></button><button aria-label="Close Buddy AI assistant" onClick={() => setOpen(false)}><i className="bi bi-x-lg" /></button></div>
      </header>
      <div className="sb-ai-context"><span><i className="bi bi-shield-check" /> Exact addresses stay private</span><span><i className="bi bi-geo" /> Distances are estimates</span></div>
      <div className="sb-ai-messages" ref={listRef} aria-live="polite">
        {messages.map(message => <div className={`sb-ai-turn ${message.role}`} key={message.id}>
          {message.role === 'assistant' && <span className="sb-ai-mini-avatar"><BuddyMark /></span>}
          <div className="sb-ai-turn-content">
            <div className="sb-ai-bubble">{message.text}</div>
            {message.prompts?.length ? <div className="sb-ai-clarifications" aria-label="Choose a destination branch">{message.prompts.map(prompt => <button key={prompt.name} onClick={() => void ask(prompt.query || `Find a room near ${prompt.name}`)}><i className="bi bi-geo-alt" /><span>{prompt.name}</span><i className="bi bi-chevron-right" /></button>)}</div> : null}
            {message.requirements?.length ? <div className="sb-ai-criteria" aria-label="Applied requirements"><b>Requirements understood</b><div>{message.requirements.map(requirement => <span key={requirement}><i className="bi bi-check2" />{requirement}</span>)}</div></div> : null}
            {message.understanding?.confidence?.overall !== undefined && <div className="sb-ai-understanding"><span><i className="bi bi-translate" />{message.understanding.language?.toUpperCase()}</span><span><i className="bi bi-bullseye" />{Math.round(message.understanding.confidence.overall * 100)}% intent confidence</span>{message.search?.personalized && <span><i className="bi bi-person-check" />Personalized</span>}</div>}
            {message.results?.length ? <div className="sb-ai-results">{message.results.map(listing => <Link className={listing.matchRank === 1 ? 'is-best' : ''} to={`/listing/${listing.id}`} onClick={() => { setOpen(false); void recordFeedback(message, 'result_click', listing); }} key={listing.id}>
              <div className="sb-ai-result-photo"><img src={listing.image || listing.images?.[0]?.thumbnail} alt="" /><span>#{listing.matchRank}</span></div>
              <div className="sb-ai-result-copy"><div><span className="sb-ai-match-label">{listing.matchRank === 1 && <i className="bi bi-trophy-fill" />} {listing.matchLabel}</span><b>{listing.matchScore}% fit</b></div><strong>{listing.title}</strong><p>{listing.area} · {money(listing.price)} / month</p>{listing.distanceKm !== undefined && <small><i className="bi bi-signpost-split" /> {listing.distanceKm} km · about {listing.commuteEstimateMinutes} min</small>}<div className="sb-ai-result-reasons">{listing.matchReasons?.slice(0, 3).map(reason => <span key={reason}><i className="bi bi-check-circle-fill" />{reason}</span>)}</div></div>
              <i className="bi bi-arrow-up-right sb-ai-open-result" />
            </Link>)}</div> : null}
            {message.relaxationAnalysis?.length ? <div className="sb-ai-relaxation"><b>Why nothing matched</b>{message.relaxationAnalysis.slice(0, 4).map(item => <div key={item.constraint}><span>{item.constraint}</span><small>{item.matchesIfRelaxed ? `${item.matchesIfRelaxed} recovered if you approve relaxing it` : `${item.blockedListings} listings fail this condition`}</small></div>)}</div> : null}
            {message.search?.warning && <div className="sb-ai-warning"><i className="bi bi-info-circle" /> {message.search.warning}</div>}
            {message.followUps?.length ? <div className="sb-ai-followups"><b>Refine this search</b>{message.followUps.map(prompt => <button key={prompt.name} onClick={() => void ask(prompt.query || prompt.name)}>{prompt.name}<i className="bi bi-arrow-right" /></button>)}</div> : null}
            {message.disclaimer && <small className="sb-ai-disclaimer"><i className="bi bi-shield-exclamation" /> {message.disclaimer}</small>}
            {message.role === 'assistant' && message.search?.searchLogId && user?.role === 'tenant' && <div className="sb-ai-feedback">{feedbackSent[message.id] ? <span><i className="bi bi-check2" /> Feedback saved</span> : <><span>Was this useful?</span><button onClick={() => void recordFeedback(message, 'helpful')} aria-label="This answer was helpful"><i className="bi bi-hand-thumbs-up" /></button><button onClick={() => void recordFeedback(message, 'not_helpful')} aria-label="This answer was not helpful"><i className="bi bi-hand-thumbs-down" /></button></>}</div>}
            {message.role === 'assistant' && message.search?.searchLogId && user?.role === 'tenant' && <button className="sb-ai-donate" disabled={donated[message.id]} onClick={() => void donateEvaluation(message)}><i className={`bi ${donated[message.id] ? 'bi-check2-circle' : 'bi-clipboard-data'}`} />{donated[message.id] ? 'Anonymized query donated' : 'Donate this anonymized query to improve Buddy'}</button>}
          </div>
        </div>)}
        {busy && <div className="sb-ai-turn assistant"><span className="sb-ai-mini-avatar"><i className="bi bi-stars" /></span><div className="sb-ai-turn-content"><div className="sb-ai-bubble sb-ai-thinking"><span /><span /><span /><b>Checking every hard requirement, then ranking the eligible stays</b></div></div></div>}
      </div>
      {messages.length === 1 && <div className="sb-ai-starters"><span>Try a guided search</span><div>{starterPrompts.map(prompt => <button key={prompt.label} onClick={() => void ask(prompt.query)}><i className={`bi ${prompt.icon}`} /><span><b>{prompt.label}</b><small>{prompt.query}</small></span><i className="bi bi-arrow-right" /></button>)}</div></div>}
      <form className="sb-ai-compose" onSubmit={submit}>
        <label className="visually-hidden" htmlFor="bodim-ai-question">Ask Buddy AI</label>
        <textarea ref={inputRef} id="bodim-ai-question" rows={1} value={draft} onChange={event => setDraft(event.target.value)} onKeyDown={event => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void ask(draft); } }} maxLength={500} placeholder="Campus, budget, WiFi, AC, parking…" />
        <div><small>{draft.length}/500</small><button aria-label="Send to Buddy AI" disabled={busy || draft.trim().length < 2}><i className="bi bi-arrow-up" /></button></div>
      </form>
      <footer><span><i className="bi bi-funnel" /> Hard filters first</span><span><i className="bi bi-bar-chart" /> Suitability ranked second</span></footer>
    </section>}
    <button className="sb-ai-launcher" title={open ? 'Close Buddy AI' : 'Ask Buddy AI'} aria-label={open ? 'Close Buddy AI assistant' : 'Open Buddy AI assistant'} aria-expanded={open} onClick={() => setOpen(!open)}><span><BuddyMark /></span><b>Ask Buddy</b><small>Your stay sidekick</small></button>
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
      <div className="sb-side-help"><BuddyMark /><strong>{role === 'tenant' ? 'Buddy can help' : role === 'owner' ? 'Improve your ranking' : 'System overview'}</strong><small>{role === 'tenant' ? 'Describe your whole stay need in one message.' : role === 'owner' ? 'Complete details help the right tenants find you.' : 'Review trust, content and AI health.'}</small><Link to={role === 'tenant' ? '/nearby' : `/${role}/${role === 'owner' ? 'listings' : 'dashboard'}`}>{role === 'tenant' ? 'Open smart finder' : 'View workspace'} <i className="bi bi-arrow-right" /></Link></div>
    </aside>
    <section className="sb-dashboard"><div className="sb-dashboard-inner">{children}</div></section>
  </main>{role === 'tenant' && <AiChatbot />}</>;
}
