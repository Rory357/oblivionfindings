import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ShowCredentialDialog, type CredentialRecord } from './_dialogs';

vi.mock('@inertiajs/react', () => ({
    router: { on: vi.fn(() => vi.fn()), reload: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
}));

const credential: CredentialRecord = {
    id: 12,
    site_id: 3,
    site_name: 'Synthetic house',
    label: 'Synthetic access',
    credential_type: 'password',
    requires_reauth: true,
    is_shareable: false,
    has_totp: true,
    lock_version: 1,
    can_reveal: true,
    can_copy: true,
    can_manage: false,
    can_audit: false,
};
const json = (body: unknown, status = 200) => ({
    ok: status >= 200 && status < 300,
    status,
    redirected: false,
    headers: { get: () => 'application/json' },
    json: async () => body,
});
let current = credential;
let statusCode = 200;
let discloseResponse: unknown;
let fetcher: ReturnType<typeof vi.fn>;
let clipboard: ReturnType<typeof vi.fn>;

async function openViewer() {
    await act(async () => {
        render(
            <ShowCredentialDialog
                siteId={3}
                credential={credential}
                isOpen
                canManage={false}
                canReveal
                onClose={vi.fn()}
            />,
        );
    });
}
async function click(name: string) {
    await act(async () => {
        fireEvent.click(screen.getByRole('button', { name }));
    });
}

describe('shared vault disclosure boundaries', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        current = { ...credential };
        statusCode = 200;
        discloseResponse = json({
            value: 'synthetic-disclosure-value',
            intent_id: 8,
            expires_in: 30,
        });
        fetcher = vi.fn(async (url: string, _init?: RequestInit) =>
            url.endsWith('/status')
                ? json(current, statusCode)
                : url.endsWith('/copy-result')
                  ? json({ ok: true })
                  : await discloseResponse,
        );
        clipboard = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('fetch', fetcher);
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: clipboard },
        });
    });
    afterEach(() => {
        cleanup();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('conceals a revealed value after thirty seconds and immediately on a context change', async () => {
        await openViewer();
        await click('Reveal for 30 seconds');
        expect(screen.queryByText('synthetic-disclosure-value') !== null).toBe(
            true,
        );
        await act(async () => {
            vi.advanceTimersByTime(30001);
        });
        expect(screen.queryByText('synthetic-disclosure-value')).toBeNull();
        await click('Reveal for 30 seconds');
        act(() => {
            window.dispatchEvent(new Event('blur'));
        });
        expect(screen.queryByText('synthetic-disclosure-value')).toBeNull();
    });

    it('drops an in-flight reveal if the context changes before the response arrives', async () => {
        let finish!: (result: unknown) => void;
        discloseResponse = new Promise((resolve) => {
            finish = resolve;
        });
        await openViewer();
        act(() => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Reveal for 30 seconds' }),
            );
        });
        act(() => {
            window.dispatchEvent(new Event('blur'));
        });
        await act(async () => {
            finish(json({ value: 'synthetic-disclosure-value' }));
        });
        expect(screen.queryByText('synthetic-disclosure-value')).toBeNull();
    });

    it('conceals immediately when the current-access poll detects revoked permission or an expired session', async () => {
        await openViewer();
        await click('Reveal for 30 seconds');
        current = { ...credential, can_reveal: false, can_copy: false };
        await act(async () => {
            vi.advanceTimersByTime(10001);
        });
        expect(screen.queryByText('synthetic-disclosure-value')).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Reveal for 30 seconds' }),
        ).toBeNull();
        statusCode = 401;
        await act(async () => {
            vi.advanceTimersByTime(10000);
        });
        expect(screen.getByRole('alert').textContent).toContain(
            'Access or session changed',
        );
    });

    it('authorizes copy first and reports clipboard failure without claiming success', async () => {
        clipboard.mockRejectedValue(new Error('Clipboard denied'));
        await openViewer();
        await click('Copy secret');
        const copyCall = fetcher.mock.calls.findIndex(([url]) =>
            url.endsWith('/copy'),
        );
        expect(fetcher.mock.invocationCallOrder[copyCall]).toBeLessThan(
            clipboard.mock.invocationCallOrder[0],
        );
        const resultCall = fetcher.mock.calls.find(([url]) =>
            url.endsWith('/copy-result'),
        );
        expect(JSON.parse(resultCall?.[1].body as string)).toEqual({
            intent_id: 8,
            outcome: 'failed',
        });
        expect(screen.getByRole('alert').textContent).toContain(
            'Clipboard access failed',
        );
        expect(screen.queryByText('Copied to clipboard.')).toBeNull();
    });

    it('reports successful clipboard use truthfully even when recording the browser outcome fails', async () => {
        fetcher.mockImplementation(async (url: string) =>
            url.endsWith('/status')
                ? json(current)
                : url.endsWith('/copy-result')
                  ? json({ message: 'Unavailable' }, 500)
                  : json({ value: 'synthetic-disclosure-value', intent_id: 8 }),
        );
        await openViewer();
        await click('Copy secret');
        expect(clipboard).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('alert').textContent).toContain(
            'Copied, but the browser outcome could not be recorded',
        );
    });

    it('never treats a redirected sign-in page or non-JSON success as a disclosure', async () => {
        discloseResponse = {
            ...json({}),
            redirected: true,
            headers: { get: () => 'text/html' },
        };
        await openViewer();
        await click('Copy secret');
        expect(clipboard).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toContain(
            'result could not be confirmed',
        );
    });

    it('conceals the service one-time code at its actual remaining lifetime', async () => {
        discloseResponse = json({
            code: '481293',
            seconds_remaining: 3,
            period: 30,
        });
        await openViewer();
        await click('Show one-time code');
        expect(screen.queryByText('481293') !== null).toBe(true);
        await act(async () => {
            vi.advanceTimersByTime(3001);
        });
        expect(screen.queryByText('481293')).toBeNull();
    });
});
