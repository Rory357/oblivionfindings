import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    EventWizardDialog,
    type CalendarEventInitial,
} from './event-wizard-dialog';

const initial: CalendarEventInitial = {
    id: 4,
    edit_version: 7,
    title: 'Team learning',
    description: 'Bring your questions',
    event_type: 'company',
    starts_at: '2026-10-20T21:00:00Z',
    ends_at: '2026-10-20T22:00:00Z',
    is_all_day: false,
    location: 'Main room',
    site_id: null,
    department_id: null,
    scope: 'this',
    occurrence_date: '2026-10-20',
    rrule: 'FREQ=WEEKLY',
    attachments: [
        {
            id: 8,
            eventId: 4,
            name: 'series.txt',
            mime: 'text/plain',
            size: 12,
            url: '/hr/calendar/attachments/8/download',
        },
    ],
};
const props = {
    open: true,
    onClose: vi.fn(),
    onSaved: vi.fn(),
    sites: [],
    departments: [],
    teams: [],
    categories: [],
    staff: [],
};
function continueTo(step: number) {
    for (let i = 0; i < step; i++)
        fireEvent.click(screen.getByRole('button', { name: /^Continue$/ }));
}

describe('Canonical event editor saves', () => {
    beforeEach(() => {
        vi.spyOn(router, 'post').mockImplementation(() => undefined);
        vi.spyOn(router, 'delete').mockImplementation(() => undefined);
        props.onSaved.mockClear();
    });
    afterEach(() => vi.restoreAllMocks());
    it('retains the canonical archive action for a publisher opening a live event', () => {
        render(
            <EventWizardDialog
                {...props}
                initial={{ ...initial, scope: 'all' }}
                publishing={{ can_publish: true }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Archive$/ }));
        expect(screen.getByRole('alertdialog')).toBeInTheDocument();
        expect(router.delete).not.toHaveBeenCalled();
    });
    it('saves a private new draft with registration settings without calling the live calendar', () => {
        render(
            <EventWizardDialog
                {...props}
                initial={{ ...initial, id: 0, scope: 'all', attachments: [] }}
                publishing={{ can_publish: false }}
            />,
        );
        continueTo(3);
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Open registration when published',
            }),
        );
        fireEvent.change(
            screen.getByRole('spinbutton', { name: 'Places per date' }),
            { target: { value: '25' } },
        );
        continueTo(1);
        expect(
            screen.queryByRole('button', { name: 'Publish event' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(vi.mocked(router.post).mock.calls[0][0]).toBe(
            '/company-hub/event-drafts',
        );
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({
            mode: 'draft',
            source_event_id: null,
            registration_enabled: true,
            registration_capacity: 25,
        });
        expect(vi.mocked(router.post).mock.calls[0][1]).not.toHaveProperty(
            '_method',
        );
    });
    it('submits an existing draft version for review and omits registration changes on a single date', () => {
        render(
            <EventWizardDialog
                {...props}
                initial={initial}
                publishing={{ draft_id: 12, version: 3, can_publish: false }}
            />,
        );
        continueTo(4);
        fireEvent.click(
            screen.getByRole('button', { name: 'Send for review' }),
        );
        expect(vi.mocked(router.post).mock.calls[0][0]).toBe(
            '/company-hub/event-drafts/12',
        );
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({
            mode: 'review',
            version: 3,
            source_event_id: 4,
            edit_version: 7,
            scope: 'this',
        });
        expect(vi.mocked(router.post).mock.calls[0][1]).not.toHaveProperty(
            'registration_enabled',
        );
    });
    it('saves the selected reminder channel and explains unavailable email delivery', () => {
        render(
            <EventWizardDialog
                {...props}
                emailAvailable={false}
                initial={{ ...initial, reminders: [] }}
            />,
        );
        continueTo(3);
        expect(
            screen.getByText(/External email is not configured/),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Email' }));
        fireEvent.click(screen.getByRole('button', { name: '1 hour before' }));
        continueTo(1);
        expect(screen.getByText(/1 hour before · Email/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({
            reminders: [{ offset_minutes: 60, channel: 'email' }],
        });
    });

    it('shows inherited single-date controls and protects shared files and whole-series archive', () => {
        render(<EventWizardDialog {...props} initial={initial} />);
        expect(
            screen.queryByRole('button', { name: /^Archive$/ }),
        ).not.toBeInTheDocument();
        continueTo(1);
        expect(screen.getByRole('combobox', { name: 'Repeat' })).toBeDisabled();
        expect(
            screen.getByRole('checkbox', {
                name: /Keep the same New Zealand time all year/,
            }),
        ).toBeDisabled();
        continueTo(1);
        expect(screen.getByRole('combobox', { name: 'Site' })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: /^Everyone$/ }),
        ).toBeDisabled();
        continueTo(1);
        expect(screen.getByText('Shared by the series')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Remove series.txt' }),
        ).not.toBeInTheDocument();
    });

    it('preserves existing repeat timing until an editor explicitly changes the series setting', () => {
        render(
            <EventWizardDialog
                {...props}
                initial={{ ...initial, scope: 'all' }}
            />,
        );
        continueTo(1);
        const localTime = screen.getByRole('checkbox', {
            name: /Keep the same New Zealand time all year/,
        });
        expect(localTime).not.toBeChecked();
        fireEvent.click(localTime);
        continueTo(3);
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({
            recurrence_timezone: 'Pacific/Auckland',
        });
    });

    it('submits metadata, staged files and the saved version in one request and retains files after errors', async () => {
        const { container } = render(
            <EventWizardDialog {...props} initial={initial} />,
        );
        continueTo(3);
        const file = new File(['Information for this date'], 'date.txt', {
            type: 'text/plain',
        });
        fireEvent.change(
            container.ownerDocument.querySelector('input[type=file]')!,
            { target: { files: [file] } },
        );
        continueTo(1);
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        const [url, body, options] = vi.mocked(router.post).mock.calls[0];
        expect(url).toBe('/hr/calendar/events/4');
        expect(body).toMatchObject({
            _method: 'put',
            edit_version: 7,
            scope: 'this',
            files: [file],
            removed_attachment_ids: [],
        });
        expect(options?.forceFormData).toBe(true);
        await act(async () => {
            options?.onError?.({ 'files.0': 'This file could not be saved.' });
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'This file could not be saved.',
        );
        expect(screen.getByText('date.txt')).toBeInTheDocument();
        expect(props.onSaved).not.toHaveBeenCalled();
    });

    it('allows a date-owned file to be removed without touching an inherited file', () => {
        render(
            <EventWizardDialog
                {...props}
                initial={{
                    ...initial,
                    id: 5,
                    recurrence_parent_id: 4,
                    scope: 'all',
                    rrule: null,
                    attachments: [
                        { ...initial.attachments![0], inherited: true },
                        {
                            id: 9,
                            eventId: 5,
                            name: 'own.txt',
                            mime: 'text/plain',
                            size: 4,
                            url: '/hr/calendar/attachments/9/download',
                        },
                    ],
                }}
            />,
        );
        continueTo(3);
        fireEvent.click(screen.getByRole('button', { name: 'Remove own.txt' }));
        expect(
            screen.queryByRole('button', { name: 'Remove series.txt' }),
        ).not.toBeInTheDocument();
        continueTo(1);
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({
            removed_attachment_ids: [9],
            edit_version: 7,
        });
    });

    it('keeps a stale draft visible and refreshes the feed when the editor is closed', async () => {
        render(<EventWizardDialog {...props} initial={initial} />);
        continueTo(4);
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        const options = vi.mocked(router.post).mock.calls[0][2];
        await act(async () => {
            options?.onError?.({
                edit_version: 'This event changed while you were editing.',
            });
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'This event changed',
        );
        expect(props.onSaved).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(props.onSaved).toHaveBeenCalledOnce();
    });
});
