import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../api';
import { AreaSafetyInsight } from '../components/AreaSafetyInsight';

vi.mock('../api', () => ({ api: { get: vi.fn() } }));

const payload = {
  listingId: 49,
  generatedAt: '2026-08-25T10:00:00+05:30',
  score: 76,
  label: 'Good supporting evidence',
  confidence: { level: 'Low', score: 45, reason: 'Limited source confidence.' },
  summary: 'Buddy found a 76/100 evidence score with Low data confidence.',
  dimensions: [
    { key: 'emergency_access', label: 'Emergency access', icon: 'bi-hospital', score: 82, weight: 40, status: 'available', explanation: 'Nearby access to help.' },
    { key: 'community_audits', label: 'Verified community audits', icon: 'bi-people', score: null, weight: 0, status: 'limited', explanation: 'No audits yet.' },
  ],
  signals: [{ type: 'police', label: 'Police station', name: 'Maharagama Police Station', distanceM: 900, sourceProvider: 'project-fixture', sourceConfidence: 40, needsConfirmation: true }],
  communityInsights: { moderatedReportCount: 0, eveningReportCount: 0, researchConsentCount: 0, minimumForScore: 3, themes: [], modelVersion: 'buddy-safety-aspects-v1.0.0', modelOnline: true, evidencePolicy: 'Opinions are not crime statistics.', source: 'Moderated observations' },
  dataGaps: ['No verified day/night community safety audits have been collected.'],
  guidance: ['Visit during daylight and evening.'],
  map: { latitude: 6.85, longitude: 79.92, privacy: 'Approximate marker.', highlightTypes: ['police'] },
  method: { name: 'Transparent hybrid safety evidence baseline', version: 'v1', scoreEngine: 'Deterministic weighted evidence model', explanationEngine: 'Buddy evidence summary', trainingReadiness: 'Ready later.' },
  disclaimer: 'This is decision support, not a guarantee that an area is safe.',
};

afterEach(() => { vi.useRealTimers(); vi.clearAllMocks(); });

describe('AreaSafetyInsight', () => {
  it('animates the evidence check and exposes score, confidence, gaps and map action', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: payload } });
    const openMap = vi.fn();
    const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    render(<QueryClientProvider client={client}><AreaSafetyInsight listingId={49} area="Wattegedara, Maharagama" onOpenMap={openMap} /></QueryClientProvider>);

    fireEvent.click(screen.getByRole('button', { name: /check this area with buddy/i }));
    expect(screen.getByText(/buddy is checking wattegedara/i)).toBeInTheDocument();
    expect(await screen.findByText('Good supporting evidence', {}, { timeout: 2500 })).toBeInTheDocument();
    expect(screen.getByText('Low confidence')).toBeInTheDocument();
    expect(screen.getByText('Maharagama Police Station')).toBeInTheDocument();
    expect(screen.getByText(/no verified day\/night community safety audits/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /highlight on map/i }));
    expect(openMap).toHaveBeenCalledWith('police');
  });
});
