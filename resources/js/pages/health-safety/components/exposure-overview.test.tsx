import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import type { ExposureRecordEnvelope } from '@/lib/health-safety/chemical-exposure-read-contract';
import { ExposureConnections, ExposureOverview } from './exposure-overview';
function detail(): ExposureRecordEnvelope {
    return { exposure_access: 'readable', id: 22, source_integrity: 'recorded',
        command_context: { root_type: 'exposure', root_id: 22, expected_version: 3, source_version_id: 13 },
        can: { correct: true, review: true, assign_followup: true, upload_evidence: true },
        record: { id: 22, substance: { id: 9, name: 'Recorded chemical' }, worker: { id: 7, name: 'Synthetic worker' }, site: { id: 3, name: 'Kauri House' },
            exposed_at: '2026-09-03T00:00:00Z', exposure_type: 'skin_contact', exposure_duration: null, circumstances: 'Recorded circumstances',
            symptoms: 'Recorded symptoms', first_aid_given: null, medical_attention_sought: false, medical_treatment: 'none', medical_outcome: null,
            incident_reported: false, incident_connection: { state: 'unknown', id: null, href: null, reason: 'Original association unknown' },
            recorded_by: { id: 8, name: 'Synthetic recorder' }, updated_by: null, created_at: '2026-09-03T00:01:00Z', updated_at: '2026-09-03T00:01:00Z',
            attribution_state: 'recorded', href: '/health-safety/chemical-exposures/22' } };
}
afterEach(cleanup);
it('retains actual exposure facts, unknown outcomes and negative responses without claiming recovery or delivery', () => {
    const d = detail();
    d.record!.fact_states = {
        medical_attention_sought: { state: 'reported', recorded_value: false, reported_at: '2026-09-03T00:01:00Z' },
        incident_reported: { state: 'reported', recorded_value: false, reported_at: '2026-09-03T00:01:00Z' },
        medical_treatment: { state: 'reported', recorded_value: 'none', reported_at: '2026-09-03T00:01:00Z' },
    };
    render(<ExposureOverview detail={d} />);
    expect(screen.getByText('Synthetic worker')).toBeInTheDocument();
    expect(screen.getByText('Recorded symptoms')).toBeInTheDocument();
    expect(screen.getAllByText('No')).toHaveLength(2);
    expect(screen.getAllByText('Not recorded').length).toBeGreaterThan(1);
    expect(screen.getByText(/does not confirm recovery/)).toBeInTheDocument();
    // Correct recorded facts is a header action now (drawing pg-hsn-129); the overview itself carries no commands.
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
});
it('shows Unknown separately from an unconfirmed earlier negative or an answer from a prior worker and Site', () => {
    const d = detail(); const { rerender } = render(<ExposureOverview detail={d} />);
    expect(screen.getAllByText('Unknown · earlier recorded value: No (unconfirmed)')).toHaveLength(2);
    expect(screen.queryByText('No')).not.toBeInTheDocument();
    d.record!.medical_attention_sought = null; d.record!.incident_reported = null;
    d.record!.fact_states = {
        medical_attention_sought: { state: 'reported_unknown', recorded_value: null, reported_at: '2026-09-03T00:01:00Z' },
        incident_reported: { state: 'unanswered', recorded_value: null, reported_at: null },
        medical_treatment: { state: 'prior_context', recorded_value: 'first_aid', reported_at: '2026-09-03T00:01:00Z' },
    };
    rerender(<ExposureOverview detail={d} />);
    expect(screen.getByText('Unknown · reported')).toBeInTheDocument(); expect(screen.getByText('Unknown · not answered')).toBeInTheDocument();
    expect(screen.getByText('Unknown for this worker and Site · earlier answer: first aid')).toBeInTheDocument();
    rerender(<ExposureOverview detail={{ ...d, source_integrity: 'repair_required' }} />);
    expect(screen.queryByText(/earlier answer:/)).not.toBeInTheDocument(); expect(screen.queryByText('Unknown · reported')).not.toBeInTheDocument();
});
it('withholds every cached private field and action when the source requires repair', () => {
    const d = detail(); d.source_integrity = 'repair_required';
    render(<><ExposureOverview detail={d} /><ExposureConnections detail={d} /></>);
    expect(screen.queryByText('Synthetic worker')).not.toBeInTheDocument();
    expect(screen.queryByText('Recorded symptoms')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Correct recorded facts' })).not.toBeInTheDocument();
    expect(screen.getByText(/Details and changes are withheld/)).toBeInTheDocument();
});
it('shows the proved care-review signal and withdraws it with source access', () => {
    const d = detail(); d.record!.response = {
        reported_attention: 'unknown', recorded_category: null, category_state: 'unanswered', response_floor: 'medium',
        basis: 'operational_triage', care_review_needed: true, harm_assessment: 'unassessed',
    };
    // Record a review is the header's primary action (gated there by can.review and the command context).
    const { rerender } = render(<ExposureOverview detail={d} />);
    expect(screen.getByText('Care answers need review')).toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Record a review' })).not.toBeInTheDocument();
    rerender(<ExposureOverview detail={{ ...d, can: { ...d.can, review: false } }} />);
    expect(screen.getByText('Care answers need review')).toBeInTheDocument();
    rerender(<ExposureOverview detail={{ ...d, source_integrity: 'repair_required' }} />);
    expect(screen.queryByText('Care answers need review')).not.toBeInTheDocument();
    rerender(<ExposureOverview detail={d} />); expect(screen.getByText('Care answers need review')).toBeInTheDocument();
    d.record!.response.care_review_needed = false; rerender(<ExposureOverview detail={d} />);
    expect(screen.queryByText('Care answers need review')).not.toBeInTheDocument();
});
it('opens only the independently recorded incident identity and never invents a destination from an unknown association', () => {
    const d = detail();
    const { rerender } = render(<ExposureConnections detail={d} />);
    expect(screen.queryByRole('link', { name: 'Open related incident' })).not.toBeInTheDocument();
    expect(screen.getByText(/original incident association is unconfirmed/)).toBeInTheDocument();
    expect(screen.getByText(/do not confirm that another team received/)).toBeInTheDocument();
    d.record!.incident_connection = { state: 'recorded', id: 11, href: '/incidents/12', reason: null };
    rerender(<ExposureConnections detail={d} />); expect(screen.queryByRole('link', { name: 'Open related incident' })).not.toBeInTheDocument();
    d.record!.incident_connection.href = '/incidents/11';
    rerender(<ExposureConnections detail={d} />);
    const href = screen.getByRole('link', { name: 'Open related incident' }).getAttribute('href')!;
    const target = new URL(href, 'https://example.invalid');
    expect(target.pathname).toBe('/incidents/11');
    expect(target.searchParams.get('return_to')).toBe('/health-safety/chemical-exposures/22?section=connections');
});
it('opens the chemical safety reference and preserves the exact private Back leaf without a saved command', () => {
    render(<ExposureConnections detail={detail()} />);
    const target = new URL(screen.getByRole('link', { name: 'Open chemical reference' }).getAttribute('href')!, 'https://example.invalid');
    expect(target.pathname).toBe('/health-safety/substances/9'); expect(target.searchParams.get('return_to')).toBe('/health-safety/chemical-exposures/22?section=connections');
    expect(target.searchParams.has('action')).toBe(false); expect(target.searchParams.has('receipt_key')).toBe(false);
});
