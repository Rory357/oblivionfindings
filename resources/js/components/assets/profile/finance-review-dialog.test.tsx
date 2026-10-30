import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { AssetFinanceReviewDialog } from './finance-review-dialog';
import type { ProfileWorkspace } from './types';

afterEach(() => vi.unstubAllGlobals());

it('reviews an asset Finance request before submitting to the canonical queue', async () => {
    const fetcher = vi.fn().mockResolvedValue(
        new Response(
            JSON.stringify({
                id: 5,
                message:
                    'Finance review FRQ-5 submitted. No expenditure has been approved.',
            }),
            { status: 200 },
        ),
    );
    vi.stubGlobal('fetch', fetcher);
    const workspace = {
        documents: [],
        finance_types: [
            {
                value: 'fixed_asset_update',
                label: 'Fixed asset / ownership update',
            },
        ],
        finance_sources: [
            { value: 'vehicle', label: 'AS-104 · Transfer hoist' },
        ],
    } as unknown as ProfileWorkspace;
    render(
        <AssetFinanceReviewDialog
            assetId={104}
            assetName="Transfer hoist"
            workspace={workspace}
            onClose={vi.fn()}
            onSaved={vi.fn().mockResolvedValue(undefined)}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Choose a request type',
    );
    expect(fetcher).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('combobox', { name: 'Request type' }));
    fireEvent.click(
        await screen.findByRole('option', {
            name: 'Fixed asset / ownership update',
        }),
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: 'What Finance needs to review *' }),
        { target: { value: 'Correct the purchase reference' } },
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Estimate (NZD, optional)' }),
        { target: { value: '125.50' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(await screen.findByText('NZD 125.50 · not approved')).toBeVisible();
    expect(fetcher).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Send to Finance' }));
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(1));
    expect(fetcher.mock.calls[0][0]).toBe('/assets/104/finance-review');
    expect(JSON.parse(fetcher.mock.calls[0][1].body)).toMatchObject({
        request_type: 'fixed_asset_update',
        source: 'vehicle',
        amount: '125.50',
        note: 'Correct the purchase reference',
        existing_document_id: null,
    });
    expect(
        await screen.findByText(
            'Finance review FRQ-5 submitted. No expenditure has been approved.',
        ),
    ).toBeVisible();
});
