import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import {
    taskHistoryResponse,
    taskReadiness,
} from '@/test/it-work-task-fixtures';
import { TicketWorkTasks, type TicketWorkTask } from '../ticket-work-tasks';

const completed: TicketWorkTask = {
    id: 9,
    title: 'Task B: reviewed access change',
    description: 'Synthetic private work',
    status: 'completed',
    due_at: null,
    is_required: true,
    evidence_required: false,
    evidence: ['Synthetic verification record'],
    completion_note: 'Verified',
    completed_at: '2026-09-09T01:00:00Z',
    sort_order: 10,
    team: null,
    assignee: null,
    completed_by: { id: 7, name: 'Synthetic technician' },
    dependencies: [],
    approval: null,
    current_completion_id: null,
    readiness: taskReadiness({
        completion: 'valid',
        can_complete: false,
        can_edit: false,
        can_reopen: true,
    }),
};
const pending: TicketWorkTask = {
    ...completed,
    id: 10,
    title: 'Task C: pending verification',
    status: 'pending',
    evidence: null,
    completion_note: null,
    completed_at: null,
    completed_by: null,
    sort_order: 20,
    readiness: taskReadiness(),
};
function Host({
    actorId = 7,
    ticketId = 42,
    canViewWork = true,
    canManage = true,
}: {
    actorId?: number;
    ticketId?: number;
    canViewWork?: boolean;
    canManage?: boolean;
}) {
    return (
        <>
            <header tabIndex={-1}>Ticket workspace</header>
            <main aria-label="Authorized task workspace" tabIndex={-1}>
                <TicketWorkTasks
                    key={`${ticketId}:${actorId}:${canViewWork}`}
                    actorId={actorId}
                    ticketId={ticketId}
                    version={4}
                    tasks={[completed, pending]}
                    canViewWork={canViewWork}
                    canManage={canManage}
                    taskWork={{
                        storage_ready: true,
                        can_create: true,
                        can_reorder: true,
                    }}
                    assignees={[]}
                    teams={[]}
                    onCommitted={vi.fn()}
                />
            </main>
        </>
    );
}
async function openAction(action: 'Reopen' | 'Complete') {
    const opener = screen.getByRole('button', { name: `${action} task` });
    opener.focus();
    fireEvent.click(opener);
    const dialog = await screen.findByRole('dialog', {
        name: `${action} work task`,
    });
    const input = within(dialog).getByRole('textbox', {
        name: action === 'Reopen' ? /Reason for reopening/ : 'Completion note',
    });
    await waitFor(() => expect(input).toHaveFocus());
    return { opener, dialog, input };
}
async function expectClosedAt(opener: HTMLElement) {
    await waitFor(() =>
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
    );
    // FocusScope dispatches close autofocus on its deferred unmount callback.
    await waitFor(() => expect(opener).toHaveFocus());
    expect(axios.request).not.toHaveBeenCalled();
    expect(axios.post).not.toHaveBeenCalled();
}

