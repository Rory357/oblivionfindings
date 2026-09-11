import { readRelatedWork } from '@/hooks/it-ticket-relationship-contract';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { TicketRelatedWork } from '../ticket-related-work';

vi.mock('@inertiajs/react', () => ({
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    Link: ({ href, children, ...props }: ComponentProps<'a'>) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));
const source = {
    id: 41,
    reference: 'IT-000041',
    title: 'Printer connection',
    status: 'open',
    work_type: 'incident',
    lock_version: 3,
    href: '/it/tickets/41',
};
const target = {
    id: 42,
    reference: 'IT-000042',
    title: 'Related printer investigation',
    status: 'open',
    work_type: 'incident',
    lock_version: 5,
    href: '/it/tickets/42',
};
const work = {
    viewer_user_id: 7,
    source,
    can_change: true,
    links: { data: [], page: 1, has_more: false },
    candidates: { data: [target], page: 1, has_more: false },
};
beforeEach(() => {
    sessionStorage.clear();
    vi.spyOn(axios, 'get').mockResolvedValue({ data: { data: work } });
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    sessionStorage.clear();
});
async function choose() {
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Manage related work' }),
        ).toBeEnabled(),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Manage related work' }),
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: /IT-000042\s*Related printer investigation/,
        }),
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Review relationship',
        }),
    );
}
it('requires a review and shows success only for a bound persisted command', async () => {
    const onChanged = vi.fn();
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) => {
            const input = body as { request_uuid: string };
            return {
                data: {
                    status: 'committed',
                    data: {
                        viewer_user_id: 7,
                        source_id: 41,
                        target_id: 42,
                        request_uuid: input.request_uuid,
                        operation: 'ticket.relationship',
                        action: 'add',
                        relationship: 'related_ticket',
                        replayed: false,
                        changed: true,
                        source_version: 4,
                        target_version: 6,
                    },
                },
            };
        });
    render(
        <TicketRelatedWork
            actorId={7}
            ticketId={41}
            active
            onChanged={onChanged}
        />,
    );
    await choose();
    expect(post).not.toHaveBeenCalled();
    expect(
        screen.getByText(
            'This reference grants no access and changes no problem, change or major-incident membership. Both tickets are checked again when saving.',
        ),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Save relationship' }));
    await screen.findByText('Relationship saved');
    expect(post).toHaveBeenCalledWith(
        '/it/tickets/41/related-work',
        expect.objectContaining({
            source_version: 3,
            target_version: 5,
            action: 'add',
            relationship: 'related_ticket',
        }),
        expect.anything(),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Return to related tickets' }),
    );
    expect(onChanged).toHaveBeenCalledTimes(1);
});
it('conceals both reviewed titles after access is denied and reports no saved outcome', async () => {
    vi.spyOn(axios, 'post').mockRejectedValue({
        isAxiosError: true,
        response: { status: 403 },
    });
    render(
        <TicketRelatedWork
            actorId={7}
            ticketId={41}
            active
            onChanged={vi.fn()}
        />,
    );
    await choose();
    fireEvent.click(screen.getByRole('button', { name: 'Save relationship' }));
    await screen.findByText('Access to these records is no longer available.');
    expect(
        screen.queryByText('Related printer investigation'),
    ).not.toBeInTheDocument();
    expect(screen.queryByText('Relationship saved')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Save relationship' }),
    ).not.toBeInTheDocument();
});
it('blocks invalid rail navigation with an explanation and rejects unsafe or mismatched read payloads', async () => {
    render(
        <TicketRelatedWork
            actorId={7}
            ticketId={41}
            active
            onChanged={vi.fn()}
        />,
    );
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Manage related work' }),
        ).toBeEnabled(),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Manage related work' }),
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: /Review relationship\s*Check both ticket references/,
        }),
    );
    expect(
        screen.getByText(
            'Choose an eligible ticket before reviewing the relationship.',
        ),
    ).toBeVisible();
    expect(
        screen.getByRole('button', {
            name: 'Review relationship',
        }),
    ).toBeDisabled();
    expect(
        readRelatedWork({ data: { ...work, viewer_user_id: 8 } }, 7, 41),
    ).toBeNull();
    expect(
        readRelatedWork(
            {
                data: {
                    ...work,
                    source: { ...source, href: 'https://example.com/private' },
                },
            },
            7,
            41,
        ),
    ).toBeNull();
});

it('reviews the exact existing link before removal and clears removal intent when returning to selection', async () => {
    vi.mocked(axios.get).mockResolvedValue({
        data: {
            data: {
                ...work,
                links: {
                    ...work.links,
                    data: [
                        {
                            id: 91,
                            relationship: 'duplicate_ticket',
                            ticket: target,
                            can_remove: true,
                        },
                    ],
                },
            },
        },
    });
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) => ({
            data: {
                status: 'committed',
                data: {
                    viewer_user_id: 7,
                    source_id: 41,
                    target_id: 42,
                    request_uuid: (body as { request_uuid: string })
                        .request_uuid,
                    operation: 'ticket.relationship',
                    action: 'remove',
                    relationship: 'duplicate_ticket',
                    replayed: false,
                    changed: true,
                    source_version: 4,
                    target_version: 6,
                },
            },
        }));
    render(
        <TicketRelatedWork
            actorId={7}
            ticketId={41}
            active
            onChanged={vi.fn()}
        />,
    );
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'Remove IT-000042 relationship',
        }),
    );
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Remove relationship' }),
    );
    await screen.findByText('Relationship saved');
    expect(post).toHaveBeenCalledWith(
        '/it/tickets/41/related-work',
        expect.objectContaining({
            action: 'remove',
            relationship: 'duplicate_ticket',
            target_ticket_id: 42,
            source_version: 3,
            target_version: 5,
        }),
        expect.anything(),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Return to related tickets' }),
    );
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'Remove IT-000042 relationship',
        }),
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: /Choose relationship\s*Keep both records intact/,
        }),
    );
    expect(
        screen.getByRole('button', { name: 'Review relationship' }),
    ).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Remove relationship' }),
    ).not.toBeInTheDocument();
});
