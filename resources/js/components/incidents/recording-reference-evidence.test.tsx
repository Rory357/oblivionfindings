import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    RecordingReferenceEvidence,
    type RecordingReferenceEvidenceData,
} from './recording-reference-evidence';

afterEach(cleanup);

function fixture(): RecordingReferenceEvidenceData {
    return {
        schema_version: 1,
        incident_id: 11,
        incident_evidence_version: 2,
        window: {
            revision: 1,
            start_at: '2026-10-18T00:00:00Z',
            end_at: '2026-10-18T00:10:00Z',
            rule_identifier: 'fall-review',
            rule_revision: 3,
            context_state: 'current',
        },
        references: {
            page: 1,
            per_page: 20,
            total: 1,
            has_more: false,
            next_page: null,
            previous_page: null,
            items: [{
                attachment_uuid: '12345678-1234-4234-8234-123456789abc',
                window_revision: 1,
                attached_evidence_version: 2,
                recorded_at: '2026-10-18T00:15:00.000000Z',
                requested_start_at: '2026-10-18T00:00:00Z',
                requested_end_at: '2026-10-18T00:10:00Z',
                access: 'readable',
                reference: {
                    reference_uuid: '12345678-1234-4234-8234-123456789abd',
                    camera: { id: 20, name: 'Private camera sentinel' },
                    recorder: { id: 30, name: 'Private recorder sentinel' },
                    site: { id: 4, name: 'Private Site sentinel' },
                    purpose: 'Private source purpose sentinel',
                    state: 'reference_only',
                    preserved: false,
                    window_coverage: 'not_verified',
                    recording_verified_at: null,
                    vendor_retention_until: null,
                    current_source: { state: 'ready', source_verified_at: null },
                },
            }],
        },
    };
}

describe('Incident recording references', () => {
    it('shows a source reference without offering unsupported media or preservation actions', () => {
        render(<RecordingReferenceEvidence incidentId={11} evidence={fixture()} onPageChange={vi.fn()} />);
        expect(screen.getByText('Private camera sentinel')).toBeTruthy();
        expect(screen.getByText('Source available')).toBeTruthy();
        expect(screen.getByText('Reference only')).toBeTruthy();
        expect(screen.getByText('Coverage not verified')).toBeTruthy();
        expect(screen.getByText('Preservation not confirmed')).toBeTruthy();
        expect(screen.getByText(/Recording availability and retention are unknown/)).toBeTruthy();
        expect(screen.getByText('Requested window (NZ time)')).toBeTruthy();
        expect(screen.getAllByText(/18 October 2026/).length).toBeGreaterThan(0);
        expect(screen.queryByRole('link')).toBeNull();
        expect(screen.queryByRole('button', { name: /play|watch|preserve|download/i })).toBeNull();
    });

    it('conceals stale source metadata when a bound reference becomes restricted', () => {
        const evidence = fixture();
        const { rerender } = render(<RecordingReferenceEvidence incidentId={11} evidence={evidence} onPageChange={vi.fn()} />);
        expect(screen.getByText('Private camera sentinel')).toBeTruthy();
        evidence.references.items[0].access = 'restricted';
        // Deliberately retain a stale reference object: the access discriminator still wins.
        rerender(<RecordingReferenceEvidence incidentId={11} evidence={evidence} onPageChange={vi.fn()} />);
        expect(screen.getByText('Access restricted')).toBeTruthy();
        for (const sentinel of ['Private camera sentinel', 'Private recorder sentinel', 'Private Site sentinel', 'Private source purpose sentinel']) {
            expect(screen.queryByText(sentinel)).toBeNull();
        }
        expect(screen.queryByText('Source available')).toBeNull();
        expect(screen.queryByText(/No recording references have been linked/)).toBeNull();
        expect(screen.getByText(/Page 1 · 1 linked reference/)).toBeTruthy();
    });

    it('withholds a projection belonging to another incident', () => {
        render(<RecordingReferenceEvidence incidentId={12} evidence={fixture()} onPageChange={vi.fn()} />);
        expect(screen.getByRole('alert')).toBeTruthy();
        expect(screen.queryByText('Private camera sentinel')).toBeNull();
        expect(screen.queryByRole('navigation')).toBeNull();
    });

    it.each([
        ['changed', 'Source changed'],
        ['withdrawn', 'Source withdrawn'],
        ['unavailable', 'Source unavailable'],
    ] as const)('retains authorised historical reference details when the current source is %s', (state, label) => {
        const evidence = fixture();
        evidence.references.items[0].reference!.current_source.state = state;
        render(<RecordingReferenceEvidence incidentId={11} evidence={evidence} onPageChange={vi.fn()} />);
        expect(screen.getByText(label)).toBeTruthy();
        expect(screen.getByText('Private source purpose sentinel')).toBeTruthy();
        expect(screen.getByText('Coverage not verified')).toBeTruthy();
        expect(screen.queryByRole('link')).toBeNull();
    });

    it('keeps original history visible when incident context changed', () => {
        const evidence = fixture();
        evidence.window!.context_state = 'changed';
        render(<RecordingReferenceEvidence incidentId={11} evidence={evidence} onPageChange={vi.fn()} />);
        expect(screen.getByText(/Incident details changed after this window was recorded/)).toBeTruthy();
        expect(screen.getByText('Private camera sentinel')).toBeTruthy();
    });

    it('does not infer an active window from older links', () => {
        const evidence = fixture();
        evidence.window = null;
        render(<RecordingReferenceEvidence incidentId={11} evidence={evidence} onPageChange={vi.fn()} />);
        expect(screen.getByText('No active evidence window is recorded.')).toBeTruthy();
        expect(screen.getByText('Private camera sentinel')).toBeTruthy();
    });

    it('passes the server page number and prevents duplicate navigation while loading', () => {
        const evidence = fixture();
        Object.assign(evidence.references, { total: 41, has_more: true, next_page: 2 });
        const change = vi.fn();
        const { rerender } = render(<RecordingReferenceEvidence incidentId={11} evidence={evidence} onPageChange={change} />);
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(change).toHaveBeenCalledTimes(1);
        expect(change).toHaveBeenCalledWith(2);
        rerender(<RecordingReferenceEvidence incidentId={11} evidence={evidence} onPageChange={change} loading />);
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(change).toHaveBeenCalledTimes(1);
        expect(screen.getByText('Loading recording references…')).toBeTruthy();
    });

    it('distinguishes an empty page from an incident with no history', () => {
        const evidence = fixture();
        Object.assign(evidence.references, { items: [], page: 3, total: 21, previous_page: 2 });
        const change = vi.fn();
        render(<RecordingReferenceEvidence incidentId={11} evidence={evidence} onPageChange={change} />);
        expect(screen.getByText(/No recording references are on this page/)).toBeTruthy();
        expect(screen.queryByText(/No recording references have been linked/)).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Previous page' }));
        expect(change).toHaveBeenCalledTimes(1);
        expect(change).toHaveBeenCalledWith(2);
    });
});
