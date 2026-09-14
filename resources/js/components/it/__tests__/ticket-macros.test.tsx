import {
    ItTicketMacros,
    summariseMacroAction,
    type MacroRow,
} from '@/components/it/it-ticket-macros';
import { TicketMacros } from '@/components/it/ticket-macros';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    routerPost: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: { post: inertia.routerPost },
    useForm: (initial: Record<string, unknown>) => ({
        data: { ...initial },
        setData: vi.fn(),
        post: inertia.post,
        patch: inertia.patch,
        processing: false,
        errors: {},
    }),
}));

const lookups = {
    agents: [{ id: 9, name: 'Ari Tech' }],
    queues: [{ id: 3, name: 'Service desk' }],
    templates: [{ id: 4, name: 'Working on it' }],
};

describe('summariseMacroAction', () => {
    it('names each action with its resolved target', () => {
        expect(
            summariseMacroAction({ type: 'assign_user', user_id: 9 }, lookups),
        ).toBe('Assign to Ari Tech');
        expect(
            summariseMacroAction({ type: 'set_queue', queue_id: 3 }, lookups),
        ).toBe('Move to Service desk');
        expect(
            summariseMacroAction(
                { type: 'add_reply', template_id: 4 },
                lookups,
            ),
        ).toBe('Reply with "Working on it"');
    });
});

describe('ItTicketMacros management register', () => {
    const macro: MacroRow = {
        id: 7,
        name: 'Pick up and escalate',
        description: 'Standard blocked-staff escalation.',
        actions: [
            { type: 'assign_to_me' },
            { type: 'set_priority', priority: 'high', reason: 'Escalation.' },
        ],
        is_active: true,
        lock_version: 2,
    };

    const registerProps = {
        total: 1,
        layout: 'cards' as const,
        creating: false,
        onCreatingChange: vi.fn(),
        agents: lookups.agents,
        queues: lookups.queues,
        templates: lookups.templates,
    };

    it('lists macros on the shared register with ordered action summaries and governed archive', () => {
        render(<ItTicketMacros {...registerProps} macros={[macro]} />);

        expect(screen.getByText('1 of 1 shown')).toBeVisible();
        expect(screen.getByText('Pick up and escalate')).toBeVisible();
        expect(
            screen.getByText('Assign to the applying technician'),
        ).toBeVisible();
        expect(screen.getByText('Override priority to high')).toBeVisible();
        expect(screen.getByText('2 steps')).toBeVisible();

        fireEvent.contextMenu(screen.getByText('Pick up and escalate'));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Archive' }));
        fireEvent.click(screen.getByRole('button', { name: 'Archive macro' }));
        expect(inertia.routerPost).toHaveBeenCalledWith(
            '/it/setup/macros/7/active',
            { active: false, lock_version: 2 },
            { preserveScroll: true },
        );
    });
});

describe('ItTicketMacros table layout', () => {
    it('renders the same rows and actions as a table', () => {
        render(
            <ItTicketMacros
                total={1}
                layout="table"
                creating={false}
                onCreatingChange={vi.fn()}
                agents={lookups.agents}
                queues={lookups.queues}
                templates={lookups.templates}
                macros={[
                    {
                        id: 7,
                        name: 'Pick up and escalate',
                        description: null,
                        actions: [{ type: 'assign_to_me' }],
                        is_active: false,
                        lock_version: 1,
                    },
                ]}
            />,
        );

        expect(screen.getByRole('table')).toBeVisible();
        expect(screen.getByText('Archived')).toBeVisible();
        fireEvent.contextMenu(screen.getByText('Pick up and escalate'));
        expect(screen.getByRole('menuitem', { name: 'Restore' })).toBeVisible();
    });
});

describe('TicketMacros apply control', () => {
    beforeEach(() => {
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: {
                ticket_version: 5,
                macros: [
                    {
                        id: 7,
                        name: 'Pick up and escalate',
                        description: null,
                        changes: ['Assign the ticket to you (Ari Tech).'],
                        blockers: [],
                    },
                    {
                        id: 8,
                        name: 'Broken macro',
                        description: null,
                        changes: [],
                        blockers: [
                            'A configured reply template is archived or missing.',
                        ],
                    },
                ],
            },
        });
    });

    afterEach(() => vi.restoreAllMocks());

    it('previews every change and applies with the fetched ticket version', async () => {
        render(<TicketMacros ticketId={12} />);

        fireEvent.pointerEnter(screen.getByLabelText('Ticket macros'));
        const trigger = await screen.findByRole('combobox', {
            name: 'Apply macro',
        });
        await waitFor(() => expect(trigger).not.toBeDisabled());
        fireEvent.click(trigger);
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Pick up and escalate',
            }),
        );

        expect(
            await screen.findByText('Assign the ticket to you (Ari Tech).'),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Apply macro' }));
        expect(inertia.routerPost).toHaveBeenCalledWith(
            '/it/tickets/12/macros/7/apply',
            expect.objectContaining({ expected_version: 5 }),
            expect.anything(),
        );
    });

    it('never lets a blocked macro apply', async () => {
        render(<TicketMacros ticketId={12} />);

        fireEvent.pointerEnter(screen.getByLabelText('Ticket macros'));
        const trigger = await screen.findByRole('combobox', {
            name: 'Apply macro',
        });
        await waitFor(() => expect(trigger).not.toBeDisabled());
        fireEvent.click(trigger);
        fireEvent.click(
            await screen.findByRole('option', { name: 'Broken macro' }),
        );

        expect(
            await screen.findByText(
                'A configured reply template is archived or missing.',
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Apply macro' }),
        ).toBeDisabled();
    });
});
