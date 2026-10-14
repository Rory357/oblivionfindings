import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    draftHistoryNavigation,
    registerDraftHistoryGuard,
    type DraftHistoryNavigation,
} from './draft-history';
class FakeNavigation extends EventTarget {
    currentEntry = { key: 'current' };
    traverseTo = vi.fn<DraftHistoryNavigation['traverseTo']>(() => ({
        finished: Promise.resolve(),
    }));
    send(
        key = 'previous',
        options: {
            type?: string;
            sameDocument?: boolean;
            cancelable?: boolean;
        } = {},
    ) {
        const event = Object.assign(
            new Event('navigate', { cancelable: options.cancelable ?? true }),
            {
                navigationType: options.type ?? 'traverse',
                destination: {
                    key,
                    sameDocument: options.sameDocument ?? true,
                },
            },
        );
        this.dispatchEvent(event);
        return event;
    }
}
let navigation: FakeNavigation;
const releases: Array<() => void> = [];
const register = (confirm: (run: () => void) => void) => {
    const release = registerDraftHistoryGuard(confirm);
    releases.push(release);
    return release;
};
beforeEach(() => {
    navigation = new FakeNavigation();
    Object.defineProperty(window, 'navigation', {
        configurable: true,
        value: navigation,
    });
});
afterEach(() => {
    releases
        .splice(0)
        .reverse()
        .forEach((release) => release());
    Reflect.deleteProperty(window, 'navigation');
});
describe('Native draft history guard', () => {
    it('cancels Back and Forward before commit and retains the exact destination for review', () => {
        const confirm = vi.fn();
        register(confirm);
        expect(navigation.send('previous').defaultPrevented).toBe(true);
        expect(navigation.send('next').defaultPrevented).toBe(true);
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(navigation.traverseTo).not.toHaveBeenCalled();
        expect(navigation.currentEntry.key).toBe('current');
    });
    it('allows one approved replay to the exact history entry without a second prompt', () => {
        const confirm = vi.fn();
        register(confirm);
        navigation.send('previous');
        navigation.traverseTo.mockImplementation((key) => {
            expect(navigation.send(key).defaultPrevented).toBe(false);
            return { finished: Promise.resolve() };
        });
        confirm.mock.calls[0][0]();
        expect(navigation.traverseTo).toHaveBeenCalledExactlyOnceWith(
            'previous',
        );
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(navigation.send('previous').defaultPrevented).toBe(true);
        expect(confirm).toHaveBeenCalledTimes(2);
    });
    it('preserves fragment traversals because the router may replace the component there too', () => {
        const confirm = vi.fn();
        register(confirm);
        expect(navigation.send('fragment-entry').defaultPrevented).toBe(true);
        expect(confirm).toHaveBeenCalledTimes(1);
    });
    it('leaves push, replace, reload, cross-document and noncancelable events to their owning handlers', () => {
        const confirm = vi.fn();
        register(confirm);
        for (const type of ['push', 'replace', 'reload'])
            expect(navigation.send('target', { type }).defaultPrevented).toBe(
                false,
            );
        expect(
            navigation.send('external', { sameDocument: false })
                .defaultPrevented,
        ).toBe(false);
        expect(
            navigation.send('forced', { cancelable: false }).defaultPrevented,
        ).toBe(false);
        expect(confirm).not.toHaveBeenCalled();
    });
    it('uses the most recent active draft once when several guarded forms exist', () => {
        const first = vi.fn(),
            second = vi.fn();
        register(first);
        const removeSecond = register(second);
        navigation.send('previous');
        expect(second).toHaveBeenCalledTimes(1);
        expect(first).not.toHaveBeenCalled();
        removeSecond();
        navigation.send('next');
        expect(first).toHaveBeenCalledTimes(1);
    });
    it('cannot replay a decision from an unmounted form or replaced actor scope', () => {
        const confirm = vi.fn();
        const remove = register(confirm);
        navigation.send('previous');
        const oldDecision = confirm.mock.calls[0][0];
        remove();
        register(vi.fn());
        oldDecision();
        expect(navigation.traverseTo).not.toHaveBeenCalled();
    });
    it('drops a stale decision if another page has committed while the prompt was open', () => {
        const confirm = vi.fn();
        register(confirm);
        navigation.send('previous');
        navigation.currentEntry = { key: 'replacement-page' };
        confirm.mock.calls[0][0]();
        expect(navigation.traverseTo).not.toHaveBeenCalled();
    });
    it('clears an unconsumed approval after failed or expired traversal', async () => {
        const confirm = vi.fn();
        register(confirm);
        navigation.send('expired');
        navigation.traverseTo.mockReturnValueOnce({
            finished: Promise.reject(new Error('Entry expired')),
        });
        confirm.mock.calls[0][0]();
        await Promise.resolve();
        expect(navigation.send('expired').defaultPrevented).toBe(true);
        expect(confirm).toHaveBeenCalledTimes(2);
    });
    it('clears synchronous failures and releases the listener after the final form closes', () => {
        const confirm = vi.fn();
        const remove = register(confirm);
        navigation.send('previous');
        navigation.traverseTo.mockImplementation(() => {
            throw Error('Invalid entry');
        });
        expect(() => confirm.mock.calls[0][0]()).not.toThrow();
        expect(navigation.send('previous').defaultPrevented).toBe(true);
        remove();
        expect(navigation.send('previous').defaultPrevented).toBe(false);
    });
    it('does not take over a traversal already cancelled by another listener', () => {
        navigation.addEventListener('navigate', (event) =>
            event.preventDefault(),
        );
        const confirm = vi.fn();
        register(confirm);
        navigation.send('previous');
        expect(confirm).not.toHaveBeenCalled();
    });
    it('invalidates a cancelled decision instead of leaving its replay callable', () => {
        const confirm = vi.fn();
        register(confirm);
        navigation.send('previous');
        const [run, cancel] = confirm.mock.calls[0];
        cancel();
        run();
        expect(navigation.traverseTo).not.toHaveBeenCalled();
    });
    it('does not replay an older destination after a newer history request is under review', () => {
        const confirm = vi.fn();
        register(confirm);
        navigation.send('previous');
        const old = confirm.mock.calls[0][0];
        navigation.send('next');
        old();
        expect(navigation.traverseTo).not.toHaveBeenCalled();
        confirm.mock.calls[1][0]();
        expect(navigation.traverseTo).toHaveBeenCalledExactlyOnceWith('next');
    });
    it('does not let completion of an older attempt clear a newer approval to the same entry', async () => {
        let finishFirst!: () => void;
        const first = new Promise<void>((resolve) => {
            finishFirst = resolve;
        });
        let finishSecond!: () => void;
        const second = new Promise<void>((resolve) => {
            finishSecond = resolve;
        });
        const confirm = vi.fn();
        register(confirm);
        navigation.traverseTo
            .mockReturnValueOnce({ finished: first })
            .mockReturnValueOnce({ finished: second });
        navigation.send('previous');
        confirm.mock.calls[0][0]();
        expect(navigation.send('previous').defaultPrevented).toBe(false);
        navigation.send('previous');
        confirm.mock.calls[1][0]();
        finishFirst();
        await Promise.resolve();
        expect(navigation.send('previous').defaultPrevented).toBe(false);
        expect(confirm).toHaveBeenCalledTimes(2);
        finishSecond();
        await Promise.resolve();
    });
    it('detects unsupported implementations without patching History or fabricating protection', () => {
        Reflect.deleteProperty(window, 'navigation');
        expect(draftHistoryNavigation()).toBeNull();
        const release = register(vi.fn());
        expect(release).toBeTypeOf('function');
        Object.defineProperty(window, 'navigation', {
            configurable: true,
            value: { addEventListener: vi.fn() },
        });
        expect(draftHistoryNavigation()).toBeNull();
    });
});
