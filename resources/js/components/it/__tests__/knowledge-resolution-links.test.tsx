import { cleanup, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, expect, it, vi } from 'vitest';
import { KnowledgeResolutionLinks } from '../knowledge-resolution-links';
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});
it('opens the exact linked publication and exposes source drafting only when allowed', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue({
        data: {
            actor_user_id: 7,
            ticket_id: 19,
            can_draft: true,
            records: [
                {
                    id: 23,
                    title: 'Verified guide',
                    status: 'published',
                    href: '/it/knowledge/23?revision=8',
                    published_revision: true,
                },
            ],
        },
    });
    render(<KnowledgeResolutionLinks ticketId={19} />);
    expect(
        await screen.findByRole('link', { name: /Verified guide/ }),
    ).toHaveAttribute('href', '/it/knowledge/23?revision=8');
    expect(
        screen.getByRole('link', { name: 'Create guide from resolution' }),
    ).toHaveAttribute('href', '/it/knowledge/from-resolution/19');
});
it('conceals a response from another account and offers a safe retry', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue({
        data: {
            actor_user_id: 8,
            ticket_id: 19,
            can_draft: true,
            records: [{ id: 23, title: 'Foreign source canary' }],
        },
    });
    render(<KnowledgeResolutionLinks ticketId={19} />);
    expect(
        await screen.findByRole('button', { name: 'Retry related knowledge' }),
    ).toBeVisible();
    expect(screen.queryByText('Foreign source canary')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'Create guide from resolution' }),
    ).not.toBeInTheDocument();
});
it('removes prior ticket links as soon as the ticket context changes', async () => {
    vi.spyOn(axios, 'get')
        .mockResolvedValueOnce({
            data: {
                actor_user_id: 7,
                ticket_id: 19,
                can_draft: false,
                records: [
                    {
                        id: 23,
                        title: 'Previous source',
                        status: 'draft',
                        href: '/it/knowledge/23',
                        published_revision: false,
                    },
                ],
            },
        })
        .mockImplementationOnce(() => new Promise(() => {}));
    const { rerender } = render(<KnowledgeResolutionLinks ticketId={19} />);
    await screen.findByRole('link', { name: /Previous source/ });
    rerender(<KnowledgeResolutionLinks ticketId={20} />);
    await waitFor(() =>
        expect(screen.queryByText(/Previous source/)).not.toBeInTheDocument(),
    );
});
