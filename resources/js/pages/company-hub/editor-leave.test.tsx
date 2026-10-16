import type { GlobalEvent, PendingVisit } from '@inertiajs/core';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useEditorLeave } from './_editor-leave';

const { navigation } = vi.hoisted(() => ({
    navigation: { on: vi.fn(), visit: vi.fn() },
}));
vi.mock('@inertiajs/react', () => ({ router: navigation }));
const save = vi.fn();
const discardLocal = vi.fn();
const onBlocked = vi.fn();
let before: (event: GlobalEvent<'before'>) => void;
function historyBrowser() {
    const browser = Object.assign(new EventTarget(), {
        currentEntry: { key: 'editor' },
        traverseTo: vi.fn(() => ({ finished: Promise.resolve() })),
    });
    Object.defineProperty(window, 'navigation', {
        configurable: true,
        value: browser,
    });
    const back = () => {
        const event = Object.assign(
            new Event('navigate', { cancelable: true }),
            {
                navigationType: 'traverse',
                destination: { key: 'previous', sameDocument: true },
            },
        );
        act(() => {
            browser.dispatchEvent(event);
        });
        return event;
    };
    return { browser, back };
}
afterEach(() => Reflect.deleteProperty(window, 'navigation'));
function Harness({ dirty = true, saving = false }) {
    const leave = useEditorLeave({
        dirty,
        saving,
        save,
        discardLocal,
        onBlocked,
    });
    return (
        <>
            <span>{leave.pending ? 'Autosave paused' : 'Autosave ready'}</span>
            {leave.dialog}
        </>
    );
}
function navigate(method: 'get' | 'post' = 'get') {
    const event = new CustomEvent('before', {
        cancelable: true,
        detail: {
            visit: {
                method,
                url: new URL('https://oblivionfindings.test/company-hub'),
                data: {},
            } as PendingVisit,
        },
    });
    act(() => before(event));
    return event;
}
describe('editor leaving choices', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        navigation.on.mockImplementation((_name, handler) => {
            before = handler;
            return () => {};
        });
        save.mockResolvedValue(true);
    });
    it('lets an editor stay or leave without persisting local changes', async () => {
        render(<Harness />);
        expect(navigate().defaultPrevented).toBe(true);
        expect(screen.getByText('Autosave paused')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByText('Autosave ready')).toBeVisible();
        expect(navigation.visit).not.toHaveBeenCalled();
        navigate();
        fireEvent.click(
            screen.getByRole('button', { name: 'Leave without changes' }),
        );
        await waitFor(() => expect(navigation.visit).toHaveBeenCalledOnce());
        expect(discardLocal).toHaveBeenCalledOnce();
        expect(save).not.toHaveBeenCalled();
        // The replay of the approved destination bypasses the dirty guard once.
        expect(navigate().defaultPrevented).toBe(false);
        expect(navigate().defaultPrevented).toBe(true);
    });
    it('keeps the dialog and changes after a failed save, then allows a successful save before leaving', async () => {
        save.mockResolvedValueOnce(false).mockResolvedValueOnce(true);
        render(<Harness />);
        navigate();
        fireEvent.click(screen.getByRole('button', { name: 'Save and leave' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'have not been saved',
        );
        expect(navigation.visit).not.toHaveBeenCalled();
        expect(discardLocal).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Save and leave' }));
        await waitFor(() => expect(navigation.visit).toHaveBeenCalledOnce());
        expect(save).toHaveBeenCalledTimes(2);
    });
    it('waits for an in-flight save and does not replay blocked publication or other mutations', () => {
        const view = render(<Harness saving />);
        navigate();
        expect(
            screen.getByRole('button', { name: 'Leave without changes' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Save and leave' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        view.rerender(<Harness dirty={false} saving={false} />);
        expect(navigate().defaultPrevented).toBe(false);
        view.rerender(<Harness />);
        expect(navigate('post').defaultPrevented).toBe(true);
        expect(onBlocked).toHaveBeenCalledOnce();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('protects native Back with the same stay and discard choices as page navigation', () => {
        const { browser, back } = historyBrowser();
        const view = render(<Harness />);
        expect(back().defaultPrevented).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(browser.traverseTo).not.toHaveBeenCalled();
        expect(discardLocal).not.toHaveBeenCalled();
        back();
        fireEvent.click(
            screen.getByRole('button', { name: 'Leave without changes' }),
        );
        expect(discardLocal).toHaveBeenCalledOnce();
        expect(browser.traverseTo).toHaveBeenCalledExactlyOnceWith('previous');
        expect(navigation.visit).not.toHaveBeenCalled();
        view.unmount();
    });
    it('retains the exact Back destination through a failed save and a successful save making the page clean', async () => {
        const { browser, back } = historyBrowser();
        let finish!: (saved: boolean) => void;
        save.mockResolvedValueOnce(false).mockImplementationOnce(
            () =>
                new Promise<boolean>((resolve) => {
                    finish = resolve;
                }),
        );
        const view = render(<Harness />);
        back();
        fireEvent.click(screen.getByRole('button', { name: 'Save and leave' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'have not been saved',
        );
        expect(browser.traverseTo).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Save and leave' }));
        view.rerender(<Harness dirty={false} />);
        expect(navigate().defaultPrevented).toBe(true);
        expect(back().defaultPrevented).toBe(true);
        expect(browser.traverseTo).not.toHaveBeenCalled();
        await act(async () => {
            finish(true);
        });
        expect(browser.traverseTo).toHaveBeenCalledExactlyOnceWith('previous');
        expect(discardLocal).not.toHaveBeenCalled();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(back().defaultPrevented).toBe(false);
        view.unmount();
    });
    it('does not replay history after the editor is replaced while its save finishes', async () => {
        const { browser, back } = historyBrowser();
        let finish!: (saved: boolean) => void;
        save.mockImplementationOnce(
            () =>
                new Promise<boolean>((resolve) => {
                    finish = resolve;
                }),
        );
        const view = render(<Harness />);
        back();
        fireEvent.click(screen.getByRole('button', { name: 'Save and leave' }));
        view.unmount();
        await act(async () => {
            finish(true);
        });
        expect(browser.traverseTo).not.toHaveBeenCalled();
        expect(navigation.visit).not.toHaveBeenCalled();
    });
    it('keeps native Back blocked while a page-link save finishes after the draft becomes clean', async () => {
        const { browser, back } = historyBrowser();
        let finish!: (saved: boolean) => void;
        save.mockImplementationOnce(
            () =>
                new Promise<boolean>((resolve) => {
                    finish = resolve;
                }),
        );
        const view = render(<Harness />);
        navigate();
        fireEvent.click(screen.getByRole('button', { name: 'Save and leave' }));
        view.rerender(<Harness dirty={false} />);
        expect(back().defaultPrevented).toBe(true);
        expect(browser.traverseTo).not.toHaveBeenCalled();
        await act(async () => {
            finish(true);
        });
        expect(navigation.visit).toHaveBeenCalledOnce();
        expect(browser.traverseTo).not.toHaveBeenCalled();
        view.unmount();
    });
});
