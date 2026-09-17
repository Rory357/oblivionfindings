import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    transform: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        router: { replace: vi.fn() },
        usePage: () => ({ url: '/governance/meetings', props: {} }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            const transformer = React.useRef<(d: T) => unknown>((d) => d);
            return {
                data,
                errors,
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                setData: (key: keyof T, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                transform: (fn: (d: T) => unknown) => {
                    transformer.current = fn;
                    inertia.transform(fn);
                },
                post: (url: string, options: unknown) => {
                    inertia.post(url, transformer.current(data), options);
                },
                put: (
                    url: string,
                    options: { onError?: (e: Record<string, string>) => void },
                ) => {
                    inertia.put(url, transformer.current(data), options);
                    const serverErrors = {
                        virtual_link: 'The virtual link field must be a valid URL.',
                    };
                    setErrors(serverErrors);
                    options.onError?.(serverErrors);
                },
            };
        },
    };
});

import {
    MeetingWizardDialog,
    nzLocalToUtcIso,
    quorumSentence,
} from './_dialogs';

const options = {
    board_members: [
        { id: 3, name: 'Aroha Ngata', is_active: true, counts_for_quorum: true },
        { id: 4, name: 'Ben Carter', is_active: true, counts_for_quorum: true },
        { id: 5, name: 'Former Member', is_active: false, counts_for_quorum: false },
    ],
    committees: [
        { id: 9, name: 'Finance Committee', committee_type: 'finance', member_ids: [3] },
    ],
    can_schedule_executive: false,
};

const existingMeeting = {
    id: 21,
    title: 'October board meeting',
    meeting_type: 'full_board',
    board_committee_id: null,
    scheduled_at: '2026-10-14T20:00:00+00:00',
    duration_minutes: 90,
    location: 'Board room',
    virtual_link: '',
    notes: null,
    status: 'scheduled',
    quorum_required: 50,
    chair_id: 3,
    secretary_id: 4,
};

afterEach(() => {
    cleanup();
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.transform.mockReset();
});

describe('nzLocalToUtcIso', () => {
    it('converts New Zealand wall time to the stored UTC instant', () => {
        // NZST (UTC+12) in winter, NZDT (UTC+13) in summer.
        expect(nzLocalToUtcIso('2026-07-15T09:00')).toBe(
            '2026-07-14T21:00:00.000Z',
        );
        expect(nzLocalToUtcIso('2026-12-15T09:00')).toBe(
            '2026-12-14T20:00:00.000Z',
        );
    });
});

describe('quorumSentence', () => {
    it('turns the percentage into the head count the workspace shows', () => {
        expect(quorumSentence(50, 6)).toBe(
            'With 6 members today, at least 3 must be present for decisions to be valid.',
        );
        expect(quorumSentence(60, 5)).toBe(
            'With 5 members today, at least 3 must be present for decisions to be valid.',
        );
        expect(quorumSentence(50, null)).toBeNull();
    });
});

