import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    SecondPersonConfirmationDialog,
    type SecondPersonConfirmationDetails,
} from './second-person-confirmation-dialog';

const { getMock, postMock, page } = vi.hoisted(() => ({
    getMock: vi.fn(),
    postMock: vi.fn(),
    page: { auth: { user: { id: 7 }, can: { medications: { view: true } } } },
}));

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: page }) }));
vi.mock('axios', async (importOriginal) => {
    const actual = await importOriginal<typeof import('axios')>();
    return {
        ...actual,
        default: {
            ...actual.default,
            get: getMock,
            post: postMock,
            isAxiosError: actual.default.isAxiosError,
        },
    };
});

function details(
    overrides: Partial<SecondPersonConfirmationDetails> = {},
): SecondPersonConfirmationDetails {
    return {
        id: 41,
        followup_id: 102,
        status: 'pending',
        server_now: '2026-10-03T10:00:00Z',
        due_at: '2026-10-03T10:30:00Z',
        person_name: 'Aroha Ngata',
        medication_name: 'Losartan 50mg',
        given_at: '2026-10-03T10:00:00Z',
        recorded_by: 'Priya Shah',
        ...overrides,
    };
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}

const defaults = { open: true, nominationId: 41, onOpenChange: vi.fn() };

async function chooseYes() {
    fireEvent.click(await screen.findByRole('button', { name: 'I was there' }));
    const record = await screen.findByRole('button', { name: 'Record answer' });
    expect(record).toHaveClass('frontline-hit');
    fireEvent.click(record);
}

