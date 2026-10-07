import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { webcrypto } from 'node:crypto';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { NoteActionDialog } from './note-action-dialog';
import { type ShiftNote } from './shared';
import { noteValuesHash } from './use-note-command';
const { patch } = vi.hoisted(() => ({ patch: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { patch } }));
const note: ShiftNote = {
    id: 9,
    type: 'shift_note',
    body: '  Legacy text with outer spaces  ',
    is_flagged: false,
    flagged_reason: null,
    is_private: true,
    subject: null,
    created_at: '2026-10-05T02:00:00Z',
    reviewed_at: null,
    reviewer: null,
    edited_at: null,
    editor: null,
    user: { id: 8, name: 'Rangi' },
    client: { id: 4, first_name: 'Aroha', last_name: 'Example', site_id: 2 },
    site: null,
    shift: {
        id: 11,
        starts_at: '2026-10-04T18:00:00Z',
        ends_at: null,
        shift_type: null,
        label: 'Support',
    },
    can_edit: false,
    can_flag: true,
    can_review: true,
    lock: { locked: true, reason: 'not_owner', age_days: 1, days_left: 6 },
};
const props = {
    note,
    actorId: 7,
    allowed: true,
    timeZone: 'Pacific/Auckland',
    weekStart: '2026-10-05',
    onClose: vi.fn(),
};
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('crypto', { subtle: webcrypto.subtle });
});
afterEach(() => vi.unstubAllGlobals());
it('matches a flag receipt against unchanged stored text, privacy and the actual default reason', async () => {
    render(<NoteActionDialog {...props} action="flag" />);
    fireEvent.click(screen.getByRole('button', { name: 'Flag note' }));
    await waitFor(() => expect(patch).toHaveBeenCalledOnce());
    expect(patch.mock.calls[0].slice(0, 2)).toEqual([
        '/operations/shift-notes/9/flag',
        {},
    ]);
    const values_hash = await noteValuesHash({
        type: note.type,
        body: note.body,
        is_private: true,
        is_flagged: true,
        flagged_reason: 'Flagged for review',
    });
    act(() => {
        patch.mock.calls[0][2].onSuccess({
            props: {
                flash: {
                    shift_note_result: {
                        action: 'flag',
                        actor_id: 7,
                        note_id: 9,
                        client_id: 4,
                        shift_id: 11,
                        changed: true,
                        values_hash,
                        is_flagged: true,
                        is_private: true,
                        edited_at: null,
                        edited_by: null,
                        reviewed_at: null,
                        reviewed_by: null,
                    },
                },
            },
        });
        patch.mock.calls[0][2].onFinish();
    });
    expect(screen.getByRole('heading', { name: 'Note flagged' })).toBeVisible();
});
it('describes an existing concurrent review without claiming this actor performed it', async () => {
    render(<NoteActionDialog {...props} action="review" />);
    fireEvent.click(screen.getByRole('button', { name: 'Mark reviewed' }));
    await waitFor(() => expect(patch).toHaveBeenCalledOnce());
    const values_hash = await noteValuesHash({
        type: note.type,
        body: note.body,
        is_private: true,
        is_flagged: false,
        flagged_reason: null,
    });
    act(() => {
        patch.mock.calls[0][2].onSuccess({
            props: {
                flash: {
                    shift_note_result: {
                        action: 'review',
                        actor_id: 7,
                        note_id: 9,
                        client_id: 4,
                        shift_id: 11,
                        changed: false,
                        values_hash,
                        is_flagged: false,
                        is_private: true,
                        edited_at: null,
                        edited_by: null,
                        reviewed_at: '2026-10-07T01:00:00Z',
                        reviewed_by: 12,
                    },
                },
            },
        });
        patch.mock.calls[0][2].onFinish();
    });
    expect(
        screen.getByRole('heading', { name: 'Review already recorded' }),
    ).toBeVisible();
});
it('does not submit when permission is lost after the confirmation opens', () => {
    const { rerender } = render(<NoteActionDialog {...props} action="flag" />);
    rerender(<NoteActionDialog {...props} action="flag" allowed={false} />);
    expect(
        screen.getByRole('heading', { name: 'Action unavailable' }),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Close message' }));
    expect(patch).not.toHaveBeenCalled();
    expect(props.onClose).toHaveBeenCalledOnce();
});
