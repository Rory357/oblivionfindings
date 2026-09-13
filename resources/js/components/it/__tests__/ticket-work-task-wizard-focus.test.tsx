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
import { taskReadiness } from '@/test/it-work-task-fixtures';
import { TicketWorkTasks, type TicketWorkTask } from '../ticket-work-tasks';

const task: TicketWorkTask = {
    id: 9,
    title: 'Synthetic task B',
    description: 'Existing private task description',
    status: 'pending',
    due_at: null,
    is_required: true,
    evidence_required: false,
    evidence: null,
    completion_note: null,
    completed_at: null,
    sort_order: 10,
    team: null,
    assignee: null,
    completed_by: null,
    dependencies: [],
    approval: null,
    current_completion_id: null,
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
                    tasks={[task]}
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
async function openWizard(action: 'Add' | 'Edit', initialFocus = true) {
    const opener = screen.getByRole('button', { name: `${action} task` });
    opener.focus();
    fireEvent.click(opener);
    const dialog = await screen.findByRole('dialog', {
        name: `${action} work task`,
    });
    const input = within(dialog).getByRole('textbox', { name: /Task title/ });
    if (initialFocus) await waitFor(() => expect(input).toHaveFocus());
    return { opener, dialog, input };
}
async function expectClosedAt(opener: HTMLElement) {
    await waitFor(() =>
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
    );
    await waitFor(() => expect(opener).toHaveFocus());
    expect(axios.request).not.toHaveBeenCalled();
    expect(axios.post).not.toHaveBeenCalled();
}
async function keepPrompt(dialog: HTMLElement, input: HTMLElement) {
    input.focus();
    fireEvent.keyDown(dialog, { key: 'Escape', code: 'Escape' });
    return screen.findByRole('alertdialog', {
        name: 'Keep this task work for later?',
    });
}

describe('Task wizard focus return', () => {
    beforeEach(() => {
        clearItTicketDraftMemory();
        sessionStorage.clear();
        vi.spyOn(axios, 'request').mockRejectedValue(
            new Error('Unexpected write'),
        );
        vi.spyOn(axios, 'post').mockRejectedValue(
            new Error('Unexpected write'),
        );
    });
    afterEach(async () => {
        cleanup();
        // Radix schedules close autofocus after unmount; settle that callback
        // before mounting the next independent ticket host.
        await new Promise((resolve) => window.setTimeout(resolve, 0));
        clearItTicketDraftMemory();
        sessionStorage.clear();
        vi.restoreAllMocks();
    });

    it.each(['Add', 'Edit'] as const)(
        'focuses the title after capture and returns untouched %s Escape to its exact opener',
        async (action) => {
            render(<Host />);
            const { opener, dialog } = await openWizard(action);
            fireEvent.keyDown(dialog, { key: 'Escape', code: 'Escape' });
            await expectClosedAt(opener);
        },
    );

    it('keeps blank-title validation focus and returns Escape to Add task', async () => {
        render(<Host />);
        const { opener, dialog, input } = await openWizard('Add');
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Continue' }),
        );
        await waitFor(() => expect(input).toHaveFocus());
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(input).toHaveAccessibleDescription('Enter a task title.');
        fireEvent.keyDown(dialog, { key: 'Escape', code: 'Escape' });
        await expectClosedAt(opener);
    });

    it('restores nested Cancel to the retained title then explicit discard to the Edit opener', async () => {
        render(<Host />);
        const { opener, dialog, input } = await openWizard('Edit');
        fireEvent.change(input, { target: { value: 'Private unsent edit' } });
        const keep = await keepPrompt(dialog, input);
        fireEvent.click(within(keep).getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(input).toHaveFocus());
        expect(input).toHaveValue('Private unsent edit');
        const discardButton = within(dialog).getByRole('button', {
            name: 'Discard changes',
        });
        discardButton.focus();
        fireEvent.click(discardButton);
        const discard = await screen.findByRole('alertdialog', {
            name: 'Discard these task changes?',
        });
        fireEvent.click(
            within(discard).getByRole('button', { name: 'Discard changes' }),
        );
        await expectClosedAt(opener);
    });

    it('focuses explicit recovery when reopening retained work without exposing the private proposal', async () => {
        render(<Host />);
        const { opener, dialog, input } = await openWizard('Edit');
        fireEvent.change(input, {
            target: { value: 'Unsent title requires fresh authorization' },
        });
        const keep = await keepPrompt(dialog, input);
        fireEvent.click(
            within(keep).getByRole('button', { name: 'Keep draft and close' }),
        );
        await expectClosedAt(opener);
        const reopened = await openWizard('Edit', false);
        await waitFor(() =>
            expect(
                within(reopened.dialog).getByRole('button', {
                    name: 'Resume draft 1',
                }),
            ).toHaveFocus(),
        );
        expect(reopened.input).toHaveValue(task.title);
        expect(
            screen.queryByDisplayValue(
                'Unsent title requires fresh authorization',
            ),
        ).not.toBeInTheDocument();
        expect(axios.request).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('uses the still-authorized dialog when a nested confirmation target becomes disabled', async () => {
        render(<Host />);
        const { dialog, input } = await openWizard('Edit');
        fireEvent.change(input, {
            target: { value: 'Retained disabled title' },
        });
        const keep = await keepPrompt(dialog, input);
        input.closest('fieldset')?.setAttribute('disabled', '');
        fireEvent.click(within(keep).getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(dialog).toHaveFocus());
        expect(input).toHaveValue('Retained disabled title');
        expect(axios.request).not.toHaveBeenCalled();
    });

    it.each(['permission', 'scope'] as const)(
        'never restores nested focus into the prior private task after %s loss',
        async (change) => {
            const { rerender } = render(<Host />);
            const { opener, dialog, input } = await openWizard('Edit');
            fireEvent.change(input, {
                target: { value: 'Private stale title' },
            });
            await keepPrompt(dialog, input);
            const oldFocus = vi.spyOn(input, 'focus');
            if (change === 'permission')
                rerender(<Host canViewWork={false} canManage={false} />);
            else rerender(<Host actorId={88} ticketId={43} />);
            await waitFor(() =>
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
            );
            await waitFor(() =>
                expect(
                    screen.queryByRole('alertdialog'),
                ).not.toBeInTheDocument(),
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
            expect(oldFocus).not.toHaveBeenCalled();
            expect(
                screen.queryByDisplayValue('Private stale title'),
            ).not.toBeInTheDocument();
            expect(axios.request).not.toHaveBeenCalled();
            expect(axios.post).not.toHaveBeenCalled();
        },
    );
});
