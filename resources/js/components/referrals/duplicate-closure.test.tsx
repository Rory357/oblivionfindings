import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    DuplicateClosureHistoryPanel,
    DuplicateClosureReview,
    type DuplicateReview,
} from './duplicate-closure';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('axios', () => ({ default: { get, isAxiosError: () => true } }));
vi.mock('@inertiajs/react', () => ({ Link: 'a' }));
const original = {
    reference: '01M56FK502AJ1TSMS9Y7C0M859',
    version: 2,
    name: 'Fictional original',
    stage: 'assessment',
    owner_name: 'Named owner',
};
function Review() {
    const [value, setValue] = useState<DuplicateReview>({
        original: null,
        basis: '',
        confirmed: false,
    });
    return (
        <DuplicateClosureReview
            source="source"
            value={value}
            onChange={setValue}
        />
    );
}
beforeEach(() => vi.clearAllMocks());

it('clears confirmation when the explanation or original changes', async () => {
    get.mockResolvedValue({ data: { original } });
    render(<Review />);
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Original referral reference' }),
        {
            target: { value: original.reference.toLowerCase() },
        },
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Review original referral' }),
    );
    await screen.findByText(original.name);
    expect(get).toHaveBeenCalledWith(
        expect.stringContaining('/source/duplicate-preview'),
        expect.objectContaining({
            params: { original_reference: original.reference },
        }),
    );
    const confirm = screen.getByRole('checkbox');
    fireEvent.click(confirm);
    expect(confirm).toBeChecked();
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Why this is the same enquiry' }),
        {
            target: { value: 'The reviewed enquiry and person match.' },
        },
    );
    expect(confirm).not.toBeChecked();
    fireEvent.click(confirm);
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Original referral reference' }),
        {
            target: { value: '01M56FK502AJ1TSMS9Y7C0M858' },
        },
    );
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByText(original.name)).not.toBeInTheDocument();
});

it('ignores an old lookup after a different original is entered', async () => {
    let finish!: (value: unknown) => void;
    get.mockImplementation(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    render(<Review />);
    const input = screen.getByRole('textbox', {
        name: 'Original referral reference',
    });
    fireEvent.change(input, { target: { value: original.reference } });
    fireEvent.click(
        screen.getByRole('button', { name: 'Review original referral' }),
    );
    const signal = get.mock.calls[0][1].signal as AbortSignal;
    fireEvent.change(input, {
        target: { value: '01M56FK502AJ1TSMS9Y7C0M858' },
    });
    finish({ data: { original } });
    await waitFor(() => expect(signal.aborted).toBe(true));
    expect(screen.queryByText(original.name)).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Review original referral' }),
    ).toBeEnabled();
});

it('shows unavailable-original feedback without leaving a confirmable match', async () => {
    get.mockRejectedValue({
        response: { data: { message: 'Original referral is unavailable.' } },
    });
    render(<Review />);
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Original referral reference' }),
        {
            target: { value: original.reference },
        },
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Review original referral' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Original referral is unavailable.',
    );
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});

it('redacts original details when current access is absent, retaining reopened history', () => {
    render(
        <DuplicateClosureHistoryPanel
            history={[
                {
                    closed_at: '2026-10-18T00:00:00Z',
                    reopened_at: '2026-10-18T01:00:00Z',
                    accessible: false,
                    target: original,
                    basis: 'Private explanation must not appear',
                },
            ]}
        />,
    );
    expect(screen.getByText(/Reopened/)).toBeVisible();
    expect(screen.getByText(/require current/)).toBeVisible();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.queryByText(/Private explanation/)).not.toBeInTheDocument();
});
