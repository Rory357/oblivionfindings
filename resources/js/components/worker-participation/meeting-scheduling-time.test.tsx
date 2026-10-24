import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MeetingDetailDialog } from './meeting-detail-dialog';
import { ScheduleMeetingWizard } from './schedule-meeting-wizard';
import type { MeetingDetail } from './shared';

const transport = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn() }));

// Only replace transport. Preserve the controlled form, real detail pane,
// WizardShell, actual time field, pickers and Radix surfaces.
vi.mock('@inertiajs/react', async () => {
    const React = await vi.importActual<typeof import('react')>('react');
    type Data = Record<string, unknown>;
    return {
        router: { put: vi.fn(), post: transport.post },
        useForm: (initial: Data) => {
            const [data, update] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            const transform = React.useRef<(data: Data) => Data>(
                (value) => value,
            );
            return {
                data,
                errors,
                processing: false,
                setData: (
                    key: string | ((previous: Data) => Data),
                    value?: unknown,
                ) =>
                    update((previous) =>
                        typeof key === 'function'
                            ? key(previous)
                            : { ...previous, [key]: value },
                    ),
                setError: (key: string, value: string) =>
                    setErrors((previous) => ({ ...previous, [key]: value })),
                transform: (callback: (data: Data) => Data) => {
                    transform.current = callback;
                },
                put: (url: string) =>
                    transport.put(url, transform.current(data)),
            };
        },
    };
});

beforeEach(() => {
    transport.put.mockClear();
    transport.post.mockClear();
});
afterEach(() => {
    cleanup();
    vi.unstubAllEnvs();
    vi.useRealTimers();
});

function show(instant: string, status = 'scheduled', edit = true) {
    const detail: MeetingDetail = {
        id: 31,
        hs_committee_id: 8,
        committee: { id: 8, name: 'North committee' },
        scheduled_at: instant,
        ended_at: null,
        location: 'North office',
        status,
        agenda_items: [' Check actions '],
        minutes: null,
        action_items: [],
        actions_due_count: 0,
        minutes_document_path: null,
        minutes_document_name: null,
        attendee_users: [],
    };
    render(
        <MeetingDetailDialog
            detail={detail}
            open
            onClose={vi.fn()}
            staff={[]}
            can={{ manage: true }}
            initialAction={edit ? 'edit' : null}
        />,
    );
}

async function changeTime(hour: string, minute = '30', period = 'AM') {
    fireEvent.click(
        screen.getByRole('button', { name: /^Meeting date & time time:/ }),
    );
    fireEvent.change(
        await screen.findByLabelText('Meeting date & time time hour'),
        { target: { value: hour } },
    );
    fireEvent.change(screen.getByLabelText('Meeting date & time time minute'), {
        target: { value: minute },
    });
    fireEvent.click(screen.getByRole('button', { name: period }));
    fireEvent.click(screen.getByRole('button', { name: 'Use time' }));
}

