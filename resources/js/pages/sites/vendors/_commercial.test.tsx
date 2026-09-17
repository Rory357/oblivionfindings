import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AgreementViewer, type Agreement } from './_commercial';

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
}));

const agreement: Agreement = {
    id: 4,
    vendor_id: 2,
    site_id: 1,
    title: 'Synthetic agreement',
    kind: 'support',
    reference: 'AG-4',
    owner_user_id: 7,
    asset_id: null,
    visibility: 'site',
    starts_on: '2026-01-01',
    renews_on: '2027-01-01',
    notice_days: 30,
    amount: '1200.00',
    currency: 'NZD',
    terms: 'Synthetic protected terms',
    evidence: 'Synthetic source reference',
    status: 'active',
    lock_version: 3,
    followup: null,
};
const files = [
    {
        id: 8,
        series_id: 'series',
        version: 2,
        name: 'Synthetic.pdf',
        state: 'ready',
        href: '/vendor-agreements/4/files/8/open',
    },
    {
        id: 7,
        series_id: 'series',
        version: 1,
        name: 'Synthetic.pdf',
        state: 'ready',
        href: '/vendor-agreements/4/files/7/open',
    },
];
const response = (body: unknown, status = 200) => ({
    ok: status < 400,
    status,
    redirected: false,
    headers: { get: () => 'application/json' },
    json: async () => body,
});

describe('restricted agreement workflows', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('offers an explicit original-file fallback and retained history without maintenance actions for a reader', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(response({ files, events: [] })),
        );
        render(
            <AgreementViewer
                agreement={agreement}
                canManage={false}
                close={vi.fn()}
                edit={vi.fn()}
            />,
        );
        await waitFor(() =>
            expect(
                screen.getAllByRole('link', { name: 'Open original' }),
            ).toHaveLength(2),
        );
        expect(
            screen.getByText(/No online preview is available/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Upload file' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Record action' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Edit agreement' }),
        ).toBeNull();
    });

    it('only offers the latest ready version as the replacement target', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(response({ files, events: [] })),
        );
        render(
            <AgreementViewer
                agreement={agreement}
                canManage
                close={vi.fn()}
                edit={vi.fn()}
            />,
        );
        expect(
            await screen.findByRole('option', {
                name: 'Replace Synthetic.pdf · version 2',
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', {
                name: 'Replace Synthetic.pdf · version 1',
            }),
        ).toBeNull();
    });

    it('retains action evidence after an uncertain response and requires explicit discard before closing', async () => {
        const close = vi.fn();
        vi.stubGlobal(
            'fetch',
            vi.fn(async (_url: string, init?: RequestInit) =>
                init?.method === 'POST'
                    ? {
                          ...response({}),
                          redirected: true,
                          headers: { get: () => 'text/html' },
                      }
                    : response({ files: [], events: [] }),
            ),
        );
        render(
            <AgreementViewer
                agreement={agreement}
                canManage
                close={close}
                edit={vi.fn()}
            />,
        );
        await screen.findByText('No files uploaded');
        const evidence = screen.getByLabelText('Evidence for this action');
        fireEvent.change(evidence, {
            target: { value: 'Owner reviewed synthetic renewal evidence' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Record action' }));
        await waitFor(() =>
            expect(screen.getByRole('alert').textContent).toContain(
                'result could not be confirmed',
            ),
        );
        expect(evidence).toHaveValue(
            'Owner reviewed synthetic renewal evidence',
        );
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByText('Close', { selector: 'button' }));
        expect(
            await screen.findByText('Discard unsaved agreement action?'),
        ).toBeInTheDocument();
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
        );
        expect(close).toHaveBeenCalledTimes(1);
    });
});