describe('MeetingWizardDialog', () => {
    it('hides board-only sessions from schedulers without that authority', () => {
        render(<MeetingWizardDialog isOpen onClose={() => {}} options={options} />);

        expect(screen.getByRole('button', { name: /full board meeting/i })).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: /board-only session/i }),
        ).toBeNull();
    });

    it('blocks Continue until the purpose step has a title', () => {
        render(<MeetingWizardDialog isOpen onClose={() => {}} options={options} />);

        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        expect(screen.getByText('Purpose', { selector: 'h2' })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        expect(screen.getByText('Give the meeting a title.')).toBeTruthy();
    });

    it('takes the committee from the meeting type', () => {
        render(<MeetingWizardDialog isOpen onClose={() => {}} options={options} />);

        // A whole-board meeting has no committee to choose.
        expect(screen.queryByText('Which committee?')).toBeNull();

        // A committee type offers only its own committee — here, picked for you.
        fireEvent.click(screen.getByRole('button', { name: /^finance committee/i }));
        expect(screen.getByText('Which committee?')).toBeTruthy();
        expect(screen.getByRole('combobox', { name: 'Committee' })).toBeTruthy();

        // A committee that hasn't been set up says so, and can't be scheduled.
        fireEvent.click(screen.getByRole('button', { name: /^audit and risk committee/i }));
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        expect(
            screen.getAllByText(
                "There's no audit and risk committee set up yet, so this meeting can't be scheduled for it. Ask an administrator to add the committee, or choose another type of meeting.",
            ).length,
        ).toBeGreaterThan(0);
        expect(screen.queryByText('Purpose', { selector: 'h2' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /^finance committee/i }));
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        expect(screen.getByText('Purpose', { selector: 'h2' })).toBeTruthy();
    });

    it('says how many members the quorum percentage means', () => {
        render(<MeetingWizardDialog isOpen onClose={() => {}} options={options} />);

        fireEvent.click(screen.getByRole('button', { name: /^chair and quorum/i }));

        expect(
            screen.getByText(
                'With 2 members today, at least 1 must be present for decisions to be valid.',
            ),
        ).toBeTruthy();
    });

    it('edits only whether the meeting goes ahead, and asks before cancelling it', () => {
        render(
            <MeetingWizardDialog
                isOpen
                onClose={() => {}}
                options={options}
                meeting={existingMeeting}
            />,
        );

        expect(screen.getByText("Change this meeting's details.")).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /^when and where/i }));
        expect(screen.getByRole('button', { name: /^scheduled/i })).toBeTruthy();
        expect(screen.queryByText(/minutes signed/i)).toBeNull();
        expect(screen.queryByText(/minutes approved/i)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /^cancelled/i }));
        fireEvent.click(screen.getByRole('button', { name: /^review/i }));
        fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

        // Nothing is saved until the cancellation is confirmed.
        expect(inertia.put).not.toHaveBeenCalled();
        expect(screen.getByText('Cancel this meeting?')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel meeting' }));
        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(inertia.put.mock.calls[0][1]).toMatchObject({ status: 'cancelled' });
    });

    it('edits prefilled in NZ time, sends UTC, and jumps to the step owning a server error', () => {
        render(
            <MeetingWizardDialog
                isOpen
                onClose={() => {}}
                options={options}
                meeting={{
                    ...existingMeeting,
                    meeting_type: 'executive_session',
                    board_committee_id: 9,
                    virtual_link: 'teams-link',
                    status: 'agenda_final',
                    quorum_required: 60,
                }}
            />,
        );

        // An existing board-only session stays selectable when editing it.
        expect(
            screen.getByRole('button', { name: /board-only session/i }),
        ).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /^review/i }));
        fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

        // Client validation catches the malformed link before submitting.
        expect(inertia.put).not.toHaveBeenCalled();
        expect(screen.getByText('Enter a full link, e.g. https://…')).toBeTruthy();

        fireEvent.change(screen.getByLabelText(/video link/i), {
            target: { value: 'https://teams.example.com/meet' },
        });
        fireEvent.click(screen.getByRole('button', { name: /^review/i }));
        fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

        expect(inertia.put).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.put.mock.calls[0];
        expect(url).toBe('/governance/meetings/21');
        expect(payload).toMatchObject({
            title: 'October board meeting',
            meeting_type: 'executive_session',
            board_committee_id: '9',
            // 9:00 am NZDT on 15 Oct → 20:00 UTC on 14 Oct.
            scheduled_at: '2026-10-14T20:00:00.000Z',
            duration_minutes: 90,
            quorum_required: 60,
            chair_id: '3',
            secretary_id: '4',
            // Saving keeps the meeting's current stage.
            status: 'agenda_final',
        });

        expect(screen.getByText('When and where', { selector: 'h2' })).toBeTruthy();
        expect(
            screen.getByText('The virtual link field must be a valid URL.'),
        ).toBeTruthy();
    });
});
