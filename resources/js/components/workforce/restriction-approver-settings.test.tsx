import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ApproverRead } from './restriction-approver-contract';
import { RestrictionApproverSettings } from './restriction-approver-settings';

const read: ApproverRead = {
    version: 1, actor_id: 4, state: 'configured',
    read_generation: 'a'.repeat(32), scope_site_ids: [2],
    checked_at: '2026-10-12T05:00:00Z', revision: 'b'.repeat(64),
    authorized_user_ids: [7],
    selected_users: [{ id: 7, name: 'Aroha', eligible: true }],
    candidate_options: [{ id: 7, name: 'Aroha' }, { id: 9, name: 'Hemi' }],
    can_configure: true,
};
const props = {
    actorId: 4, draftActorId: 4, read, selectedIds: [7, 9],
    reason: 'Approved cover change', busy: false, unresolved: false,
    onSelectionChange: vi.fn(), onReasonChange: vi.fn(),
    onSave: vi.fn(), onCheckSave: vi.fn(),
};
beforeEach(() => vi.clearAllMocks());
function openEditor() {
    fireEvent.click(screen.getByRole('button', { name: 'Review approvers ↗' }));
}
function openReview() {
    openEditor();
    fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
}

describe('work restriction approver settings presentation', () => {
    it('conceals previous-account names, private draft reason and review immediately', () => {
        const view = render(<RestrictionApproverSettings {...props} />);
        openReview();
        view.rerender(<RestrictionApproverSettings {...props} actorId={5} />);
        expect(screen.queryByText('Aroha')).not.toBeInTheDocument();
        expect(screen.queryByText('Approved cover change')).not.toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        view.rerender(<RestrictionApproverSettings {...props} actorId={5} read={{ ...read, actor_id: 5 }} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText('Aroha')).not.toBeInTheDocument();
    });

    it('keeps pending setup and a configured empty list distinct, without fake people', () => {
        const empty = { ...read, authorized_user_ids: [], selected_users: [] };
        const view = render(<RestrictionApproverSettings {...props} read={{ ...empty, state: 'pending_setup', revision: null }} selectedIds={[]} />);
        expect(screen.getByText('Not set up — no named approvers')).toBeVisible();
        view.rerender(<RestrictionApproverSettings {...props} read={empty} selectedIds={[]} />);
        expect(screen.getByText('No named approvers')).toBeVisible();
    });

    it('allows an explicitly reviewed empty first setup and states that it authorises nobody', () => {
        render(<RestrictionApproverSettings {...props} selectedIds={[]}
            read={{ ...read, state: 'pending_setup', revision: null, authorized_user_ids: [], selected_users: [] }} />);
        openReview();
        expect(screen.getByText(/Nobody will be authorised by this list/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Save approver list' }));
        expect(props.onSave).toHaveBeenCalledWith(expect.objectContaining({ expectedRevision: null, selectedIds: [] }));
    });

    it('does not reopen another account’s editor or reveal its search text', () => {
        const view = render(<RestrictionApproverSettings {...props} />);
        openEditor();
        fireEvent.change(screen.getByLabelText('Find people to add'), { target: { value: 'Private previous search' } });
        view.rerender(<RestrictionApproverSettings {...props} actorId={5} draftActorId={5}
            read={{ ...read, actor_id: 5 }} selectedIds={[]} reason="" />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        openEditor();
        expect(screen.getByLabelText('Find people to add')).toHaveValue('');
        expect(screen.getByLabelText('Reason for this change')).toHaveValue('');
    });

    it('requires explicit removal of a retained person who is no longer selectable', () => {
        const retained = { ...read, authorized_user_ids: [7, 12], selected_users: [
            ...read.selected_users, { id: 12, name: 'Moana', eligible: false },
        ] };
        render(<RestrictionApproverSettings {...props} read={retained} selectedIds={[7, 9, 12]} />);
        openEditor();
        expect(screen.getByText(/No longer selectable/)).toBeVisible();
        expect(screen.getByRole('button', { name: 'Review changes' })).toBeDisabled();
        const row = screen.getByText('Moana').closest('li')!;
        fireEvent.click(within(row).getByRole('button', { name: 'Remove' }));
        expect(props.onSelectionChange).toHaveBeenCalledWith([7, 9]);
        expect(props.onSave).not.toHaveBeenCalled();
    });

    it('saves the exact reviewed selection and reason, rather than later live inputs', () => {
        const view = render(<RestrictionApproverSettings {...props} />);
        openReview();
        expect(screen.getByText(/This adds people/)).toBeVisible();
        view.rerender(<RestrictionApproverSettings {...props} selectedIds={[7]} reason="Later edit" />);
        fireEvent.click(screen.getByRole('button', { name: 'Save approver list' }));
        expect(props.onSave).toHaveBeenCalledWith({
            actorId: 4, readGeneration: read.read_generation, expectedRevision: read.revision,
            selectedIds: [7, 9], reason: 'Approved cover change',
        });
        expect(Object.isFrozen(props.onSave.mock.calls[0][0].selectedIds)).toBe(true);
    });

    it('invalidates review after a new read generation and keeps the draft for review', () => {
        const view = render(<RestrictionApproverSettings {...props} />);
        openReview();
        view.rerender(<RestrictionApproverSettings {...props} read={{ ...read, read_generation: 'c'.repeat(32) }} />);
        expect(screen.queryByRole('button', { name: 'Save approver list' })).not.toBeInTheDocument();
        expect(screen.getByText(/Your draft is retained/)).toBeVisible();
        expect(screen.getByLabelText('Reason for this change')).toHaveValue(props.reason);
        expect(props.onSave).not.toHaveBeenCalled();
        view.rerender(<RestrictionApproverSettings {...props} />);
        expect(screen.queryByRole('button', { name: 'Save approver list' })).not.toBeInTheDocument();
        expect(screen.getByLabelText('Reason for this change')).toHaveValue(props.reason);
    });

    it('permanently withdraws a review after access loss even if the same old frame returns', () => {
        const view = render(<RestrictionApproverSettings {...props} />);
        openReview();
        view.rerender(<RestrictionApproverSettings {...props} read={{ ...read, can_configure: false }} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        view.rerender(<RestrictionApproverSettings {...props} />);
        expect(screen.queryByRole('button', { name: 'Save approver list' })).not.toBeInTheDocument();
        expect(props.onSave).not.toHaveBeenCalled();
    });

    it('checks an uncertain original attempt without sending a new save', () => {
        render(<RestrictionApproverSettings {...props} unresolved />);
        fireEvent.click(screen.getByRole('button', { name: 'Check last save' }));
        expect(props.onCheckSave).toHaveBeenCalledOnce();
        openEditor();
        expect(screen.getByRole('button', { name: 'Review changes' })).toBeDisabled();
        expect(screen.getByLabelText('Reason for this change')).toBeDisabled();
        expect(props.onSave).not.toHaveBeenCalled();
    });

    it('closes the editor without discarding the controlled draft', () => {
        render(<RestrictionApproverSettings {...props} />);
        openEditor();
        fireEvent.click(screen.getAllByRole('button', { name: 'Close', exact: true })[0]);
        openEditor();
        expect(screen.getByLabelText('Reason for this change')).toHaveValue(props.reason);
        expect(props.onSelectionChange).not.toHaveBeenCalled();
        expect(props.onReasonChange).not.toHaveBeenCalled();
    });
});