describe('Actual meeting schedule editor', () => {
    it('presents unknown meeting state honestly and labels record sections instead of form progress', () => {
        show('2026-10-14T23:30:00Z', 'legacy_unknown', false);
        expect(
            screen.getAllByText('Status not confirmed').length,
        ).toBeGreaterThan(0);
        expect(
            screen.queryByText('Completed', { exact: true }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/Step 1 of/)).not.toBeInTheDocument();
        expect(
            document.querySelector('[data-wizard-region="progress"]'),
        ).not.toBeInTheDocument();
        expect(
            screen.getAllByText('Overview', { exact: true }).length,
        ).toBeGreaterThan(0);
        fireEvent.click(screen.getByRole('button', { name: /^Attendees/ }));
        expect(
            document.querySelector('[data-wizard-region="header"]'),
        ).toHaveTextContent('Attendees');
        expect(
            document.querySelector('[data-wizard-region="progress"]'),
        ).not.toBeInTheDocument();
        expect(
            document.querySelector('[data-wizard-region="rail"] .lucide-check'),
        ).not.toBeInTheDocument();
        expect(transport.put).not.toHaveBeenCalled();
        expect(transport.post).not.toHaveBeenCalled();
    });
    it.each([
        ['2026-01-14T23:30:00Z', '15 Jan 2026', '12:30 PM'],
        ['2026-07-15T00:30:00Z', '15 Jul 2026', '12:30 PM'],
        ['2026-04-04T14:30:00Z', '5 Apr 2026', '02:30 AM'],
    ])(
        'prefills the worker clock from %s in a different browser timezone',
        (instant, day, time) => {
            vi.stubEnv('TZ', 'America/Los_Angeles');
            show(instant);
            expect(
                screen.getByRole('button', {
                    name: `Meeting date & time date: ${day}`,
                }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('button', {
                    name: `Meeting date & time time: ${time}`,
                }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('group', {
                    name: /Meeting date & time Pacific\/Auckland/,
                }),
            ).toBeInTheDocument();
        },
    );

    it.each(['2026-01-14T23:30:47Z', '2026-04-04T14:30:47Z'])(
        'omits the unchanged schedule when editing location at %s',
        (instant) => {
            show(instant);
            fireEvent.change(
                screen.getByPlaceholderText('e.g. Site office — meeting room'),
                { target: { value: 'South office' } },
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Save changes' }),
            );
            expect(transport.put).toHaveBeenCalledWith(
                '/health-safety/worker-participation/meetings/31',
                { location: 'South office', agenda_items: ['Check actions'] },
            );
        },
    );

    it('submits a changed unique clock reading with its explicit scheduling timezone', async () => {
        show('2026-01-14T23:30:47Z');
        await changeTime('01', '30', 'PM');
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(transport.put).toHaveBeenCalledWith(
            '/health-safety/worker-participation/meetings/31',
            expect.objectContaining({
                scheduled_at: '2026-01-15T13:30',
                scheduled_timezone: 'Pacific/Auckland',
                scheduled_offset: null,
            }),
        );
    });

    it('requires a clock occurrence after changing into the repeated hour and retains the second choice', async () => {
        show('2026-04-04T15:30:00Z');
        await changeTime('02');
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(transport.put).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Choose which occurrence',
        );
        fireEvent.keyDown(
            screen.getByRole('combobox', {
                name: 'Which occurrence of this time?',
            }),
            { key: 'Enter' },
        );
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Second occurrence · UTC+12:00',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(transport.put).toHaveBeenCalledWith(
            '/health-safety/worker-participation/meetings/31',
            expect.objectContaining({
                scheduled_at: '2026-04-05T02:30',
                scheduled_timezone: 'Pacific/Auckland',
                scheduled_offset: '+12:00',
            }),
        );
    });

    it('blocks a skipped daylight-saving clock reading before transport', async () => {
        show('2026-09-26T13:30:00Z');
        await changeTime('02');
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(transport.put).not.toHaveBeenCalled();
        expect(
            screen
                .getAllByRole('alert')
                .some((alert) =>
                    alert.textContent?.includes('valid New Zealand'),
                ),
        ).toBe(true);
    });

    it('carries an actual picker change through the create wizard and its request', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-13T00:00:00Z'));
        render(
            <ScheduleMeetingWizard
                open
                onClose={vi.fn()}
                committees={[
                    {
                        id: 8,
                        name: 'North committee',
                        site_id: 4,
                        meeting_frequency: 'monthly',
                        meetings_count: 1,
                    },
                ]}
                sites={[{ id: 4, name: 'North House' }]}
                staff={[]}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: /North committee/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Meeting date & time date: Choose date',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: /^Thu 15 October 2026/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Use date' }));
        await changeTime('12', '30', 'PM');
        expect(
            screen.getByRole('button', {
                name: 'Meeting date & time date: 15 Oct 2026',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Meeting date & time time: 12:30 PM',
            }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Schedule meeting' }),
        );
        expect(transport.post).toHaveBeenCalledWith(
            '/health-safety/worker-participation/committees/8/meetings',
            expect.objectContaining({
                scheduled_at: '2026-10-15T12:30',
                scheduled_timezone: 'Pacific/Auckland',
                scheduled_offset: null,
            }),
            expect.any(Object),
        );
    });
});
