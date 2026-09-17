import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    formPost: vi.fn(),
    routerPost: vi.fn(),
    routerPut: vi.fn(),
    /** The page the next visit "succeeds" with (flash errors still fire onSuccess). */
    nextPage: { props: { flash: {} } } as { props: { flash: { error?: string } } },
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    type VisitOptions = { onSuccess?: (page: unknown) => void; onFinish?: () => void };

    return {
        router: {
            post: (url: string, data: unknown, options?: VisitOptions) => {
                inertia.routerPost(url, data);
                options?.onSuccess?.(inertia.nextPage);
                options?.onFinish?.();
            },
            put: (url: string, data: unknown, options?: VisitOptions) => {
                inertia.routerPut(url, data);
                options?.onSuccess?.(inertia.nextPage);
                options?.onFinish?.();
            },
        },
        usePage: () => ({ url: '/governance/meetings/7', props: {} }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            const transformer = React.useRef<(d: T) => unknown>((d) => d);
            return {
                data,
                errors: {},
                processing: false,
                setData: (key: keyof T, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                transform: (fn: (d: T) => unknown) => {
                    transformer.current = fn;
                },
                post: (url: string, options?: VisitOptions) => {
                    inertia.formPost(url, transformer.current(data));
                    options?.onSuccess?.(inertia.nextPage);
                },
            };
        },
    };
});

import { MinutesEditorDialog, RsvpDialog } from './_workspace-dialogs';

afterEach(() => {
    cleanup();
    inertia.formPost.mockReset();
    inertia.routerPost.mockReset();
    inertia.routerPut.mockReset();
    inertia.nextPage = { props: { flash: {} } };
});

describe('RsvpDialog', () => {
    it('asks with tiles, keeps the apology reason apart from dietary needs, and closes once recorded', () => {
        const onClose = vi.fn();
        const onSaved = vi.fn();
        render(
            <RsvpDialog
                isOpen
                onClose={onClose}
                onSaved={onSaved}
                meetingId={7}
                meetingTitle="October board meeting"
                meetingWhen="7 October 2026, 5:00 pm"
            />,
        );

        expect(screen.getByText('Reply to the meeting invitation')).toBeTruthy();
        expect(
            screen.getByText("Let the secretary know whether you'll be at October board meeting on 7 October 2026, 5:00 pm."),
        ).toBeTruthy();
        expect(screen.getByRole('button', { name: /attending/i }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.getByRole('checkbox')).toBeTruthy();
        expect(document.querySelector('[data-test="rsvp-decline-reason"]')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /sending apologies/i }));
        expect(screen.queryByRole('checkbox')).toBeNull();
        const reason = document.querySelector('[data-test="rsvp-decline-reason"]') as HTMLTextAreaElement;
        fireEvent.change(reason, { target: { value: 'Overseas that week' } });

        fireEvent.click(screen.getByRole('button', { name: 'Send reply' }));

        expect(inertia.formPost).toHaveBeenCalledWith('/governance/meetings/7/rsvp', {
            response: 'declined',
            decline_reason: 'Overseas that week',
            dietary_requirements: false,
            dietary_notes: null,
        });
        expect(onSaved).toHaveBeenCalledTimes(1);
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('stays open and shows the server’s reason when the reply is refused', () => {
        inertia.nextPage = {
            props: { flash: { error: 'Only members of this committee are invited to this meeting.' } },
        };
        const onClose = vi.fn();
        const onSaved = vi.fn();
        render(
            <RsvpDialog
                isOpen
                onClose={onClose}
                onSaved={onSaved}
                meetingId={7}
                meetingTitle="Finance committee"
                existing={{ response: 'accepted', decline_reason: null, dietary_requirements: false, dietary_notes: null }}
            />,
        );

        expect(screen.getByText('Change your reply')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Update reply' }));

        expect(screen.getByRole('alert').textContent).toBe(
            'Only members of this committee are invited to this meeting.',
        );
        expect(onSaved).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();
    });
});

describe('MinutesEditorDialog', () => {
    it('starts with plain headings and keeps the draft open when the save is refused', () => {
        inertia.nextPage = {
            props: {
                flash: {
                    error: "Someone else saved these minutes while you were editing (you started from version 2; they're now version 3). Refresh the page to see their changes, then make yours again.",
                },
            },
        };
        const onClose = vi.fn();
        render(
            <MinutesEditorDialog
                isOpen
                onClose={onClose}
                meetingId={7}
                minutes={{
                    version_number: 2,
                    content_blocks: [{ heading: 'General business', content: 'Agreed the plan.' }],
                }}
            />,
        );

        expect(screen.getByText('Edit the minutes (version 2)')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(inertia.routerPut).toHaveBeenCalledWith('/governance/meetings/7/minutes', {
            content_blocks: [{ heading: 'General business', content: 'Agreed the plan.' }],
            expected_version: 2,
        });
        expect(screen.getByRole('alert').textContent).toContain('Someone else saved these minutes');
        expect(onClose).not.toHaveBeenCalled();
    });

    it('offers sentence-case headings for new minutes and closes once saved', () => {
        const onClose = vi.fn();
        render(<MinutesEditorDialog isOpen onClose={onClose} meetingId={7} minutes={null} />);

        expect(screen.getByDisplayValue('Welcome and apologies')).toBeTruthy();
        expect(screen.getByDisplayValue('Minutes of the previous meeting')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(inertia.routerPost).toHaveBeenCalledWith(
            '/governance/meetings/7/minutes',
            expect.objectContaining({ expected_version: null }),
        );
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
