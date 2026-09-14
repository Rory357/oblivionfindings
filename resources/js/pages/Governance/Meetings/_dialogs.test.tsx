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

import { MeetingWizardDialog, nzLocalToUtcIso } from './_dialogs';

const options = {
    board_members: [
        { id: 3, name: 'Aroha Ngata', is_active: true },
        { id: 4, name: 'Ben Carter', is_active: true },
    ],
    committees: [{ id: 9, name: 'Finance Committee', committee_type: 'finance' }],
    can_schedule_executive: false,
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

describe('MeetingWizardDialog', () => {
    it('hides executive sessions from schedulers without executive authority', () => {
        render(<MeetingWizardDialog isOpen onClose={() => {}} options={options} />);

        expect(screen.getByRole('button', { name: /full board/i })).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: /executive session/i }),
        ).toBeNull();
    });

    it('blocks Continue until the purpose step has a title', () => {
        render(<MeetingWizardDialog isOpen onClose={() => {}} options={options} />);

        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        expect(screen.getByText('Purpose', { selector: 'h2' })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        expect(screen.getByText('Give the meeting a title.')).toBeTruthy();
    });

    it('edits prefilled in NZ time, sends UTC, and jumps to the step owning a server error', () => {
        render(
            <MeetingWizardDialog
                isOpen
                onClose={() => {}}
                options={options}
                meeting={{
                    id: 21,
                    title: 'October board meeting',
                    meeting_type: 'executive_session',
                    board_committee_id: 9,
                    scheduled_at: '2026-10-14T20:00:00+00:00',
                    duration_minutes: 90,
                    location: 'Board room',
                    virtual_link: 'teams-link',
                    notes: null,
                    status: 'agenda_final',
                    quorum_required: 60,
                    chair_id: 3,
                    secretary_id: 4,
                }}
            />,
        );

        // An existing executive session stays selectable when editing it.
        expect(
            screen.getByRole('button', { name: /executive session/i }),
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
            status: 'agenda_final',
        });

        expect(screen.getByText('When and where')).toBeTruthy();
        expect(
            screen.getByText('The virtual link field must be a valid URL.'),
        ).toBeTruthy();
    });
});
