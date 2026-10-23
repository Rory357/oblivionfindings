import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ConsultationDetailDialog } from './consultation-detail-dialog';
import type { ConsultationDetail } from './shared';

// Preserve the real detail pane, WizardShell and Radix dialog; no command is submitted.
vi.mock('@inertiajs/react', () => ({
    useForm: (data: Record<string, unknown>) => ({
        data,
        processing: false,
        errors: {},
        put: vi.fn(),
    }),
}));
afterEach(cleanup);
function detail(): ConsultationDetail {
    return {
        id: 31,
        title: 'Consultation topic',
        consultation_type: 'general',
        consultation_date: '2026-10-11',
        status: 'open',
        site: null,
        site_ownership: 'unconfirmed',
        description: 'Recorded topic',
        document_path: null,
        outcome_document_path: null,
        worker_feedback_summary: null,
        outcome: null,
        changes_made: null,
        document_name: null,
        outcome_document_name: null,
        workers_consulted: null,
        initiated_by: null,
    };
}
function show(record: ConsultationDetail, action: 'close' | null = 'close') {
    return render(
        <ConsultationDetailDialog
            detail={record}
            open
            onClose={vi.fn()}
            sites={[]}
            staff={[]}
            can={{ manage: true }}
            initialAction={action}
        />,
    );
}
describe('Consultation ownership and lifecycle detail', () => {
    it('keeps missing ownership explicit inside the actual close pane', () => {
        show(detail());
        expect(
            screen.getByText(/Site ownership not confirmed ·/i),
        ).toBeInTheDocument();
        expect(screen.getByRole('note')).toHaveTextContent(
            'No Site is recorded.',
        );
        expect(screen.queryByText(/All sites/i)).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Close consultation' }),
        ).toBeInTheDocument();
    });
    it('retains the recorded Site in the same close pane without a missing-ownership notice', () => {
        show({
            ...detail(),
            site: { id: 4, name: 'North House' },
            site_ownership: 'recorded',
        });
        expect(screen.getByText(/North House ·/i)).toBeInTheDocument();
        expect(
            screen.queryByText('No Site is recorded.'),
        ).not.toBeInTheDocument();
    });
    it('does not present an unrecognised status as Open or the first lifecycle stage', () => {
        const record = {
            ...detail(),
            status: 'legacy_review',
            stage_index: 0,
            worker_feedback_summary: 'Retained worker feedback',
            outcome: 'Retained recorded outcome',
            changes_made: 'Retained recorded changes',
        };
        show(record, 'close');
        // Begin on the existing lifecycle section by cancelling its close pane.
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByText('Lifecycle stage not confirmed'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Retained worker feedback'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Retained recorded outcome'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Retained recorded changes'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('legacy_review', { exact: true }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Open', { exact: true }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Current', { exact: true }),
        ).not.toBeInTheDocument();
    });
});
