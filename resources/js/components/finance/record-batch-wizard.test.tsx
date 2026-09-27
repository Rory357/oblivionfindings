import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { RecordBatchWizard, type BatchRecord } from './record-batch-wizard';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
const records: BatchRecord[] = [1, 2, 3].map((id) => ({
    id,
    label: `Request ${id}`,
    href: `/records/${id}`,
    url: `/commands/${id}`,
    body: { expected_version: 1 },
    detail: `Evidence ${id}`,
}));
const response = (status: number, body: unknown) =>
    new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });

it('retains successful, stale and uncertain outcomes and retries only the original uncertain command', async () => {
    const fetch = vi
        .fn()
        .mockResolvedValueOnce(response(200, { saved: true }))
        .mockResolvedValueOnce(
            response(409, { message: 'Evidence changed. Review again.' }),
        )
        .mockResolvedValueOnce(response(200, { incomplete: true }))
        .mockResolvedValueOnce(response(200, { saved: true }));
    vi.stubGlobal('fetch', fetch);
    render(
        <RecordBatchWizard
            title="Resolve requests"
            description="Independent results"
            records={records}
            action="Resolve all"
            requireNote
            onClose={() => {}}
        />,
    );
    expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    for (const record of records)
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: `I reviewed ${record.label}`,
            }),
        );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText('Decision note'), {
        target: { value: 'Evidence reviewed' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Resolve all' }));
    await screen.findByText('Result unconfirmed');
    expect(
        screen.getByText('Evidence changed. Review again.'),
    ).toBeInTheDocument();
    expect(screen.getAllByText('Saved')).toHaveLength(1);
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Retry this record' }),
        ).toBeEnabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Retry this record' }));
    await waitFor(() => expect(screen.getAllByText('Saved')).toHaveLength(2));
    expect(fetch).toHaveBeenCalledTimes(4);
    expect(fetch.mock.calls[3]).toEqual(fetch.mock.calls[2]);
    expect(fetch.mock.calls.map((call) => call[0])).toEqual([
        '/commands/1',
        '/commands/2',
        '/commands/3',
        '/commands/3',
    ]);
});

it('requires individual review and a decision note before submitting', async () => {
    const fetch = vi.fn();
    vi.stubGlobal('fetch', fetch);
    render(
        <RecordBatchWizard
            title="Resolve requests"
            description="Independent results"
            records={records.slice(0, 1)}
            action="Resolve all"
            requireNote
            onClose={() => {}}
        />,
    );
    fireEvent.click(
        screen.getByRole('checkbox', { name: 'I reviewed Request 1' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Resolve all' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Add a decision note');
    expect(fetch).not.toHaveBeenCalled();
});
