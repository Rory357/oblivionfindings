import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Person, Workspace } from './model';
import { ReportDialog } from './report-dialog';
const person = { id: 'c1', name: 'Maia Thompson' } as Person;
const workspace = {
    filters: { date: '2026-09-27' },
    history: { journeys: [] },
} as unknown as Workspace;
const preview = {
    needsSource: false,
    positions: [],
    samples: [],
    scope: 'Selected Auckland day',
    window: { from: '2026-09-26T12:00:00Z', to: '2026-09-27T10:59:59Z' },
};
const fetchMock = vi.fn();
const jsonResponse = () =>
    new Response(JSON.stringify(preview), {
        headers: { 'Content-Type': 'application/json' },
    });
beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockReset();
    Object.defineProperty(document, 'hidden', {
        configurable: true,
        value: false,
    });
});
afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});
async function open() {
    fetchMock.mockResolvedValueOnce(jsonResponse());
    const onClose = vi.fn();
    const mounted = render(
        <ReportDialog
            workspace={workspace}
            person={person}
            source="1"
            onClose={onClose}
        />,
    );
    await screen.findByText('Selected Auckland day ·');
    fireEvent.change(screen.getByLabelText('Reason for export'), {
        target: { value: 'Authorised care review' },
    });
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Generate report' }),
        ).toBeEnabled(),
    );
    return { ...mounted, onClose };
}
describe('Report lifecycle', () => {
    it('aborts a cancelled export and ignores a late successful response', async () => {
        const mounted = await open();
        let resolve!: (response: Response) => void;
        let signal: AbortSignal | undefined;
        fetchMock.mockImplementationOnce((_url, options) => {
            signal = options.signal;
            return new Promise<Response>((r) => {
                resolve = r;
            });
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Generate report' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel generation' }),
        );
        expect(signal?.aborted).toBe(true);
        expect(mounted.onClose).toHaveBeenCalledOnce();
        mounted.unmount();
        await act(async () =>
            resolve(
                new Response('pdf', {
                    headers: { 'Content-Type': 'application/pdf' },
                }),
            ),
        );
        expect(mounted.onClose).toHaveBeenCalledOnce();
    });
    it('refuses a successful login HTML response and retains the reason for retry', async () => {
        await open();
        fetchMock.mockResolvedValueOnce(
            new Response('<html>Sign in</html>', {
                headers: { 'Content-Type': 'text/html' },
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Generate report' }),
        );
        expect(
            await screen.findByText(/unexpected response/),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Reason for export')).toHaveValue(
            'Authorised care review',
        );
        expect(
            screen.getByRole('button', { name: 'Generate report' }),
        ).toBeEnabled();
    });
    it('times out an export and keeps cancellation and retry available', async () => {
        await open();
        vi.useFakeTimers();
        fetchMock.mockImplementationOnce(
            (_url, options) =>
                new Promise((_resolve, reject) =>
                    options.signal.addEventListener('abort', () =>
                        reject(new DOMException('Aborted', 'AbortError')),
                    ),
                ),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Generate report' }),
        );
        await act(async () => {
            await vi.advanceTimersByTimeAsync(60001);
        });
        expect(screen.getByText(/generation timed out/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Generate report' }),
        ).toBeEnabled();
        expect(screen.getByLabelText('Reason for export')).toHaveValue(
            'Authorised care review',
        );
    });
    it('aborts preview and generation when unmounted', async () => {
        let signal: AbortSignal | undefined;
        fetchMock.mockImplementationOnce((_url, options) => {
            signal = options.signal;
            return new Promise(() => {});
        });
        const mounted = render(
            <ReportDialog
                workspace={workspace}
                person={person}
                source="1"
                onClose={() => {}}
            />,
        );
        mounted.unmount();
        expect(signal?.aborted).toBe(true);
    });
});
