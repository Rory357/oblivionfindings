import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StocktakeModal, type Count } from './stocktake-modal';

const { apiMock, downloadMock } = vi.hoisted(() => ({
    apiMock: vi.fn(),
    downloadMock: vi.fn(),
}));
vi.mock('./api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./api')>()),
    api: apiMock,
    download: downloadMock,
}));
vi.mock('./qr-reader', () => ({ QRReader: () => <p>Camera reader</p> }));
vi.mock('@/components/wizard/shell', () => ({
    WizardShell: ({
        children,
        footerEnd,
    }: {
        children: ReactNode;
        footerEnd: ReactNode;
    }) => (
        <div role="dialog">
            {children}
            <footer>{footerEnd}</footer>
        </div>
    ),
}));

const original: Count = {
    id: 7,
    title: 'Equipment room count',
    site_id: 1,
    site_room_id: 4,
    version: 1,
    status: 'draft',
    scope: {
        site: 'Aurora House',
        room: 'Equipment room',
        counter: 'Maya Chen',
        rooms: [{ id: 4, name: 'Equipment room' }],
    },
    entries: [
        {
            key: 'asset-104',
            asset_id: 104,
            name: 'Transfer hoist',
            asset_tag: 'AS-104',
            serial_number: null,
            room: 'Equipment room',
            site_room_id: 4,
            expected: true,
            result: 'pending',
            note: '',
            source: null,
            actor: null,
            observed_at: null,
            changed: false,
        },
    ],
    activity: [],
    counted_at: '2026-09-27T01:00:00Z',
    updated_at: '2026-09-27T01:00:00Z',
    completed_at: null,
    review_note: null,
    follow_up_user_id: null,
    follow_up_name: null,
};
const mount = (count = original) =>
    render(
        <StocktakeModal
            initial={count}
            sites={[{ id: 1, name: 'Aurora House' }]}
            staff={[{ id: 2, name: 'Maya Chen' }]}
            canCount
            initialSite=""
            initialRoom=""
            selected={[]}
            onClose={vi.fn()}
        />,
    );

describe('stocktake server recovery and scan workflow', () => {
    beforeEach(() => {
        apiMock.mockReset();
        downloadMock.mockReset();
    });

    it('keeps unfinished-count guidance accurate when the review has no differences yet', () => {
        mount();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review count (1 unchecked)' }),
        );
        expect(screen.getByText(/still need an answer/)).toBeInTheDocument();
        expect(
            screen.queryByText(
                'No unchecked items. Review the count when you are ready.',
            ),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Finish stocktake' }),
        ).toBeDisabled();
    });

    it('keeps an unsuccessful answer pending and retries the same command identity', async () => {
        apiMock.mockRejectedValueOnce(new TypeError('Connection lost'));
        apiMock.mockResolvedValueOnce({
            ...original,
            version: 2,
            entries: [{ ...original.entries[0], result: 'found' }],
        });
        mount();
        fireEvent.click(screen.getByRole('button', { name: /^Found$/ }));
        await screen.findByRole('alert');
        expect(screen.getByText('Not checked')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        await waitFor(() => expect(apiMock).toHaveBeenCalledTimes(2));
        expect(apiMock.mock.calls[1][2]).toEqual(apiMock.mock.calls[0][2]);
        await screen.findByText('0 left to check');
    });

    it('resolves a QR before recording a found answer and never finishes automatically', async () => {
        apiMock.mockResolvedValueOnce({
            state: 'expected',
            entry: original.entries[0],
        });
        apiMock.mockResolvedValueOnce({
            ...original,
            version: 2,
            entries: [
                {
                    ...original.entries[0],
                    result: 'found',
                    source: 'Keyboard / scanner',
                },
            ],
        });
        mount();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Scan asset QR code' }),
            { target: { value: 'stable-token' } },
        );
        fireEvent.click(screen.getByRole('button', { name: /^Scan$/ }));
        await screen.findByText('Transfer hoist marked found.');
        expect(apiMock.mock.calls[0]).toEqual([
            '/stocktakes/7/resolve',
            'POST',
            { payload: 'stable-token' },
        ]);
        expect(apiMock.mock.calls[1][2]).toMatchObject({
            action: 'found',
            key: 'asset-104',
            source: 'Keyboard / scanner',
            version: 1,
        });
        expect(
            apiMock.mock.calls.some((call) => call[2]?.action === 'finish'),
        ).toBe(false);
    });

    it('requires explicit confirmation for a QR outside the checklist', async () => {
        apiMock.mockResolvedValueOnce({
            state: 'extra',
            entry: {
                ...original.entries[0],
                key: 'asset-220',
                asset_id: 220,
                name: 'Portable ramp',
                expected: false,
                room: 'Garage',
            },
        });
        mount();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Scan asset QR code' }),
            { target: { value: 'off-list-token' } },
        );
        fireEvent.click(screen.getByRole('button', { name: /^Scan$/ }));
        await screen.findByText('Portable ramp is outside this checklist');
        expect(apiMock).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'Skip label' }));
        expect(apiMock).toHaveBeenCalledTimes(1);
    });

    it('opens a completed count read-only and handles a revoked export without changing it', async () => {
        downloadMock.mockRejectedValueOnce(
            new Error('This report is no longer available.'),
        );
        mount({
            ...original,
            status: 'completed',
            completed_at: original.updated_at,
            entries: [{ ...original.entries[0], result: 'found' }],
        });
        expect(
            screen.queryByRole('textbox', { name: 'Scan asset QR code' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Export Excel' }));
        await screen.findByText('This report is no longer available.');
        expect(downloadMock).toHaveBeenCalledWith(
            '/fleet-assets/asset-register/stocktakes/7/export/xlsx',
            'stocktake-7.xlsx',
        );
        expect(apiMock).not.toHaveBeenCalled();
    });
});
