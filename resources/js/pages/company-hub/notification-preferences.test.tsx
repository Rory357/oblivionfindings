import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HubRequestError } from './_api';
import { HubNotificationDialog } from './_notification-preferences';
import type { HubNotificationState } from './_types';

const { request, notice, actor, before, visit } = vi.hoisted(() => ({
    request: vi.fn(),
    notice: vi.fn(),
    actor: { id: 11 },
    before: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('./_api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./_api')>()),
    hubRequest: request,
}));
vi.mock('sonner', () => ({ toast: { success: notice } }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: actor.id } } } }),
    router: { on: before, visit },
}));

const initial: HubNotificationState = {
    ready: true,
    actor_id: 11,
    version: 3,
    preferences: {
        recognition: true,
        replies: true,
        events: true,
        news: false,
        reviews: false,
    },
};
const newsLabel = 'News from topics and teams I follow';
const next = {
    saved: true,
    ...initial,
    version: 4,
    preferences: { ...initial.preferences, news: true },
};
function deferred() {
    let resolve!: (value: unknown) => void;
    const promise = new Promise((complete) => {
        resolve = complete;
    });
    return { promise, resolve };
}
function setup() {
    const props = {
        open: true,
        close: vi.fn(),
        onInterests: vi.fn(),
        onSaved: vi.fn(),
    };
    return { props, ...render(<HubNotificationDialog {...props} />) };
}
async function ready() {
    await screen.findByRole('switch', { name: newsLabel });
}

