import { Button } from '@/components/ui/button';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';
import { ServiceStartPanel } from './service-start-panel';
import type { ReferralDetail } from './types';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('axios', () => ({ default: { get } }));
vi.mock('./service-start-dialog', () => ({
    ServiceStartDialog: ({ preview }: { preview: { version: number } }) => {
        const [saved, setSaved] = useState(false);
        return saved ? (
            <p>Saved decision for version {preview.version}</p>
        ) : (
            <Button onClick={() => setSaved(true)}>Save test decision</Button>
        );
    },
}));

it('preserves the open decision and its success state while refreshed referral data loads', async () => {
    const preview = {
        version: 1,
        person: { id: 2, name: 'Fictional person' },
        workflow: { status: 'completed', url: null },
        proposal: { id: 1 },
        start: null,
    };
    get.mockResolvedValueOnce({ data: preview });
    const referral = {
        reference: 'example',
        stage: 'converted',
        version: 1,
    } as ReferralDetail;
    const view = render(<ServiceStartPanel referral={referral} />);
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'Review and agree start',
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save test decision' }));
    let finish: (value: unknown) => void = () => {};
    get.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    view.rerender(<ServiceStartPanel referral={{ ...referral, version: 2 }} />);
    await screen.findByText('Loading the current service and care records…');
    expect(
        screen.getByText('Saved decision for version 1'),
    ).toBeInTheDocument();
    finish({
        data: {
            ...preview,
            version: 2,
            start: { status: 'scheduled', agreed_date: '2026-10-18' },
        },
    });
    await waitFor(() =>
        expect(
            screen.queryByText('Loading the current service and care records…'),
        ).not.toBeInTheDocument(),
    );
    expect(
        screen.getByText('Saved decision for version 1'),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Save test decision' }),
    ).not.toBeInTheDocument();
});
