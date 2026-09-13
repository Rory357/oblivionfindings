import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ShiftNotesDialog from './shift-notes-dialog';

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    visit: vi.fn(),
    reload: vi.fn(),
    request: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ router: mocks }));
vi.mock('../lib/task-api', async (original) => ({
    ...(await original<object>()),
    taskRequest: mocks.request,
}));
const people = [
    { id: 1, name: 'Mere' },
    { id: 2, name: 'James' },
    { id: 3, name: 'Casey' },
];
beforeEach(() => {
    vi.clearAllMocks();
    mocks.request.mockResolvedValue({
        handover_id: 8,
        expected_version: 5,
        status: 'draft',
    });
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                people,
                handover_id: 8,
                expected_version: 4,
                status: 'draft',
                review_url: '/operations/handovers?handover=8',
                worker_notes: { shared_notes: '', people: [] },
            }),
        }),
    );
});
afterEach(() => vi.unstubAllGlobals());

describe('shift notes wizard', () => {
    it('preserves separate notes across named steps and saves the current version', async () => {
        render(<ShiftNotesDialog open shiftId={5} onOpenChange={vi.fn()} />);
        const mere = await screen.findByLabelText(
            'What should the next worker know about Mere?',
        );
        fireEvent.change(mere, { target: { value: 'Mere note' } });
        expect(
            screen.queryByLabelText(/know about James/),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Next: James' }));
        fireEvent.change(screen.getByLabelText(/know about James/), {
            target: { value: 'James note' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Next: Casey' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Next: Whole site' }),
        );
        fireEvent.change(screen.getByLabelText(/Whole site/), {
            target: { value: 'Shared note' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Next: Review notes' }),
        );
        expect(screen.getByText('Mere note')).toBeVisible();
        expect(screen.getByText('James note')).toBeVisible();
        expect(
            screen.queryByText(
                'Follow-up requested. Check with the outgoing worker.',
            ),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(mocks.request).toHaveBeenCalledWith(
                '/attendance/handover',
                'POST',
                expect.objectContaining({
                    expected_version: 4,
                    worker_notes: expect.objectContaining({
                        shared_notes: 'Shared note',
                        people: expect.arrayContaining([
                            expect.objectContaining({
                                client_id: 1,
                                notes: 'Mere note',
                            }),
                            expect.objectContaining({
                                client_id: 2,
                                notes: 'James note',
                            }),
                        ]),
                    }),
                }),
            ),
        );
    });
    it('guards unsaved changes when closing and retains them when cancelling discard', async () => {
        mocks.request.mockRejectedValue(new Error('Connection lost'));
        const close = vi.fn();
        render(<ShiftNotesDialog open shiftId={5} onOpenChange={close} />);
        fireEvent.change(await screen.findByLabelText(/know about Mere/), {
            target: { value: 'Unsaved note' },
        });
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Close' }).at(-1)!,
        );
        expect(await screen.findByRole('alertdialog')).toBeVisible();
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(screen.getByLabelText(/know about Mere/)).toHaveValue(
            'Unsaved note',
        );
    });
});
