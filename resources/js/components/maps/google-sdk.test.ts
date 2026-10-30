import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type Host = Window & {
    __fleetGoogleReady?: () => void;
    gm_authFailure?: () => void;
    google?: { maps: unknown };
};
describe('optional Google SDK lifecycle', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.useFakeTimers();
        document.head.innerHTML = '';
        const host = window as Host;
        delete host.google;
        delete host.gm_authFailure;
        delete host.__fleetGoogleReady;
    });
    afterEach(() => vi.useRealTimers());
    it('shares one load and reuses the nonce while rejecting key rotation', async () => {
        const nonce = document.createElement('script');
        nonce.nonce = 'fixture-nonce';
        document.head.append(nonce);
        const { loadGoogleMaps } = await import('./google-sdk');
        const first = loadGoogleMaps('fixture-a');
        expect(loadGoogleMaps('fixture-a')).toBe(first);
        const scripts =
            document.querySelectorAll<HTMLScriptElement>('script[src]');
        expect(scripts).toHaveLength(1);
        expect(scripts[0].nonce).toBe('fixture-nonce');
        const sdk = { Map: 'synthetic SDK' };
        (window as Host).google = { maps: sdk };
        (window as Host).__fleetGoogleReady?.();
        await expect(first).resolves.toBe(sdk);
        await expect(loadGoogleMaps('fixture-b')).rejects.toThrow('Reload');
        expect(document.querySelectorAll('script[src]')).toHaveLength(1);
    });
    it('fails closed after authentication rejection without injecting a second SDK', async () => {
        const { loadGoogleMaps, onGoogleFailure } =
            await import('./google-sdk');
        const notify = vi.fn();
        const unsubscribe = onGoogleFailure(notify);
        const pending = loadGoogleMaps('fixture');
        const rejected = expect(pending).rejects.toThrow('unavailable');
        (window as Host).gm_authFailure?.();
        await rejected;
        expect(notify).toHaveBeenCalledTimes(1);
        unsubscribe();
        await expect(loadGoogleMaps('fixture')).rejects.toThrow('Reload');
        expect(document.querySelectorAll('script[src]')).toHaveLength(0);
    });
    it('tells already-mounted maps about late authentication failure', async () => {
        const { loadGoogleMaps, onGoogleFailure } =
            await import('./google-sdk');
        const notify = vi.fn();
        const pending = loadGoogleMaps('fixture');
        (window as Host).google = { maps: {} };
        (window as Host).__fleetGoogleReady?.();
        await pending;
        const unsubscribe = onGoogleFailure(notify);
        (window as Host).gm_authFailure?.();
        expect(notify).toHaveBeenCalledTimes(1);
        unsubscribe();
        await expect(loadGoogleMaps('fixture')).rejects.toThrow('Reload');
    });
});
