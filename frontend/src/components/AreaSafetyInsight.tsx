import { useMutation } from '@tanstack/react-query';
import { type CSSProperties, type FormEvent, useEffect, useState } from 'react';
import { api } from '../api';
import { BuddyMark } from './Shell';

export interface SafetyDimension {
  key: string;
  label: string;
  icon: string;
  score: number | null;
  weight: number;
  status: 'available' | 'limited';
  explanation: string;
  gap?: string | null;
}

export interface SafetySignal {
  type: string;
  label: string;
  name: string;
  distanceM: number;
  latitude?: number;
  longitude?: number;
  sourceProvider: string;
  sourceConfidence: number;
  needsConfirmation: boolean;
}

export interface AreaSafetyInsightData {
  listingId: number;
  generatedAt: string;
  score: number;
  label: string;
  confidence: { level: 'Low' | 'Medium' | 'High'; score: number; reason: string };
  summary: string;
  dimensions: SafetyDimension[];
  signals: SafetySignal[];
  communityInsights: {
    moderatedReportCount: number;
    eveningReportCount: number;
    researchConsentCount: number;
    minimumForScore: number;
    themes: Array<{ key: string; label: string; supportive: number; concerns: number; mentions: number; direction: 'supportive' | 'concern' | 'mixed' }>;
    modelVersion: string;
    modelOnline: boolean;
    evidencePolicy: string;
    source: string;
  };
  dataGaps: string[];
  guidance: string[];
  map: { latitude: number; longitude: number; privacy: string; highlightTypes: string[] };
  method: { name: string; version: string; scoreEngine: string; explanationEngine: string; trainingReadiness: string };
  disclaimer: string;
}

const stages = [
  ['bi-geo-alt', 'Reading the privacy-safe area marker'],
  ['bi-hospital', 'Checking access to help'],
  ['bi-moon-stars', 'Comparing travel and active places'],
  ['bi-shield-check', 'Checking evidence quality and gaps'],
];

const distance = (metres: number) => metres < 1000 ? `${metres} m` : `${(metres / 1000).toFixed(1)} km`;