describe('Task action dialog focus return', () => {
    beforeEach(() => {
        clearItTicketDraftMemory();
        sessionStorage.clear();
        vi.spyOn(axios, 'request').mockRejectedValue(
            new Error('Unexpected task write'),
        );
        vi.spyOn(axios, 'post').mockRejectedValue(
            new Error('Unexpected task write'),
        );
        vi.spyOn(axios, 'get').mockImplementation(async (_url, config) =>
            taskHistoryResponse({
                nonce: config?.params.review_nonce,
                taskId: 9,
                readiness: completed.readiness,
            }),
        );
    });
    afterEach(() => {
        cleanup();
        clearItTicketDraftMemory();
        sessionStorage.clear();
        vi.restoreAllMocks();
    });

    it('returns blank-reason validation followed by Escape to the exact Reopen opener', async () => {
        render(<Host />);
        const { opener, dialog, input } = await openAction('Reopen');
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Check task history' }),
        );
        fireEvent.click(
            await within(dialog).findByRole('button', {
                name: 'I have reviewed the consequences',
            }),
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Reopen task' }),
        );
        await waitFor(() => expect(input).toHaveFocus());
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(input).toHaveAccessibleDescription(
            'Explain why this task needs to be reopened.',
        );
        fireEvent.keyDown(dialog, { key: 'Escape', code: 'Escape' });
        await expectClosedAt(opener);
    });

    it.each(['Reopen', 'Complete'] as const)(
        'returns %s footer Close to its actual task opener',
        async (action) => {
            render(<Host />);
            const { opener, dialog } = await openAction(action);
            const close = within(dialog)
                .getAllByRole('button', { name: 'Close' })
                .find((button) =>
                    button.closest('[data-slot="dialog-footer"]'),
                );
            if (!close)
                throw new Error('The action dialog footer Close is missing.');
            close.focus();
            fireEvent.click(close);
            await expectClosedAt(opener);
        },
    );

    it('keeps nested cancellation inside the original dialog, then returns explicit discard to its opener', async () => {
        render(<Host />);
        const { opener, dialog, input } = await openAction('Reopen');
        fireEvent.change(input, {
            target: { value: 'Keep this private reopen reason' },
        });
        input.focus();
        fireEvent.keyDown(dialog, { key: 'Escape', code: 'Escape' });
        const keep = await screen.findByRole('alertdialog', {
            name: 'Keep this task work for later?',
        });
        fireEvent.click(within(keep).getByRole('button', { name: 'Cancel' }));
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(dialog).toBeVisible();
        expect(input).toHaveValue('Keep this private reopen reason');
        await waitFor(() =>
            expect(
                dialog.contains(document.activeElement),
                `Settled focus target: ${document.activeElement?.tagName}`,
            ).toBe(true),
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Discard changes' }),
        );
        const discard = await screen.findByRole('alertdialog', {
            name: 'Discard these task changes?',
        });
        fireEvent.click(
            within(discard).getByRole('button', { name: 'Discard changes' }),
        );
        await expectClosedAt(opener);
    });

    it('uses the authorized dialog when a field becomes disabled during its nested confirmation', async () => {
        render(<Host />);
        const { dialog, input } = await openAction('Reopen');
        fireEvent.change(input, {
            target: { value: 'Keep the disabled field proposal' },
        });
        input.focus();
        fireEvent.keyDown(dialog, { key: 'Escape', code: 'Escape' });
        const keep = await screen.findByRole('alertdialog', {
            name: 'Keep this task work for later?',
        });
        // Fieldsets disable their descendants without adding a disabled
        // attribute to each field, as the command lifecycle does.
        input.closest('fieldset')?.setAttribute('disabled', '');
        expect(input).toBeDisabled();
        fireEvent.click(within(keep).getByRole('button', { name: 'Cancel' }));
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        await waitFor(() => expect(dialog).toHaveFocus());
        expect(input).toHaveValue('Keep the disabled field proposal');
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('does not return focus to a removed trigger after private work permission is lost', async () => {
        const { rerender } = render(<Host />);
        const { opener } = await openAction('Reopen');
        rerender(<Host canViewWork={false} canManage={false} />);
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(opener.isConnected).toBe(false);
        await waitFor(() =>
            expect(
                screen.getByRole('main', { name: 'Authorized task workspace' }),
            ).toHaveFocus(),
        );
        expect(screen.queryByText(completed.title)).not.toBeInTheDocument();
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('does not reuse the previous actor and ticket trigger after the host scope changes', async () => {
        const { rerender } = render(<Host />);
        const { opener } = await openAction('Reopen');
        rerender(<Host actorId={88} ticketId={43} />);
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(opener.isConnected).toBe(false);
        await waitFor(() =>
            expect(
                screen.getByRole('main', { name: 'Authorized task workspace' }),
            ).toHaveFocus(),
        );
        expect(
            screen.getByRole('button', { name: 'Reopen task' }),
        ).not.toHaveFocus();
        expect(axios.request).not.toHaveBeenCalled();
    });

    it.each(['permission', 'scope'] as const)(
        'does not restore a nested confirmation into private task content after %s loss',
        async (change) => {
            const { rerender } = render(<Host />);
            const { opener, dialog, input } = await openAction('Reopen');
            fireEvent.change(input, {
                target: { value: 'Private nested confirmation work' },
            });
            input.focus();
            fireEvent.keyDown(dialog, { key: 'Escape', code: 'Escape' });
            await screen.findByRole('alertdialog', {
                name: 'Keep this task work for later?',
            });
            const staleFieldFocus = vi.spyOn(input, 'focus');
            if (change === 'permission')
                rerender(<Host canViewWork={false} canManage={false} />);
            else rerender(<Host actorId={88} ticketId={43} />);
            await waitFor(() =>
                expect(
                    screen.queryByRole('alertdialog'),
                ).not.toBeInTheDocument(),
            );
            await waitFor(() =>
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
            );
            await waitFor(() =>
                expect(
                    screen.getByRole('main', {
                        name: 'Authorized task workspace',
                    }),
                ).toHaveFocus(),
            );
            expect(opener.isConnected).toBe(false);
            expect(input.isConnected).toBe(false);
            expect(staleFieldFocus).not.toHaveBeenCalled();
            expect(
                screen.queryByDisplayValue('Private nested confirmation work'),
            ).not.toBeInTheDocument();
            expect(axios.request).not.toHaveBeenCalled();
        },
    );
});