describe('Company Hub notification preferences', () => {
    beforeEach(() => {
        request.mockReset();
        notice.mockReset();
        actor.id = 11;
        before.mockReset().mockReturnValue(vi.fn());
        visit.mockReset();
    });
    it('guards an in-app GET, keeps choices on Cancel and leaves only after explicit discard', async () => {
        request.mockResolvedValueOnce(initial);
        const close = vi.fn();
        function PreferencesOwner() {
            const [open, setOpen] = useState(true);
            return (
                <HubNotificationDialog
                    open={open}
                    close={() => {
                        close();
                        setOpen(false);
                    }}
                    onSaved={vi.fn()}
                />
            );
        }
        render(<PreferencesOwner />);
        await ready();
        fireEvent.click(screen.getByRole('switch', { name: newsLabel }));
        const handler = before.mock.calls.find(
            ([name]) => name === 'before',
        )![1];
        const event = {
            preventDefault: vi.fn(),
            detail: {
                visit: {
                    method: 'get',
                    url: '/company-hub?view=news',
                    preserveState: true,
                },
            },
        };
        act(() => handler(event));
        expect(event.preventDefault).toHaveBeenCalledOnce();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.getByRole('switch', { name: newsLabel })).toBeChecked();
        expect(visit).not.toHaveBeenCalled();
        act(() => handler(event));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(visit).toHaveBeenCalledWith(
            event.detail.visit.url,
            event.detail.visit,
        );
        expect(close).toHaveBeenCalledOnce();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });
    it('allows the synchronous onSaved refresh after a verified acknowledgement', async () => {
        request.mockResolvedValueOnce(initial).mockResolvedValueOnce(next);
        const { props } = setup();
        await ready();
        const event = {
            preventDefault: vi.fn(),
            detail: { visit: { method: 'get', url: '/company-hub' } },
        };
        props.onSaved.mockImplementation(() =>
            before.mock.calls.find(([name]) => name === 'before')![1](event),
        );
        fireEvent.click(screen.getByRole('switch', { name: newsLabel }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save preferences' }),
        );
        await waitFor(() => expect(props.close).toHaveBeenCalledOnce());
        expect(event.preventDefault).not.toHaveBeenCalled();
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });
    it('loads current choices in the side panel and keeps mandatory updates outside personal choices', async () => {
        request.mockResolvedValueOnce(initial);
        setup();
        await ready();
        expect(screen.getByRole('dialog')).toHaveAttribute(
            'data-slot',
            'sheet-content',
        );
        expect(
            screen.getByRole('switch', {
                name: 'A shout-out for me or my team',
            }),
        ).toBeChecked();
        expect(
            screen.getByRole('switch', { name: newsLabel }),
        ).not.toBeChecked();
        expect(
            screen.getByText(
                /Required-read announcements still appear in Needs attention/,
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Save preferences' }),
        ).toBeDisabled();
        expect(request).toHaveBeenCalledWith(
            '/company-hub/notifications',
            'GET',
        );
    });
    it('saves once with the current actor/version and retained page-review choice, then confirms the acknowledgement', async () => {
        const saving = deferred();
        request
            .mockResolvedValueOnce(initial)
            .mockReturnValueOnce(saving.promise);
        const { props } = setup();
        await ready();
        fireEvent.click(screen.getByRole('switch', { name: newsLabel }));
        const save = screen.getByRole('button', { name: 'Save preferences' });
        fireEvent.click(save);
        fireEvent.click(save);
        fireEvent.click(screen.getByRole('button', { name: /^Close$/ }));
        expect(request).toHaveBeenCalledTimes(2);
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/notifications',
            'PUT',
            { actor_id: 11, version: 3, ...next.preferences },
        );
        expect(screen.getByRole('switch', { name: newsLabel })).toBeDisabled();
        expect(props.close).not.toHaveBeenCalled();
        expect(props.onSaved).not.toHaveBeenCalled();
        await act(async () => saving.resolve(next));
        expect(props.onSaved).toHaveBeenCalledOnce();
        expect(props.close).toHaveBeenCalledOnce();
        expect(notice).toHaveBeenCalledWith(
            'Your notification preferences are saved.',
        );
    });
    it.each([
        ['another account', { ...next, actor_id: 12 }],
        ['unchanged version', { ...next, version: 3 }],
        ['skipped version', { ...next, version: 5 }],
        ['different choices', { ...next, preferences: initial.preferences }],
        ['missing receipt', { ...next, saved: false }],
        ['unavailable editing', { ...next, ready: false }],
    ])(
        'keeps unsaved choices after %s acknowledgement',
        async (_label, result) => {
            request
                .mockResolvedValueOnce(initial)
                .mockResolvedValueOnce(result);
            const { props } = setup();
            await ready();
            fireEvent.click(screen.getByRole('switch', { name: newsLabel }));
            fireEvent.click(
                screen.getByRole('button', { name: 'Save preferences' }),
            );
            await screen.findByText(
                'The saved choices could not be confirmed. Reload them before making another change.',
            );
            expect(
                screen.getByRole('switch', { name: newsLabel }),
            ).toBeChecked();
            expect(
                screen.getByRole('button', { name: 'Save preferences' }),
            ).toBeDisabled();
            expect(props.onSaved).not.toHaveBeenCalled();
            expect(props.close).not.toHaveBeenCalled();
            expect(notice).not.toHaveBeenCalled();
        },
    );
    it('preserves failed choices and asks before replacing them with a current saved version', async () => {
        request
            .mockResolvedValueOnce(initial)
            .mockRejectedValueOnce(
                new HubRequestError('Your preferences changed.', 409),
            )
            .mockResolvedValueOnce({ ...initial, version: 4 });
        const { props } = setup();
        await ready();
        fireEvent.click(screen.getByRole('switch', { name: newsLabel }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save preferences' }),
        );
        await screen.findByText('Your preferences changed.');
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload saved choices' }),
        );
        expect(request).toHaveBeenCalledTimes(2);
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByRole('switch', { name: newsLabel })).toBeChecked();
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload saved choices' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('switch', { name: newsLabel }),
            ).not.toBeChecked(),
        );
        expect(props.close).not.toHaveBeenCalled();
        expect(notice).not.toHaveBeenCalled();
    });
    it('protects dirty closure and only opens interests after the user chooses to discard', async () => {
        request.mockResolvedValueOnce(initial);
        const { props } = setup();
        await ready();
        fireEvent.click(screen.getByRole('switch', { name: newsLabel }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(props.close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Manage followed topics and teams',
            }),
        );
        expect(props.onInterests).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
        );
        expect(props.onInterests).toHaveBeenCalledOnce();
        expect(request).toHaveBeenCalledTimes(1);
    });
    it.each([
        ['network loss', new TypeError('Connection interrupted.')],
        [
            'temporary server failure',
            new HubRequestError('Please try again.', 500),
        ],
    ])(
        'retries retained choices after %s without forcing a reload',
        async (_label, failure) => {
            request
                .mockResolvedValueOnce(initial)
                .mockRejectedValueOnce(failure)
                .mockResolvedValueOnce(next);
            const { props } = setup();
            await ready();
            fireEvent.click(screen.getByRole('switch', { name: newsLabel }));
            fireEvent.click(
                screen.getByRole('button', { name: 'Save preferences' }),
            );
            await screen.findByText(failure.message);
            expect(
                screen.getByRole('switch', { name: newsLabel }),
            ).toBeChecked();
            expect(
                screen.getByRole('switch', { name: newsLabel }),
            ).toBeEnabled();
            fireEvent.click(
                screen.getByRole('button', { name: 'Save preferences' }),
            );
            await waitFor(() => expect(props.onSaved).toHaveBeenCalledOnce());
            expect(
                request.mock.calls.filter((call) => call[1] === 'GET'),
            ).toHaveLength(1);
            expect(request.mock.calls[1]).toEqual(request.mock.calls[2]);
        },
    );
    it('requires current saved choices if a retry discovers the first request already committed', async () => {
        request
            .mockResolvedValueOnce(initial)
            .mockRejectedValueOnce(new TypeError('Connection interrupted.'))
            .mockRejectedValueOnce(
                new HubRequestError('Your saved preferences changed.', 409),
            );
        const { props } = setup();
        await ready();
        fireEvent.click(screen.getByRole('switch', { name: newsLabel }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save preferences' }),
        );
        await screen.findByText('Connection interrupted.');
        fireEvent.click(
            screen.getByRole('button', { name: 'Save preferences' }),
        );
        await screen.findByText('Your saved preferences changed.');
        expect(screen.getByRole('switch', { name: newsLabel })).toBeChecked();
        expect(
            screen.getByRole('button', { name: 'Save preferences' }),
        ).toBeDisabled();
        expect(props.onSaved).not.toHaveBeenCalled();
        expect(notice).not.toHaveBeenCalled();
    });
    it('ignores an old save after account switching and loads the new account’s own choices', async () => {
        const saving = deferred();
        request
            .mockResolvedValueOnce(initial)
            .mockReturnValueOnce(saving.promise)
            .mockResolvedValueOnce({ ...initial, actor_id: 12 });
        const { props, rerender } = setup();
        await ready();
        fireEvent.click(screen.getByRole('switch', { name: newsLabel }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save preferences' }),
        );
        actor.id = 12;
        rerender(<HubNotificationDialog {...props} />);
        await ready();
        await act(async () => saving.resolve(next));
        expect(
            screen.getByRole('switch', { name: newsLabel }),
        ).not.toBeChecked();
        expect(props.onSaved).not.toHaveBeenCalled();
        expect(props.close).not.toHaveBeenCalled();
        expect(notice).not.toHaveBeenCalled();
    });
    it('ignores an obsolete load after closing and reopening', async () => {
        const old = deferred();
        request.mockReturnValueOnce(old.promise).mockResolvedValueOnce(initial);
        const { props, rerender } = setup();
        rerender(<HubNotificationDialog {...props} open={false} />);
        rerender(<HubNotificationDialog {...props} />);
        await ready();
        await act(async () => old.resolve(next));
        expect(
            screen.getByRole('switch', { name: newsLabel }),
        ).not.toBeChecked();
    });
    it('offers retry without guessed editable choices after a load failure', async () => {
        request
            .mockRejectedValueOnce(new Error('Please sign in again.'))
            .mockResolvedValueOnce(initial);
        setup();
        await screen.findByText('Please sign in again.');
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Save preferences' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload saved choices' }),
        );
        await ready();
        expect(screen.getByRole('switch', { name: newsLabel })).toBeEnabled();
    });
    it('disables editing when the version update is unavailable', async () => {
        request.mockResolvedValueOnce({
            ...initial,
            ready: false,
            version: null,
        });
        setup();
        await ready();
        expect(screen.getByRole('switch', { name: newsLabel })).toBeDisabled();
        expect(
            screen.getByText(/Notification editing is being updated/),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Save preferences' }),
        ).toBeDisabled();
    });
    it('rejects a saved preference read for another account', async () => {
        request.mockResolvedValueOnce({ ...initial, actor_id: 22 });
        setup();
        await screen.findByText(
            'Your saved choices could not be confirmed. Reopen notification preferences.',
        );
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    });
});
