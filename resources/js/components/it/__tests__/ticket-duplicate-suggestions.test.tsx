import { readDuplicateMatches } from '@/hooks/it-ticket-duplicate-contract';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, expect, it, vi } from 'vitest';
import { TicketDuplicateSuggestions } from '../ticket-duplicate-suggestions';

const props = { actorId: 7, title: 'Printer connection failure', siteId: 1 };
const match = {
    id: 42,
    reference: 'IT-000042',
    title: 'Printer connection failure',
    status: 'open',
    reasons: ['same_title'],
    href: '/it/tickets/42',
};
const result = (
    body: unknown,
    matches = [match],
    sourceId: number | null = null,
) => ({
    data: {
        viewer_user_id: 7,
        query_uuid: (body as { query_uuid: string }).query_uuid,
        source_id: sourceId,
        matches,
    },
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

it('checks only on request and offers an authorized new-tab link without submitting a ticket', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) => result(body));
    render(<TicketDuplicateSuggestions {...props} />);
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Check similar tickets' }),
    );
    const link = await screen.findByRole('link', { name: /IT-000042/ });
    expect(link).toHaveAttribute('href', '/it/tickets/42');
    expect(link).toHaveAttribute('target', '_blank');
    expect(screen.getByText('Same title, site and work type')).toBeVisible();
    expect(
        screen.getByRole('button', { name: 'Check similar tickets' }),
    ).toHaveFocus();
    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith(
        '/it/tickets/duplicate-suggestions',
        expect.objectContaining({
            actor_user_id: 7,
            title: props.title,
            site_id: 1,
            work_type: 'incident',
        }),
        expect.anything(),
    );
});

it('never describes a failed or unbound response as no duplicates and supports retry', async () => {
    vi.spyOn(axios, 'post')
        .mockRejectedValueOnce(new Error('offline'))
        .mockImplementationOnce(async (_url, body) => result(body, []));
    render(<TicketDuplicateSuggestions {...props} />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Check similar tickets' }),
    );
    await screen.findByRole('alert');
    expect(
        screen.queryByText(/No similar ticket found/),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry ticket check' }));
    await screen.findByText(/No similar ticket found in this check/);
    expect(
        screen.getByText(/Older or differently worded reports may still exist/),
    ).toBeVisible();
    expect(
        readDuplicateMatches(
            {
                viewer_user_id: 8,
                query_uuid: 'a',
                source_id: null,
                matches: [match],
            },
            7,
            'a',
            null,
        ),
    ).toBeNull();
    expect(
        readDuplicateMatches(
            {
                viewer_user_id: 7,
                query_uuid: 'a',
                source_id: null,
                matches: [{ ...match, href: 'https://example.com' }],
            },
            7,
            'a',
            null,
        ),
    ).toBeNull();
});

it('stops a check and ignores its late response without changing the draft', async () => {
    let resolve!: (value: unknown) => void;
    let body: unknown;
    vi.spyOn(axios, 'post').mockImplementation((_url, value) => {
        body = value;
        return new Promise((done) => {
            resolve = done;
        });
    });
    render(<TicketDuplicateSuggestions {...props} />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Check similar tickets' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Stop checking' }));
    await act(async () => resolve(result(body)));
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(
        screen.getByText('Check stopped. Your ticket draft is unchanged.'),
    ).toBeVisible();
    expect(
        screen.getByRole('button', { name: 'Check similar tickets' }),
    ).toBeEnabled();
});

it('discards results immediately when actor or context changes and conceals denied results', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) => result(body));
    const view = render(<TicketDuplicateSuggestions {...props} />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Check similar tickets' }),
    );
    await screen.findByRole('link');
    view.rerender(
        <TicketDuplicateSuggestions {...props} title="Changed problem" />,
    );
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    post.mockRejectedValueOnce({
        isAxiosError: true,
        response: { status: 403 },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Check similar tickets' }),
    );
    await screen.findByText(
        'Ticket suggestions are no longer available for this account or Site.',
    );
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Retry ticket check' }),
    ).toBeEnabled();
    view.rerender(<TicketDuplicateSuggestions {...props} actorId={8} />);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('reads saved triage context from its canonical endpoint and resets when the version changes', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) => result(body, [match], 41));
    const view = render(
        <TicketDuplicateSuggestions
            {...props}
            sourceId={41}
            sourceVersion={1}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Check similar tickets' }),
    );
    await screen.findByRole('link');
    expect(post).toHaveBeenCalledWith(
        '/it/tickets/41/duplicate-suggestions',
        {
            actor_user_id: 7,
            query_uuid: expect.any(String),
        },
        expect.anything(),
    );
    view.rerender(
        <TicketDuplicateSuggestions
            {...props}
            sourceId={41}
            sourceVersion={2}
        />,
    );
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Check similar tickets' }),
        ).toBeEnabled(),
    );
});

it('can check again after signing in without editing or discarding the existing draft', async () => {
    vi.spyOn(axios, 'post')
        .mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 401 },
        })
        .mockImplementationOnce(async (_url, body) => result(body));
    render(<TicketDuplicateSuggestions {...props} />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Check similar tickets' }),
    );
    const retry = await screen.findByRole('button', {
        name: 'Check after signing in',
    });
    expect(retry).toHaveFocus();
    expect(
        screen.getByRole('link', { name: 'Sign in (opens in a new tab)' }),
    ).toHaveAttribute('target', '_blank');
    fireEvent.click(retry);
    await screen.findByRole('link', { name: /IT-000042/ });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Check similar tickets' }),
    ).toHaveFocus();
});

it('does not steal focus from another field when a pending check finishes', async () => {
    let finish!: (value: unknown) => void;
    let body: unknown;
    vi.spyOn(axios, 'post').mockImplementation((_url, value) => {
        body = value;
        return new Promise((resolve) => {
            finish = resolve;
        });
    });
    render(
        <>
            <TicketDuplicateSuggestions {...props} />
            <input aria-label="Additional details" />
        </>,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Check similar tickets' }),
    );
    screen.getByRole('textbox', { name: 'Additional details' }).focus();
    await act(async () => finish(result(body)));
    expect(
        screen.getByRole('textbox', { name: 'Additional details' }),
    ).toHaveFocus();
});
