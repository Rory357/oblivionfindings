import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { InjuryRecordPanel } from './injury-record-panels';
import type { InjuryRecord } from './injury-workspace-types';

vi.mock('@inertiajs/react', () => ({ Link: ({ href, children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => <a href={href} {...props}>{children}</a> }));
vi.mock('@/components/files/file-preview-dialog', () => ({ FilePreviewDialog: () => null }));
const record = (changes: Partial<InjuryRecord> = {}) => ({ id: 9, reference: 'INJ-0009', status: 'reported', source_version: 1, source_integrity: 'valid',
    medical_access: 'readable', recovery_integrity: 'valid', evidence_integrity: 'valid', history_integrity: 'valid', rtw_plans: [], capacity_assessments: [], attachments: [], history: [], audits: [],
    can: { recovery: false, review: false, upload_evidence: false, remove_evidence: true }, commands: { expected_version: 1, transitions: [] }, ...changes }) as InjuryRecord;
afterEach(cleanup);

it('does not report none recorded when damaged evidence or history is withheld', () => {
    const d = record({ source_integrity: 'repair_required', evidence_integrity: 'repair_required', history_integrity: 'repair_required' });
    const { rerender } = render(<InjuryRecordPanel detail={d} section="evidence" open={vi.fn()} sourceHref="/health-safety/injuries/9" />);
    expect(screen.getByRole('status')).toHaveTextContent('needs review'); expect(screen.queryByText('No evidence is recorded.')).not.toBeInTheDocument();
    rerender(<InjuryRecordPanel detail={d} section="history" open={vi.fn()} sourceHref="/health-safety/injuries/9" />);
    expect(screen.getByRole('status')).toHaveTextContent('needs review'); expect(screen.queryByText(/No attributed source version/)).not.toBeInTheDocument();
    rerender(<InjuryRecordPanel detail={{ ...d, history: null }} section="history" open={vi.fn()} sourceHref="/health-safety/injuries/9" />);
    expect(screen.getByText('Source needs review')).toBeInTheDocument(); expect(screen.queryByText('Medical details restricted')).not.toBeInTheDocument();
});

it('preserves a real evidence removal command when retained metadata has no retrievable bytes and offers no false preview or download', () => {
    const open = vi.fn(); const d = record({ attachments: [{ id: 31, original_name: 'missing.pdf', mime: 'application/pdf', is_image: false,
        availability: 'unavailable', url: null, source_version_id: 14, source_revision: 1, attribution_state: 'recorded', size: 1024, created_at: null,
        uploaded_by: null, kind: 'document', notes: null, alt_text: null }] });
    render(<InjuryRecordPanel detail={d} section="evidence" open={open} sourceHref="/health-safety/injuries/9" />);
    expect(screen.getByText(/File unavailable/)).toBeInTheDocument(); expect(screen.queryByRole('button', { name: /Preview/ })).not.toBeInTheDocument(); expect(screen.queryByRole('link', { name: /Download/ })).not.toBeInTheDocument();
    // Removal lives in the row's menu (kebab and right-click, drawing pg-hsn-88g).
    fireEvent.contextMenu(screen.getAllByRole('row')[1], { clientX: 70, clientY: 100 });
    expect(screen.queryByRole('menuitem', { name: /Preview|Download/ })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('menuitem', { name: 'Remove evidence' })); expect(open).toHaveBeenCalledWith('remove_evidence', { plan: undefined, attachment: 31 });
});

it('shows an actually cleared historical field and withholds a restricted side without relabelling it absent', () => {
    const entry = { source_revision: 1, source_type: 'injury', command: 'update', content_scope: 'medical' as const, access: 'readable' as const,
        before_access: 'readable' as const, after_access: 'readable' as const, source_context: { worker_id: 12, site_id: 4 }, recorded_at: null,
        recorder: null, attribution_state: 'recorded' as const, before: { notes: 'Previous note' }, after: { notes: null } };
    const { rerender } = render(<InjuryRecordPanel detail={record({ history: [entry] })} section="history" open={vi.fn()} sourceHref="/health-safety/injuries/9" />);
    expect(screen.getByText('Before: Previous note')).toBeInTheDocument(); expect(screen.getByText('Recorded: Not recorded')).toBeInTheDocument();
    rerender(<InjuryRecordPanel detail={record({ history: [{ ...entry, access: 'restricted', after_access: 'restricted', after: null }] })} section="history" open={vi.fn()} sourceHref="/health-safety/injuries/9" />);
    expect(screen.getByText('Recorded: Restricted')).toBeInTheDocument(); expect(screen.getByText('Source version facts')).toBeInTheDocument();
});

it('opens only a readable connection with one bounded injury return and no old receipt or command values', () => {
    const connection = { access: 'absent' as const, id: null, reference: null, status: null, url: null };
    const d = record({ connections: { incident: { ...connection, access: 'readable', id: 8, reference: 'INC-0008', url: '/incidents/8?section=overview' },
        health_safety: { ...connection, access: 'restricted' }, control_room: { ...connection, access: 'unavailable' },
        worksafe: { access: 'restricted', decision_state: null, notification_state: null, notified_at: null, acknowledged_at: null, reference: null, url: null, external_receipt_state: 'unconfirmed' } } });
    render(<InjuryRecordPanel detail={d} section="connections" open={vi.fn()} sourceHref="/health-safety/injuries/9?section=connections&action=close&receipt_key=old&return_to=%2Ftasks" />);
    expect(screen.getByRole('link', { name: 'Open incident' })).toHaveAttribute('href', '/incidents/8?section=overview&return_to=%2Fhealth-safety%2Finjuries%2F9%3Fsection%3Dconnections');
    expect(screen.queryByRole('link', { name: 'Open safety review' })).not.toBeInTheDocument(); expect(screen.queryByRole('link', { name: 'Open control room response' })).not.toBeInTheDocument();
});
