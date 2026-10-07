import { Button } from '@/components/ui/button';
import type { WizardShell } from '@/components/wizard/shell';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { webcrypto } from 'node:crypto';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { NoteWizard } from './note-wizard';
import { type Catalogue, type ShiftNote } from './shared';
import { normalizedNoteValues, noteValuesHash } from './use-note-command';
const { post, put } = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post, put } }));
vi.mock('@/components/wizard/shell', async (importOriginal) => {
    const actual =
        await importOriginal<typeof import('@/components/wizard/shell')>();
    return {
        ...actual,
        WizardShell: (props: ComponentProps<typeof WizardShell>) => (
            <div role="dialog" aria-label={props.title}>
                {props.success || (
                    <>
                        <Button onClick={props.onClose}>Dismiss wizard</Button>
                        <nav>
                            {props.steps.map((step, index) => (
                                <Button
                                    key={step.key}
                                    disabled={step.disabled}
                                    onClick={() => props.onStepClick(index)}
                                >
                                    {step.label}
                                </Button>
                            ))}
                        </nav>
                        {props.children}
                        {props.footerStart}
                        {props.footerEnd}
                    </>
                )}
            </div>
        ),
    };
});
const catalogue: Catalogue = {
    clients: [{ id: 4, first_name: 'Aroha', last_name: 'Example', site_id: 2 }],
    staff: [],
    sites: [],
    shifts: [
        {
            id: 11,
            client_id: 4,
            site_id: 2,
            user_id: 7,
            shift_type: 'day',
            label: 'Morning support',
            starts_at: '2026-10-04T18:00:00Z',
            ends_at: '2026-10-05T02:00:00Z',
            staff: { id: 7, name: 'Hemi' },
        },
    ],
};
const note: ShiftNote = {
    id: 9,
    type: 'shift_note',
    body: 'Original note',
    is_flagged: false,
    flagged_reason: null,
    is_private: true,
    subject: null,
    created_at: '2026-10-05T02:00:00Z',
    reviewed_at: null,
    reviewer: null,
    edited_at: null,
    editor: null,
    user: { id: 7, name: 'Hemi' },
    client: catalogue.clients[0],
    site: { id: 2, name: 'Kauri house' },
    shift: catalogue.shifts[0],
    can_edit: true,
    can_flag: false,
    can_review: false,
    lock: { locked: false, reason: '', days_left: 6, age_days: 1 },
};
const props = {
    open: true,
    onOpenChange: vi.fn(),
    initial: { client_id: 4, shift_id: 11 },
    catalogue,
    onCreated: vi.fn(),
    actorId: 7,
    canSave: true,
    timeZone: 'Pacific/Auckland',
    weekStart: '2026-10-05',
};
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('crypto', { subtle: webcrypto.subtle });
});
afterEach(() => vi.unstubAllGlobals());
const write = (body: string) => {
    fireEvent.click(screen.getByRole('button', { name: 'Details & privacy' }));
    fireEvent.change(screen.getByRole('textbox', { name: /^Note/ }), {
        target: { value: body },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Review' }));
};
it('keeps edits with the original person and shift even when the current catalogue does not include them', async () => {
    render(
        <NoteWizard
            {...props}
            catalogue={{ clients: [], staff: [], sites: [], shifts: [] }}
            editNote={note}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Person & shift' }));
    expect(screen.getByText('Aroha Example')).toBeVisible();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    write('Updated care summary');
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(put).toHaveBeenCalledOnce());
    expect(put.mock.calls[0][0]).toBe('/operations/shift-notes/9');
    expect(put.mock.calls[0][1]).toMatchObject({
        body: 'Updated care summary',
        is_private: true,
    });
    expect(put.mock.calls[0][1]).not.toHaveProperty('shift_id');
    expect(put.mock.calls[0][1]).not.toHaveProperty('client_id');
});
it('asks before discarding and keeps the exact draft when the worker chooses to continue editing', () => {
    render(<NoteWizard {...props} />);
    write('A draft worth keeping');
    fireEvent.click(screen.getByRole('button', { name: 'Dismiss wizard' }));
    const confirmation = screen.getByRole('alertdialog');
    expect(within(confirmation).getByText('Discard this draft?')).toBeVisible();
    fireEvent.click(
        within(confirmation).getByRole('button', { name: 'Keep editing' }),
    );
    expect(props.onOpenChange).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Details & privacy' }));
    expect(screen.getByRole('textbox', { name: /^Note/ })).toHaveValue(
        'A draft worth keeping',
    );
});
it('jumps to and focuses the required note instead of submitting an empty final step', () => {
    render(<NoteWizard {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save note' }));
    expect(post).not.toHaveBeenCalled();
    expect(screen.getByText('Write the note before saving.')).toBeVisible();
    expect(screen.getByRole('textbox', { name: /^Note/ })).toHaveFocus();
});
it('holds a missing receipt, keeps its text, and offers a fresh review with no automatic replay', async () => {
    render(<NoteWizard {...props} />);
    write('Check whether this saved');
    fireEvent.click(screen.getByRole('button', { name: 'Save note' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    act(() => {
        post.mock.calls[0][2].onSuccess({
            props: { flash: { success: 'Saved' } },
        });
        post.mock.calls[0][2].onFinish();
    });
    expect(screen.queryByText('Shift note saved')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save note' })).toBeDisabled();
    const recovery = screen.getByRole('link', {
        name: 'Check current notes in a new tab',
    });
    expect(recovery).toHaveAttribute(
        'href',
        '/operations/shift-notes?week=2026-10-05&status=all&page=1&client_id=4&author_id=7',
    );
    expect(recovery).toHaveAttribute('target', '_blank');
    fireEvent.click(screen.getByRole('button', { name: 'Details & privacy' }));
    expect(screen.getByRole('textbox', { name: /^Note/ })).toHaveValue(
        'Check whether this saved',
    );
    expect(screen.getByRole('textbox', { name: /^Note/ })).toBeDisabled();
    expect(post).toHaveBeenCalledOnce();
});
it('shows success only for the matching committed note and retains its actual private status', async () => {
    render(<NoteWizard {...props} />);
    write('  Kia ora 🌿  ');
    fireEvent.click(screen.getByRole('button', { name: 'Save note' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    const values = normalizedNoteValues(post.mock.calls[0][1]);
    const values_hash = await noteValuesHash(values);
    act(() => {
        post.mock.calls[0][2].onSuccess({
            props: {
                flash: {
                    shift_note_result: {
                        action: 'create',
                        actor_id: 7,
                        note_id: 24,
                        shift_id: 11,
                        client_id: 4,
                        changed: true,
                        values_hash,
                        is_flagged: false,
                        is_private: false,
                        edited_at: null,
                        edited_by: null,
                        reviewed_at: null,
                        reviewed_by: null,
                    },
                },
            },
        });
        post.mock.calls[0][2].onFinish();
    });
    expect(
        screen.getByRole('heading', { name: 'Shift note saved' }),
    ).toBeVisible();
    expect(
        screen.getByText(/Note #24 is saved for Aroha Example/),
    ).toBeVisible();
    expect(props.onCreated).not.toHaveBeenCalled();
});
it('preserves entries and focuses a server validation error', async () => {
    render(<NoteWizard {...props} />);
    write('Retain me');
    fireEvent.click(screen.getByRole('button', { name: 'Save note' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    act(() => {
        post.mock.calls[0][2].onError({ body: 'More context is required.' });
        post.mock.calls[0][2].onFinish();
    });
    expect(screen.getByRole('textbox', { name: /^Note/ })).toHaveValue(
        'Retain me',
    );
    expect(screen.getByRole('textbox', { name: /^Note/ })).toHaveFocus();
    expect(screen.getByText('More context is required.')).toBeVisible();
});

it('keeps the draft but disables a newly denied save in an already-open editor', () => {
    const { rerender } = render(<NoteWizard {...props} />);
    write('Preserve after access changes');
    rerender(<NoteWizard {...props} canSave={false} />);
    expect(screen.getByRole('button', { name: 'Save note' })).toBeDisabled();
    expect(
        screen.getByText(
            /no longer available under the current record or permissions/,
        ),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Details & privacy' }));
    expect(screen.getByRole('textbox', { name: /^Note/ })).toHaveValue(
        'Preserve after access changes',
    );
    expect(post).not.toHaveBeenCalled();
});

it('supports arrow-key choice and one tab stop for note types', () => {
    render(<NoteWizard {...props} />);
    const shift = screen.getByRole('radio', { name: /^Shift Note/ });
    shift.focus();
    fireEvent.keyDown(shift, { key: 'ArrowRight' });
    const progress = screen.getByRole('radio', { name: /^Progress Note/ });
    expect(progress).toHaveAttribute('aria-checked', 'true');
    expect(progress).toHaveFocus();
    expect(shift).toHaveAttribute('tabindex', '-1');
    fireEvent.keyDown(progress, { key: 'End' });
    expect(screen.getByRole('radio', { name: /^General/ })).toHaveFocus();
});
