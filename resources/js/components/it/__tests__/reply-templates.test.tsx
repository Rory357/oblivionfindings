import { ItReplyTemplates } from '@/components/it/it-reply-templates';
import {
    clearTicketTemplateCache,
    TicketTemplateInsert,
} from '@/components/it/ticket-template-insert';
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
    useForm: (initial: Record<string, unknown>) => {
        const data = { ...initial };
        return {
            data,
            setData: (key: string, value: unknown) => {
                data[key] = value;
            },
            post: inertia.post,
            patch: inertia.patch,
            processing: false,
            errors: {},
        };
    },
}));

const templates = [
    {
        id: 4,
        name: 'Password reset confirmation',
        audience: 'public' as const,
        body: 'Kia ora {{requester.first_name}}, access is restored.',
        owner: { id: 9, name: 'Ari Tech' },
        review_due_at: '2026-12-01',
        is_active: true,
        lock_version: 3,
        version_count: 3,
        updated_at: '2026-09-13T10:00:00+12:00',
    },
    {
        id: 5,
        name: 'Escalation note',
        audience: 'internal' as const,
        body: 'Escalating {{ticket.reference}}.',
        owner: null,
        review_due_at: null,
        is_active: false,
        lock_version: 2,
        version_count: 2,
        updated_at: null,
    },
];

describe('ItReplyTemplates management register', () => {
    it('lists templates with audience, ownership and archive state', () => {
        render(
            <ItReplyTemplates
                templates={templates}
                placeholders={{ 'ticket.reference': 'Ticket reference' }}
            />,
        );

        expect(
            screen.getByText('Password reset confirmation'),
        ).toBeVisible();
        expect(screen.getByText('Public')).toBeVisible();
        expect(screen.getByText('Internal')).toBeVisible();
        expect(screen.getByText('Archived')).toBeVisible();
        expect(
            screen.getByText('Owned by Ari Tech · review 2026-12-01 · v3'),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Restore' }),
        ).toBeVisible();
    });

    it('archives only through the governed confirmation with the current version', () => {
        render(
            <ItReplyTemplates templates={[templates[0]]} placeholders={{}} />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Archive' }));
        expect(screen.getByText('Archive reply template?')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Archive template' }),
        );
        expect(inertia.routerPost).toHaveBeenCalledWith(
            '/it/setup/reply-templates/4/active',
            { active: false, lock_version: 3 },
            { preserveScroll: true },
        );
    });
});

describe('TicketTemplateInsert', () => {
    beforeEach(() => {
        clearTicketTemplateCache();
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: {
                templates: [
                    { id: 4, name: 'Password reset', audience: 'public' },
                    { id: 5, name: 'Escalation', audience: 'internal' },
                ],
            },
        });
    });

    afterEach(() => vi.restoreAllMocks());

    it('offers only public templates on a public reply and inserts the rendered body', async () => {
        const onInsert = vi.fn();
        render(
            <TicketTemplateInsert
                ticketId={7}
                internal={false}
                onInsert={onInsert}
            />,
        );

        const trigger = await screen.findByRole('combobox', {
            name: 'Insert template',
        });
        fireEvent.click(trigger);
        expect(
            screen.getByRole('option', { name: 'Password reset' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: /Escalation/ }),
        ).not.toBeInTheDocument();

        vi.mocked(axios.get).mockResolvedValueOnce({
            data: { body: 'Kia ora Sam, access is restored.' },
        });
        fireEvent.click(screen.getByRole('option', { name: 'Password reset' }));
        await waitFor(() =>
            expect(onInsert).toHaveBeenCalledWith(
                'Kia ora Sam, access is restored.',
            ),
        );
        expect(vi.mocked(axios.get)).toHaveBeenCalledWith(
            '/it/tickets/7/reply-templates/4/render',
            expect.anything(),
        );
    });

    it('shows the server explanation when a placeholder cannot resolve', async () => {
        const onInsert = vi.fn();
        render(
            <TicketTemplateInsert ticketId={7} internal onInsert={onInsert} />,
        );

        fireEvent.click(
            await screen.findByRole('combobox', { name: 'Insert template' }),
        );
        vi.mocked(axios.get).mockRejectedValueOnce({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    errors: {
                        template: [
                            'This ticket has no value for assignee.name.',
                        ],
                    },
                },
            },
        });
        vi.spyOn(axios, 'isAxiosError').mockReturnValue(true);
        fireEvent.click(screen.getByRole('option', { name: /Escalation/ }));

        expect(
            await screen.findByText(
                'This ticket has no value for assignee.name.',
            ),
        ).toBeVisible();
        expect(onInsert).not.toHaveBeenCalled();
    });
});
