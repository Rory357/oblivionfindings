import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DraftTools } from './_draft-tools';
import type { HubDraftRecovery } from './_types';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', () => ({ hubRequest: request }));
const context: HubDraftRecovery = {
    actor_id: 41,
    page_id: 4,
    version: 3,
    has_saved_draft: true,
    can_discard: true,
    blocked_reason: null,
    recovery: null,
};
const changed = vi.fn();
const reset = vi.fn();
const close = vi.fn();
function tools(dirty = false, editable = true) {
    return render(
        <DraftTools
            pageId={4}
            version={3}
            actorId={41}
            dirty={dirty}
            editable={editable}
            demo={false}
            starters={[{ key: 'blank', label: 'Blank page', sections: [] }]}
            onReset={reset}
            onChanged={changed}
            onClose={close}
        />,
    );
}
describe('saved draft controls', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        request.mockResolvedValue(context);
    });
    it('keeps starting-layout changes unavailable while a draft is reviewed or scheduled', () => {
        tools(false, false);
        expect(
            screen.getByRole('button', { name: 'Choose a starting layout' }),
        ).toBeDisabled();
        expect(reset).not.toHaveBeenCalled();
    });
    it('checks the current context and requires confirmation before discarding exactly that saved draft', async () => {
        let complete!: () => void;
        request.mockImplementation((url: string, method: string) =>
            method === 'GET'
                ? Promise.resolve(context)
                : new Promise<void>((resolve) => {
                      complete = resolve;
                  }),
        );
        tools();
        const discard = await screen.findByRole('button', {
            name: 'Discard saved draft',
        });
        await waitFor(() => expect(discard).toBeEnabled());
        fireEvent.click(discard);
        expect(request).toHaveBeenCalledTimes(1);
        const dialog = screen.getByRole('dialog', {
            name: 'Discard the saved draft?',
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Discard saved draft' }),
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/manage/4/draft',
            'DELETE',
            { actor_id: 41, version: 3 },
        );
        expect(
            within(dialog).getByRole('button', { name: 'Discarding…' }),
        ).toBeDisabled();
        expect(
            within(dialog).getByRole('button', { name: 'Cancel' }),
        ).toBeDisabled();
        complete();
        await waitFor(() => expect(changed).toHaveBeenCalledOnce());
        expect(request).toHaveBeenCalledTimes(2);
    });
    it.each([
        ['stale page', { ...context, version: 4 }, false],
        ['wrong page', { ...context, page_id: 9 }, false],
        ['changed account', { ...context, actor_id: 42 }, false],
        [
            'review',
            { ...context, can_discard: false, blocked_reason: 'review' },
            false,
        ],
        [
            'schedule',
            { ...context, can_discard: false, blocked_reason: 'schedule' },
            false,
        ],
        ['unsaved edits', context, true],
    ])(
        'does not offer a saved-draft mutation for %s',
        async (_label, state, dirty) => {
            request.mockResolvedValue(state);
            tools(dirty);
            await waitFor(() =>
                expect(
                    screen.queryByText('Checking current draft…'),
                ).not.toBeInTheDocument(),
            );
            expect(
                screen.getByRole('button', { name: 'Discard saved draft' }),
            ).toBeDisabled();
            expect(request).toHaveBeenCalledTimes(1);
        },
    );
    it('recovers only the advertised private snapshot and preserves a failed recovery in the dialog', async () => {
        request.mockResolvedValueOnce({
            ...context,
            has_saved_draft: false,
            can_discard: false,
            blocked_reason: 'no_saved_draft',
            recovery: {
                id: 27,
                title: 'Team story',
                discarded_at: '2026-10-12T08:00:00Z',
                can_restore: true,
            },
        });
        request.mockRejectedValueOnce(
            new Error(
                'Someone changed this draft. Reload it before trying again.',
            ),
        );
        tools();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Recover draft' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Recover draft' }));
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/manage/4/draft/restore',
            'POST',
            { actor_id: 41, version: 3, discard_id: 27 },
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Someone changed this draft',
        );
        expect(changed).not.toHaveBeenCalled();
        expect(close).not.toHaveBeenCalled();
    });
});