describe('own-login second-person confirmation', () => {
    beforeEach(() => {
        getMock.mockReset();
        postMock.mockReset();
        page.auth.user.id = 7;
        page.auth.can.medications.view = true;
        getMock.mockResolvedValue({ data: details() });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('fetches private source details and records an explicit irreversible answer', async () => {
        const onAnswered = vi.fn();
        postMock.mockResolvedValue({
            data: { status: 'confirmed', replayed: false },
        });
        render(
            <SecondPersonConfirmationDialog
                {...defaults}
                onAnswered={onAnswered}
            />,
        );
        expect(await screen.findByText('Aroha Ngata')).toBeInTheDocument();
        expect(getMock).toHaveBeenCalledWith(
            '/meds/confirmations/41',
            expect.objectContaining({
                headers: { Accept: 'application/json' },
                signal: expect.any(AbortSignal),
            }),
        );
        expect(screen.queryByLabelText(/PIN/i)).not.toBeInTheDocument();
        await chooseYes();
        await waitFor(() =>
            expect(onAnswered).toHaveBeenCalledWith(
                { status: 'confirmed', replayed: false },
                41,
            ),
        );
        expect(postMock).toHaveBeenCalledWith(
            '/meds/confirmations/41',
            { was_there: true },
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
        expect(
            screen.getByText('Your confirmation is recorded.'),
        ).toBeInTheDocument();
    });

    it('treats a redirected HTML save as uncertain and only retries the same answer', async () => {
        const onAnswered = vi.fn();
        postMock
            .mockResolvedValueOnce({ data: '<html>Sign in</html>' })
            .mockResolvedValueOnce({
                data: { status: 'confirmed', replayed: true },
            });
        render(
            <SecondPersonConfirmationDialog
                {...defaults}
                onAnswered={onAnswered}
            />,
        );
        await chooseYes();
        const retry = await screen.findByRole('button', {
            name: 'Retry same answer',
        });
        expect(onAnswered).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: 'I wasn’t there' }),
        ).not.toBeInTheDocument();
        fireEvent.click(retry);
        await waitFor(() => expect(onAnswered).toHaveBeenCalledTimes(1));
        expect(postMock.mock.calls.map((call) => call[1])).toEqual([
            { was_there: true },
            { was_there: true },
        ]);
        expect(onAnswered).toHaveBeenCalledWith(
            { status: 'confirmed', replayed: true },
            41,
        );
    });

    it('aborts a closed request and never displays its late details in a new target', async () => {
        const first = deferred<{ data: SecondPersonConfirmationDetails }>();
        const next = deferred<{ data: SecondPersonConfirmationDetails }>();
        getMock
            .mockReturnValueOnce(first.promise)
            .mockReturnValueOnce(next.promise);
        const view = render(<SecondPersonConfirmationDialog {...defaults} />);
        const signal = getMock.mock.calls[0][1].signal as AbortSignal;
        view.rerender(
            <SecondPersonConfirmationDialog {...defaults} open={false} />,
        );
        expect(signal.aborted).toBe(true);
        view.rerender(
            <SecondPersonConfirmationDialog {...defaults} nominationId={42} />,
        );
        await act(async () => {
            first.resolve({ data: details() });
        });
        expect(screen.queryByText('Aroha Ngata')).not.toBeInTheDocument();
        await act(async () => {
            next.resolve({
                data: details({ id: 42, person_name: 'Mere Kahu' }),
            });
        });
        expect(await screen.findByText('Mere Kahu')).toBeInTheDocument();
    });

    it('clears prior private data when the login changes and ignores the old save acknowledgement', async () => {
        const pending = deferred<{
            data: { status: string; replayed: boolean };
        }>();
        const next = deferred<{ data: SecondPersonConfirmationDetails }>();
        const onAnswered = vi.fn();
        postMock.mockReturnValueOnce(pending.promise);
        const view = render(
            <SecondPersonConfirmationDialog
                {...defaults}
                onAnswered={onAnswered}
            />,
        );
        await chooseYes();
        const signal = postMock.mock.calls[0][2].signal as AbortSignal;
        getMock.mockReturnValueOnce(next.promise);
        page.auth.user.id = 8;
        view.rerender(
            <SecondPersonConfirmationDialog
                {...defaults}
                onAnswered={onAnswered}
            />,
        );
        expect(signal.aborted).toBe(true);
        expect(screen.queryByText('Aroha Ngata')).not.toBeInTheDocument();
        await act(async () => {
            pending.resolve({ data: { status: 'confirmed', replayed: false } });
        });
        expect(onAnswered).not.toHaveBeenCalled();
        expect(
            screen.queryByText('Your confirmation is recorded.'),
        ).not.toBeInTheDocument();
    });

    it('clears private details immediately when medication read permission is lost', async () => {
        const view = render(<SecondPersonConfirmationDialog {...defaults} />);
        expect(await screen.findByText('Aroha Ngata')).toBeInTheDocument();
        page.auth.can.medications.view = false;
        view.rerender(<SecondPersonConfirmationDialog {...defaults} />);
        expect(screen.queryByText('Aroha Ngata')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'I was there' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'This confirmation is no longer available to you.',
            ),
        ).toBeInTheDocument();
    });

    it('clears clinical details after the server denies a save', async () => {
        const onAnswered = vi.fn();
        postMock.mockRejectedValue({
            isAxiosError: true,
            response: { status: 403 },
        });
        render(
            <SecondPersonConfirmationDialog
                {...defaults}
                onAnswered={onAnswered}
            />,
        );
        await chooseYes();
        expect(
            await screen.findByText(
                'This confirmation is no longer available to you.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText('Aroha Ngata')).not.toBeInTheDocument();
        expect(onAnswered).not.toHaveBeenCalled();
    });

    it('uses the server deadline and offers no answer at the exact expiry', async () => {
        getMock.mockResolvedValue({
            data: details({ due_at: '2026-10-03T10:00:00Z' }),
        });
        render(<SecondPersonConfirmationDialog {...defaults} />);
        expect(
            await screen.findByText(
                'The 30-minute window has ended. A house lead will need to check this dose.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'I was there' }),
        ).not.toBeInTheDocument();
        expect(postMock).not.toHaveBeenCalled();
    });

    it('reports a late server reply as expired without a false confirmation', async () => {
        const onAnswered = vi.fn();
        postMock.mockResolvedValue({
            data: { status: 'expired', replayed: false },
        });
        render(
            <SecondPersonConfirmationDialog
                {...defaults}
                onAnswered={onAnswered}
            />,
        );
        await chooseYes();
        expect(
            await screen.findByText(
                'The 30-minute window has ended. A house lead will need to check this dose.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Your confirmation is recorded.'),
        ).not.toBeInTheDocument();
        expect(onAnswered).toHaveBeenCalledWith(
            { status: 'expired', replayed: false },
            41,
        );
    });

    it('rejects details for another nomination before rendering person data', async () => {
        getMock.mockResolvedValue({ data: details({ id: 42 }) });
        render(<SecondPersonConfirmationDialog {...defaults} />);
        expect(
            await screen.findByText(
                'The confirmation could not load. Try again.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText('Aroha Ngata')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'I was there' }),
        ).not.toBeInTheDocument();
    });
});
