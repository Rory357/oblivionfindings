import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AssetActionDialog } from './action-dialog';
import type { Movement, ProfileWorkspace } from './types';

const movement: Movement = {
    id: 8,
    kind: 'loan',
    state: 'pending_receipt',
    origin: 'Kōwhai',
    origin_site_id: 1,
    destination: 'Rimu',
    destination_site_id: 2,
    room: null,
    recipient: 'Mara',
    dispatched_at: '2026-09-26T09:00:00Z',
    received_at: null,
    returned_at: null,
    return_due_on: '2026-10-01',
    reason: 'Loan',
    receipt_note: null,
    kit: [
        { id: 3, name: 'Sling' },
        { id: 4, name: 'Charging cable' },
    ],
    received_kit: [],
    can_receive: true,
};
const workspace = {
    version: 5,
    documents: [],
    retirement_blockers: [],
} as unknown as ProfileWorkspace;
afterEach(() => vi.unstubAllGlobals());

describe('Asset receipt dialog', () => {
    it('requires explicit kit confirmation and reviews the receipt before submitting', async () => {
        const fetcher = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ message: 'Receipt recorded' }), {
                status: 200,
            }),
        );
        vi.stubGlobal('fetch', fetcher);
        render(
            <AssetActionDialog
                action="receive"
                assetId={104}
                assetName="Transfer hoist"
                workspace={workspace}
                sites={[]}
                movement={movement}
                onClose={vi.fn()}
                onSaved={vi.fn()}
            />,
        );
        fireEvent.change(screen.getByRole('textbox', { name: /Reason/ }), {
            target: { value: 'Observed at handover' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Confirm every kit item',
        );
        expect(fetcher).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('checkbox', { name: 'Sling' }));
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Charging cable' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(await screen.findByText('2 of 2')).toBeVisible();
        expect(fetcher).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Record actual receipt' }),
        );
        await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(1));
        const payload = JSON.parse(fetcher.mock.calls[0][1].body);
        expect(payload).toMatchObject({
            action: 'receive',
            movement_id: 8,
            expected_version: 5,
            received_kit: [3, 4],
            outcome: 'acknowledged',
        });
        expect(await screen.findByText('Receipt recorded')).toBeVisible();
    });

    it('keeps an uncertain save locked and retries the exact same submission', async () => {
        const fetcher = vi
            .fn()
            .mockRejectedValueOnce(new Error('Offline'))
            .mockResolvedValueOnce(
                new Response(
                    JSON.stringify({ message: 'Exception recorded' }),
                    { status: 200 },
                ),
            );
        vi.stubGlobal('fetch', fetcher);
        render(
            <AssetActionDialog
                action="exception"
                assetId={104}
                assetName="Transfer hoist"
                workspace={workspace}
                sites={[]}
                onClose={vi.fn()}
                onSaved={vi.fn()}
            />,
        );
        fireEvent.change(screen.getByRole('textbox', { name: /Reason/ }), {
            target: { value: 'Item not received' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Record custody exception' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Retry this submission',
            }),
        );
        await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
        expect(fetcher.mock.calls[0][1].body).toBe(
            fetcher.mock.calls[1][1].body,
        );
        expect(fetcher.mock.calls[0][1].headers['Idempotency-Key']).toBe(
            fetcher.mock.calls[1][1].headers['Idempotency-Key'],
        );
    });
});
