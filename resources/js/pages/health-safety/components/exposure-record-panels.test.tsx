import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import type { ExposureSection } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import { ExposureRecordPanel } from './exposure-record-panels';

function detail(): ExposureWorkspaceDetail {
    const empty = { items: [], page: { current_page: 1, last_page: 1, total: 0, per_page: 25, has_more: false }, source_integrity: 'admitted' as const };
    return { exposure_access: 'readable', id: 22, source_integrity: 'recorded',
        command_context: { root_type: 'exposure', root_id: 22, expected_version: 3, source_version_id: 13 },
        can: { correct: true, review: true, assign_followup: true, upload_evidence: true },
        record: { id: 22, substance: { id: 9, name: 'Chemical reference' }, worker: { id: 7, name: 'Synthetic worker' }, site: { id: 3, name: 'Synthetic site' },
            exposed_at: '2026-09-03T00:00:00Z', exposure_type: 'skin_contact', exposure_duration: null, circumstances: 'Private root circumstances',
            symptoms: null, first_aid_given: null, medical_attention_sought: false, medical_treatment: 'none', medical_outcome: null,
            incident_reported: false, incident_connection: { state: 'absent', id: null, href: null, reason: null },
            recorded_by: { id: 8, name: 'Synthetic recorder' }, updated_by: null, created_at: null, updated_at: null,
            attribution_state: 'recorded', href: '/health-safety/chemical-exposures/22' },
        reviews: { ...empty, items: [{ id: 30, attribution_state: 'recorded', reviewed_at: null, outcome: 'needs_followup', notes: 'Private review notes', recorded_by: { id: 8, name: 'Synthetic reviewer' } }] },
        followups: { ...empty, items: [{ id: 31, attribution_state: 'recorded', assigned_to: { id: 8, name: 'Synthetic assignee' }, instruction: 'Private follow-up instruction',
            due_at: null, completed_at: null, completion_notes: null, recorded_by: { id: 7, name: 'Synthetic assigner' }, completed_by: null, can_complete: true, can_reassign: true }] },
        attachments: { ...empty, items: [{ id: 32, attribution_state: 'recorded', available: true, original_name: 'private-synthetic.txt', description: 'Private evidence description',
            href: '/health-safety/chemical-exposures/22/evidence/32/download', can_remove: true, mime: 'text/plain', size: 2 }] },
        history: { ...empty, source_integrity: 'recorded', items: [{ id: 13, revision: 3, source_type: 'exposure', source_id: 22, command: 'correct_exposure',
            recorded_at: '2026-09-03T00:00:00Z', recorded_by: { id: 8, name: 'Synthetic recorder' }, reason: 'Private recorded reason',
            before: { user_id: 99, medical_attention_sought: false, medical_outcome: null }, after: { user_id: 7, medical_attention_sought: true, circumstances: 'Private historic circumstances' }, attribution_state: 'recorded' }] },
        delivery: { state: 'unconfirmed', attempts: null } };
}
afterEach(cleanup);
it('withdraws all cached private sections and command entry points when root integrity or identity is lost', () => {
    const d = detail(); const open = vi.fn(); const onPage = vi.fn();
    const { rerender } = render(<ExposureRecordPanel detail={{ ...d, source_integrity: 'repair_required' }} section="overview" open={open} onPage={onPage} />);
    for (const section of ['overview', 'reviews', 'followups', 'evidence', 'history', 'connections'] as ExposureSection[]) {
        rerender(<ExposureRecordPanel detail={{ ...d, source_integrity: 'repair_required' }} section={section} open={open} onPage={onPage} />);
        expect(screen.queryByText(/Private /)).not.toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument(); expect(screen.queryByRole('link')).not.toBeInTheDocument();
    }
    rerender(<ExposureRecordPanel detail={{ ...d, id: 90 }} section="reviews" open={open} onPage={onPage} />);
    expect(screen.queryByText('Private review notes')).not.toBeInTheDocument();
    expect(open).not.toHaveBeenCalled();
});
it('retains the named assignee and server completion authority, then withdraws completion after current flags change', () => {
    const d = detail(); const open = vi.fn(); const onPage = vi.fn();
    const { rerender } = render(<ExposureRecordPanel detail={d} section="followups" open={open} onPage={onPage} />);
    expect(screen.getByText('Synthetic assignee')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Record completion' })); expect(open).toHaveBeenCalledWith('complete_followup', 31);
    const row = d.followups.items![0]; if (row.attribution_state !== 'repair_required') row.can_complete = false;
    rerender(<ExposureRecordPanel detail={d} section="followups" open={open} onPage={onPage} />);
    expect(screen.queryByRole('button', { name: 'Record completion' })).not.toBeInTheDocument();
    d.command_context = null; rerender(<ExposureRecordPanel detail={d} section="followups" open={open} onPage={onPage} />);
    expect(screen.queryByRole('button', { name: 'Assign a follow-up' })).not.toBeInTheDocument();
});
it('does not expose substituted or legacy evidence downloads, and hides even cached details of a repaired child', () => {
    const d = detail(); const row = d.attachments.items![0]; if (row.attribution_state !== 'repair_required') row.href = '/health-safety/chemical-exposures/23/evidence/32/download';
    const { rerender } = render(<ExposureRecordPanel detail={d} section="evidence" open={vi.fn()} onPage={vi.fn()} />);
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    if (row.attribution_state !== 'repair_required') row.attribution_state = 'legacy_unknown';
    rerender(<ExposureRecordPanel detail={d} section="evidence" open={vi.fn()} onPage={vi.fn()} />);
    expect(screen.queryByText('private-synthetic.txt')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Remove evidence' })).not.toBeInTheDocument();
    d.attachments.items = [{ ...row, attribution_state: 'repair_required', record: null, available: false, href: null, can_complete: false, can_reassign: false, can_remove: false }];
    rerender(<ExposureRecordPanel detail={d} section="evidence" open={vi.fn()} onPage={vi.fn()} />);
    expect(screen.queryByText('Private evidence description')).not.toBeInTheDocument();
});
it('keeps unknown collection totals unknown and preserves the correct section page key', () => {
    const d = detail(); const onPage = vi.fn(); d.reviews.source_integrity = 'repair_required';
    d.reviews.page = { current_page: 2, last_page: null, total: null, per_page: 25, has_more: true };
    render(<ExposureRecordPanel detail={d} section="reviews" open={vi.fn()} onPage={onPage} />);
    expect(screen.getByText('Page 2 · total unconfirmed')).toBeInTheDocument();
    expect(screen.getByText('Private review notes')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Next page' })); expect(onPage).toHaveBeenCalledWith('reviews_page', 3);
    fireEvent.click(screen.getByRole('button', { name: 'Previous page' })); expect(onPage).toHaveBeenCalledWith('reviews_page', 1);
});
it('shows retained historical association numbers and explicit before/after values without substituting current identities', () => {
    render(<ExposureRecordPanel detail={detail()} section="history" open={vi.fn()} onPage={vi.fn()} />);
    expect(screen.getByText('99')).toBeInTheDocument(); expect(screen.getByText('7')).toBeInTheDocument();
    expect(screen.getByText('Saved value: No')).toBeInTheDocument(); expect(screen.getByText('Saved value: Yes')).toBeInTheDocument();
    expect(screen.queryByText('No')).not.toBeInTheDocument(); expect(screen.getByText(/does not confirm their original reporting/)).toBeInTheDocument();
    expect(screen.getByText('Not recorded')).toBeInTheDocument();
    expect(screen.queryByText('Synthetic worker')).not.toBeInTheDocument();
    expect(screen.getByText('Private historic circumstances')).toBeInTheDocument();
});
it('offers transfer only for the current server-admitted incomplete child and keeps completion immutable', () => {
    const d = detail(); const open = vi.fn(); const onPage = vi.fn();
    const { rerender } = render(<ExposureRecordPanel detail={d} section="followups" open={open} onPage={onPage} />);
    fireEvent.click(screen.getByRole('button', { name: 'Transfer follow-up' })); expect(open).toHaveBeenCalledWith('reassign_followup', 31);
    const row = d.followups.items![0]; if (row.attribution_state === 'repair_required') throw new Error('Expected admitted fixture');
    row.can_reassign = false; rerender(<ExposureRecordPanel detail={d} section="followups" open={open} onPage={onPage} />);
    expect(screen.queryByRole('button', { name: 'Transfer follow-up' })).not.toBeInTheDocument();
    row.can_reassign = true; row.completed_at = '2026-09-03T02:00:00Z'; rerender(<ExposureRecordPanel detail={d} section="followups" open={open} onPage={onPage} />);
    expect(screen.queryByRole('button', { name: 'Transfer follow-up' })).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Record completion' })).not.toBeInTheDocument();
});
it('focuses only the admitted selected follow-up without granting completion or transfer', () => {
    const d = detail(); const row = d.followups.items![0];
    if (row.attribution_state === 'repair_required') throw new Error('Expected admitted fixture');
    row.can_complete = false; row.can_reassign = false;
    d.followup_focus_state = 'available'; d.focused_followup_id = 31;
    const open = vi.fn(); const props = { detail: d, section: 'followups' as const, open, onPage: vi.fn(), focusedFollowupId: 31 };
    const { rerender } = render(<ExposureRecordPanel {...props} />);
    expect(screen.getByRole('listitem', { name: 'Selected follow-up' })).toHaveFocus();
    expect(screen.queryByRole('button', { name: 'Record completion' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Transfer follow-up' })).not.toBeInTheDocument();
    expect(open).not.toHaveBeenCalled();
    rerender(<ExposureRecordPanel {...props} focusedFollowupId={90} />);
    expect(screen.queryByRole('listitem', { name: 'Selected follow-up' })).not.toBeInTheDocument();
    rerender(<ExposureRecordPanel {...props} detail={{ ...d, source_integrity: 'repair_required' }} />);
    expect(screen.queryByText('Private follow-up instruction')).not.toBeInTheDocument();
    expect(screen.queryByRole('listitem')).not.toBeInTheDocument();
    rerender(<ExposureRecordPanel {...props} />);
    expect(screen.getByRole('listitem', { name: 'Selected follow-up' })).toHaveFocus();
});
it('withholds focus when the accepted child is missing, legacy, repaired or currently unavailable', () => {
    const d = detail(); d.followup_focus_state = 'available'; d.focused_followup_id = 31;
    const props = { detail: d, section: 'followups' as const, open: vi.fn(), onPage: vi.fn(), focusedFollowupId: 31 };
    const row = d.followups.items![0]; if (row.attribution_state === 'repair_required') throw new Error('Expected admitted fixture');
    row.attribution_state = 'legacy_unknown'; const { rerender } = render(<ExposureRecordPanel {...props} />);
    expect(screen.queryByRole('listitem', { name: 'Selected follow-up' })).not.toBeInTheDocument();
    row.attribution_state = 'recorded'; rerender(<ExposureRecordPanel {...props} detail={{ ...d, followup_focus_state: 'unavailable' }} />);
    expect(screen.queryByRole('listitem', { name: 'Selected follow-up' })).not.toBeInTheDocument();
    rerender(<ExposureRecordPanel {...props} detail={{ ...d, followups: { ...d.followups, items: [] } }} />);
    expect(screen.queryByRole('listitem', { name: 'Selected follow-up' })).not.toBeInTheDocument();
    rerender(<ExposureRecordPanel {...props} detail={{ ...d, followups: { ...d.followups, items: [{ id: 31,
        attribution_state: 'repair_required', record: null, available: false, href: null, can_complete: false, can_reassign: false, can_remove: false }] } }} />);
    expect(screen.queryByRole('listitem', { name: 'Selected follow-up' })).not.toBeInTheDocument();
    expect(screen.queryByText('Private follow-up instruction')).not.toBeInTheDocument();
});
it('separates a newly recorded completion from unconfirmed earlier assignment and legacy completion values', () => {
    const d = detail(); const row = d.followups.items![0]; if (row.attribution_state === 'repair_required') throw new Error('Expected admitted fixture');
    row.completed_at = '2026-09-03T02:00:00Z'; row.assignment_attribution_state = 'legacy_unknown'; row.completion_attribution_state = 'recorded';
    const props = { detail: d, section: 'followups' as const, open: vi.fn(), onPage: vi.fn() };
    const { rerender } = render(<ExposureRecordPanel {...props} />);
    expect(screen.getByText('Completion recorded')).toBeInTheDocument(); expect(screen.getByText(/Earlier assignment history is unconfirmed/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Record completion' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Transfer follow-up' })).not.toBeInTheDocument();
    row.assignment_attribution_state = 'recorded'; rerender(<ExposureRecordPanel {...props} />);
    expect(screen.queryByText(/Earlier assignment history is unconfirmed/)).not.toBeInTheDocument();
    row.completion_attribution_state = undefined; rerender(<ExposureRecordPanel {...props} />);
    expect(screen.getByText('Earlier completion unconfirmed')).toBeInTheDocument(); expect(screen.queryByText('Completion recorded')).not.toBeInTheDocument();
});
