import { act, render, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ChatNotificationLifecycle } from './chat-notification-lifecycle';

const postMessage = vi.fn();
const getRegistration = vi.fn();
let worker: EventTarget;

beforeEach(() => {
    vi.resetAllMocks();
    worker = Object.assign(new EventTarget(), { getRegistration });
    getRegistration.mockResolvedValue({ active: { postMessage } });
    vi.stubGlobal(
        'navigator',
        Object.assign(Object.create(navigator), { serviceWorker: worker }),
    );
});
afterEach(() => vi.unstubAllGlobals());

it('asks the worker to recheck on account changes, focus, connection and worker activation', async () => {
    const { rerender } = render(<ChatNotificationLifecycle actorId={11} />);
    await waitFor(() => expect(postMessage).toHaveBeenCalledTimes(1));
    rerender(<ChatNotificationLifecycle actorId={22} />);
    await waitFor(() => expect(postMessage).toHaveBeenCalledTimes(2));
    await act(async () => {
        window.dispatchEvent(new Event('focus'));
    });
    await act(async () => {
        window.dispatchEvent(new Event('online'));
    });
    await act(async () => {
        worker.dispatchEvent(new Event('controllerchange'));
    });
    expect(postMessage).toHaveBeenCalledTimes(5);
    expect(
        postMessage.mock.calls.every(
            ([message]) =>
                JSON.stringify(message) ===
                JSON.stringify({ type: 'chat-account-refresh' }),
        ),
    ).toBe(true);
    rerender(<ChatNotificationLifecycle actorId={null} />);
    await waitFor(() => expect(postMessage).toHaveBeenCalledTimes(6));
});

it('removes listeners on unmount and ignores a late registration lookup', async () => {
    let resolve!: (value: unknown) => void;
    getRegistration.mockReturnValueOnce(
        new Promise((done) => {
            resolve = done;
        }),
    );
    const { unmount } = render(<ChatNotificationLifecycle actorId={11} />);
    unmount();
    await act(async () => {
        resolve({ active: { postMessage } });
        window.dispatchEvent(new Event('focus'));
        window.dispatchEvent(new Event('online'));
        worker.dispatchEvent(new Event('controllerchange'));
    });
    expect(postMessage).not.toHaveBeenCalled();
    expect(getRegistration).toHaveBeenCalledOnce();
});

it('does not register a worker or interrupt the page when one is unavailable', async () => {
    getRegistration.mockRejectedValueOnce(new Error('Unavailable'));
    const { container } = render(<ChatNotificationLifecycle actorId={11} />);
    await act(async () => {});
    expect(container).toBeEmptyDOMElement();
    expect(postMessage).not.toHaveBeenCalled();
});