export function AreaSafetyInsight({ listingId, area, onOpenMap, canReport = false }: { listingId: number; area: string; onOpenMap: (type?: string) => void; canReport?: boolean }) {
  const [stage, setStage] = useState(0);
  const [expanded, setExpanded] = useState(false);
  const [reportOpen, setReportOpen] = useState(false);
  const [reportMessage, setReportMessage] = useState('');
  const assessment = useMutation({
    mutationFn: async () => {
      const [response] = await Promise.all([
        api.get(`/listings/${listingId}/area-safety`),
        new Promise(resolve => window.setTimeout(resolve, 1550)),
      ]);
      return response.data.data as AreaSafetyInsightData;
    },
    onMutate: () => { setExpanded(true); setStage(0); },
  });
  const report = useMutation({
    mutationFn: (body: unknown) => api.post(`/listings/${listingId}/area-safety/reports`, body),
    onSuccess: response => {
      setReportOpen(false);
      setReportMessage(response.data.message);
    },
  });

  useEffect(() => {
    if (!assessment.isPending) return;
    const timer = window.setInterval(() => setStage(value => Math.min(stages.length - 1, value + 1)), 390);
    return () => window.clearInterval(timer);
  }, [assessment.isPending]);

  const run = () => assessment.mutate();
  const result = assessment.data;
  const keySignals = result?.signals.filter(signal => ['police', 'hospital', 'pharmacy'].includes(signal.type)).slice(0, 3) || [];
  const submitReport = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const values = new FormData(event.currentTarget);
    report.mutate({
      visitBasis: values.get('visitBasis'), visitPeriod: values.get('visitPeriod'), visitedOn: values.get('visitedOn') || null,
      lightingRating: Number(values.get('lightingRating')), transportRating: Number(values.get('transportRating')),
      publicActivityRating: Number(values.get('publicActivityRating')), roadSafetyRating: Number(values.get('roadSafetyRating')),
      emergencyAccessRating: Number(values.get('emergencyAccessRating')), comment: values.get('comment'), consentForResearch: values.get('consentForResearch') === 'on',
    });
  };

  return <section className={`bb-safety-insight ${expanded ? 'is-expanded' : ''}`} aria-labelledby={`safety-title-${listingId}`}>
    <div className="bb-safety-intro">
      <div className="bb-safety-buddy"><BuddyMark /><span><small>Buddy evidence check</small><strong>Buddy checks evidence, never makes promises.</strong></span></div>
      <div><span className="bb-safety-kicker"><BuddyMark className="is-symbol" /> Buddy area insight</span><h2 id={`safety-title-${listingId}`}>Understand the support around {area}.</h2><p>Compare emergency access, travel activity and property protection. Missing information lowers confidence instead of being treated as safe.</p></div>
      {!result && !assessment.isPending && <button className="bb-safety-run" type="button" onClick={run}><BuddyMark className="is-symbol" /><span><strong>Check this area with Buddy</strong><small>See score, confidence and evidence</small></span><i className="bi bi-arrow-right" /></button>}
    </div>

    {expanded && <div className="bb-safety-stage" aria-live="polite">
      {assessment.isPending && <div className="bb-safety-scanning">
        <div className="bb-safety-orbit"><BuddyMark /><i className="bi bi-shield-check" /><span /><span /><span /></div>
        <div><span>Buddy is checking {area}</span><h3>{stages[stage][1]}</h3><div className="bb-safety-progress"><i style={{ width: `${((stage + 1) / stages.length) * 100}%` }} /></div><div className="bb-safety-stage-list">{stages.map(([icon, label], index) => <span className={index <= stage ? 'active' : ''} key={label}><i className={`bi ${index < stage ? 'bi-check2' : icon}`} />{label}</span>)}</div></div>
      </div>}

      {assessment.isError && <div className="bb-safety-error" role="alert"><i className="bi bi-exclamation-triangle" /><div><strong>Buddy could not complete this check.</strong><p>{(assessment.error as { message?: string })?.message || 'Please try again in a moment.'}</p></div><button type="button" onClick={run}>Try again</button></div>}

      {result && !assessment.isPending && <div className="bb-safety-result">
        <header>
          <div className="bb-safety-score" style={{ '--safety-score': `${result.score * 3.6}deg` } as CSSProperties}><span><strong>{result.score}</strong><small>/100</small></span></div>
          <div><span className="bb-safety-result-label"><BuddyMark className="is-symbol" /> Buddy’s evidence result</span><h3>{result.label}</h3><p>{result.summary}</p><div className="bb-safety-confidence"><span className={result.confidence.level.toLowerCase()}>{result.confidence.level} confidence</span><small>{result.confidence.score}% evidence confidence</small></div></div>
          <button className="bb-safety-refresh" type="button" onClick={run} aria-label="Recheck area safety insight"><i className="bi bi-arrow-clockwise" /></button>
        </header>

        <div className="bb-safety-dimensions">{result.dimensions.map(dimension => <article className={dimension.status === 'limited' ? 'is-limited' : ''} key={dimension.key}>
          <span><i className={`bi ${dimension.icon}`} /></span><div><small>{dimension.status === 'available' ? `${dimension.weight}% of available score` : 'Evidence gap'}</small><strong>{dimension.label}</strong><p>{dimension.explanation}</p></div><b>{dimension.score === null ? '—' : dimension.score}</b>
        </article>)}</div>

        <div className="bb-safety-evidence-grid">
          <section><header><span><i className="bi bi-geo-alt" /> Closest support signals</span><button type="button" onClick={() => onOpenMap('police')}>Highlight on map <i className="bi bi-map" /></button></header>{keySignals.length ? keySignals.map(signal => <div key={signal.type}><i className={`bi ${signal.type === 'police' ? 'bi-shield-check' : signal.type === 'hospital' ? 'bi-hospital' : 'bi-capsule'}`} /><span><small>{signal.label}</small><strong>{signal.name}</strong><em>{signal.needsConfirmation ? 'Needs confirmation' : 'Higher-confidence record'}</em></span><b>{distance(signal.distanceM)}</b></div>) : <p>No emergency-support coordinates are recorded.</p>}</section>
          <section className="bb-safety-gaps"><header><span><i className="bi bi-transparency" /> What Buddy cannot confirm</span></header><ul>{result.dataGaps.slice(0, 4).map(gap => <li key={gap}><i className="bi bi-info-circle" />{gap}</li>)}</ul></section>
          <section className="bb-safety-community"><header><span><i className="bi bi-chat-heart" /> Community language signals</span><small>{result.communityInsights.moderatedReportCount}/{result.communityInsights.minimumForScore} reports needed for scoring</small></header>
            {result.communityInsights.themes.length ? <div className="bb-safety-themes">{result.communityInsights.themes.slice(0, 6).map(theme => <article className={`is-${theme.direction}`} key={theme.key}><span><i className={`bi ${theme.direction === 'concern' ? 'bi-exclamation-triangle' : theme.direction === 'supportive' ? 'bi-check2-circle' : 'bi-dash-circle'}`} /></span><div><strong>{theme.label}</strong><small>{theme.supportive} supportive · {theme.concerns} concern</small></div></article>)}</div> : <div className="bb-safety-community-empty"><i className="bi bi-shield-lock" /><span><strong>No AI theme is invented without moderated observations.</strong><small>Structured day and evening reports will appear here after administrator review.</small></span></div>}
            <p><i className="bi bi-info-circle" /> {result.communityInsights.evidencePolicy}</p>
            {canReport && <button type="button" className="bb-safety-share" onClick={() => setReportOpen(true)}><i className="bi bi-plus-circle" /> Share a structured area observation</button>}
            {reportMessage && <div className="bb-safety-report-success" role="status"><i className="bi bi-check-circle" />{reportMessage}</div>}
          </section>
        </div>

        <footer><div><i className="bi bi-shield-exclamation" /><span><strong>Use this as a viewing checklist—not a safety guarantee.</strong><small>{result.disclaimer}</small></span></div><button type="button" onClick={() => onOpenMap()}>Open the full neighbourhood map <i className="bi bi-arrow-right" /></button></footer>
      </div>}
    </div>}
    {reportOpen && <div className="bb-safety-report-backdrop" onMouseDown={event => event.target === event.currentTarget && setReportOpen(false)}><form className="bb-safety-report-form" onSubmit={submitReport}>
      <button type="button" className="bb-safety-report-close" aria-label="Close safety observation form" onClick={() => setReportOpen(false)}><i className="bi bi-x-lg" /></button>
      <BuddyMark /><span className="bb-safety-kicker">Help Buddy learn responsibly</span><h3>Share what you personally observed.</h3><p>Use your own viewing, residence or regular commute experience. Your report stays pending until an administrator reviews it.</p>
      <div className="bb-safety-report-meta"><label>Experience basis<select name="visitBasis" required defaultValue="viewing"><option value="viewing">Property viewing</option><option value="resident">Current or former resident</option><option value="regular_commute">Regular commute through area</option></select></label><label>When did you observe it?<select name="visitPeriod" required defaultValue="both"><option value="day">Daytime</option><option value="evening">Evening</option><option value="both">Both day and evening</option></select></label><label>Approximate date<input type="date" name="visitedOn" max={new Date().toISOString().slice(0, 10)} /></label></div>
      <div className="bb-safety-rating-grid">{[
        ['lightingRating', 'Street lighting'], ['transportRating', 'Transport access'], ['publicActivityRating', 'People and active places'], ['roadSafetyRating', 'Walking and road safety'], ['emergencyAccessRating', 'Access to help'],
      ].map(([name, label]) => <label key={name}>{label}<select name={name} defaultValue="3" required><option value="1">1 — Very limited</option><option value="2">2 — Limited</option><option value="3">3 — Mixed</option><option value="4">4 — Good support</option><option value="5">5 — Strong support</option></select></label>)}</div>
      <label className="bb-safety-report-comment">What did you notice?<textarea name="comment" required minLength={20} maxLength={2000} placeholder="Example: The main road was well lit, but the final lane was quiet after 9 PM and buses stopped early." /></label>
      <label className="bb-safety-consent"><input type="checkbox" name="consentForResearch" required /><span>I consent to this anonymized observation being used to evaluate and improve BodimBuddy’s safety-language model. It will never be presented as an official incident record.</span></label>
      {report.isError && <div className="bb-safety-report-error" role="alert">{(report.error as { message?: string })?.message || 'Could not submit the observation.'}</div>}
      <button className="bb-safety-report-submit" disabled={report.isPending}>{report.isPending ? 'Submitting…' : 'Submit for moderation'} <i className="bi bi-arrow-right" /></button>
    </form></div>}
  </section>;
}
