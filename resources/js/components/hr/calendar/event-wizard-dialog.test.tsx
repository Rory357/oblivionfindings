import { router } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { emptyEventHubContent } from './event-hub-fields';
import type { EventLibraryPhoto } from './event-image-library';
import { eventTimeInstant } from './event-time';
import {
    EventWizardDialog,
    type CalendarEventInitial,
} from './event-wizard-dialog';
vi.mock('./event-image-library', () => ({
    EventImageLibrary: ({
        onSelect,
    }: {
        onSelect: (photo: EventLibraryPhoto) => void;
    }) => (
        // eslint-disable-next-line no-restricted-syntax -- Small library test double; production uses the shared Button.
        <button
            type="button"
            onClick={() =>
                onSelect({
                    id: 41,
                    version: 8,
                    name: 'garden.jpg',
                    url: '/company-hub/media/41',
                    mime: 'image/jpeg',
                    size: 20,
                    hub_page_id: 2,
                    page_title: 'Company story',
                    alt: 'Herb garden',
                    caption: 'Our afternoon.',
                    album_id: 4,
                    albumName: 'Together',
                    crop: null,
                })
            }
        >
            Select library garden
        </button>
    ),
}));

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
async function chooseEventOption(label: string, option: string) {
    const trigger = screen.getByRole('combobox', { name: label });
    fireEvent.keyDown(trigger, { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: option }));
    await waitFor(() => expect(trigger).toHaveFocus());
}

describe('Canonical event editor saves', () => {
    beforeEach(() => {
        vi.spyOn(router, 'post').mockImplementation(() => undefined);
        vi.spyOn(router, 'delete').mockImplementation(() => undefined);
        props.onSaved.mockClear();
        props.onClose.mockClear();
    });
    afterEach(() => vi.restoreAllMocks());
    it('uses styled event menus and preserves organiser and one-event empty choices when saved', async () => {
        render(
            <EventWizardDialog
                {...props}
                presentation="page"
                staff={[{ value: '42', label: 'Example host' }]}
                categories={[
                    {
                        id: 1,
                        key: 'company',
                        label: 'Company',
                        icon: null,
                        color_token: 'hr',
                    },
                    {
                        id: 2,
                        key: 'training',
                        label: 'Training',
                        icon: null,
                        color_token: 'hr',
                    },
                ]}
                initial={{ ...initial, scope: 'all' }}
                publishing={{ can_publish: true }}
            />,
        );
        await chooseEventOption('Host', 'Example host');
        expect(
            screen.getByRole('combobox', { name: 'Host' }),
        ).toHaveTextContent('Example host');
        await chooseEventOption('Host', 'Use the organiser');
        await chooseEventOption('Category', 'Training');
        await chooseEventOption('Repeats', 'One event');
        expect(
            screen.queryByRole('button', { name: /Repeat until:/ }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({
            event_type: 'training',
            rrule: null,
            hub_content: expect.objectContaining({ host_id: null }),
        });
    });
    it('does not count retained canonical event files against the ten new draft file limit', () => {
        render(
            <EventWizardDialog
                {...props}
                presentation="page"
                initial={{
                    ...initial,
                    scope: 'all',
                    attachments: Array.from({ length: 11 }, (_, index) => ({
                        ...initial.attachments![0],
                        id: index + 1,
                    })),
                }}
                publishing={{ draft_id: 5, can_publish: true, actor_id: 9 }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cover & files' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Choose from company library' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Select library garden' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({
            hub_library_images: [{ media_id: 41, version: 8 }],
        });
    });
    it('counts retained private files and refuses an eleventh library selection', () => {
        render(
            <EventWizardDialog
                {...props}
                presentation="page"
                initial={{
                    ...initial,
                    scope: 'all',
                    attachments: Array.from({ length: 10 }, (_, index) => ({
                        ...initial.attachments![0],
                        id: -index - 1,
                    })),
                }}
                publishing={{ draft_id: 5, can_publish: true, actor_id: 9 }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cover & files' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Choose from company library' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Select library garden' }),
        );
        expect(
            screen.queryByRole('radio', { name: 'Use garden.jpg as cover' }),
        ).not.toBeInTheDocument();
    });
    it('saves library identity and version with ordered photo metadata in the private event draft', () => {
        render(
            <EventWizardDialog
                {...props}
                presentation="page"
                initial={{
                    ...initial,
                    scope: 'all',
                    hub_content: {
                        ...emptyEventHubContent(),
                        photo_details: [
                            {
                                ref: 'file:8',
                                title: 'Existing title',
                                alt: '',
                                caption: 'Retain this',
                                album: '',
                            },
                        ],
                    },
                }}
                publishing={{ can_publish: true, actor_id: 9 }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cover & files' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Choose from company library' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Select library garden' }),
        );
        fireEvent.click(
            screen.getByRole('radio', { name: 'Use garden.jpg as cover' }),
        );
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Include garden.jpg in recap',
            }),
        );
        fireEvent.change(screen.getByLabelText('Photo title'), {
            target: { value: 'Planting together' },
        });
        fireEvent.change(screen.getByLabelText('Recap title'), {
            target: { value: 'Our afternoon in the garden' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        const payload = vi.mocked(router.post).mock.calls[0][1];
        expect(payload).toMatchObject({
            actor_id: 9,
            hub_library_images: [{ media_id: 41, version: 8 }],
            hub_content: {
                cover_ref: 'library:41',
                gallery_refs: ['library:41'],
                recap_title: 'Our afternoon in the garden',
                photo_details: [
                    { ref: 'file:8', caption: 'Retain this' },
                    {
                        ref: 'library:41',
                        title: 'Planting together',
                        alt: 'Herb garden',
                        caption: 'Our afternoon.',
                        album: 'Together',
                    },
                ],
            },
        });
        expect(JSON.stringify(payload)).not.toContain('/company-hub/media/41');
        expect(JSON.stringify(payload)).not.toContain('Company story');
    });
    it('keeps the dialog open during saving and ignores completion from a previous opening', async () => {
        const view = render(<EventWizardDialog {...props} initial={initial} />);
        continueTo(4);
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        const oldOptions = vi.mocked(router.post).mock.calls[0][2];
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(props.onClose).not.toHaveBeenCalled();
        view.rerender(
            <EventWizardDialog {...props} open={false} initial={initial} />,
        );
        view.rerender(
            <EventWizardDialog
                {...props}
                initial={{ ...initial, title: 'Second invitation' }}
            />,
        );
        continueTo(4);
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await act(async () => {
            oldOptions?.onSuccess?.({} as never);
            oldOptions?.onFinish?.({} as never);
        });
        expect(props.onSaved).not.toHaveBeenCalled();
        expect(screen.queryByText('Event updated')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(router.post).toHaveBeenCalledTimes(2);
        expect(props.onClose).not.toHaveBeenCalled();
    });
    it('preserves a custom booking cutoff when reopening and saving a private event draft', () => {
        render(
            <EventWizardDialog
                {...props}
                presentation="page"
                initial={{
                    ...initial,
                    scope: 'all',
                    registration_enabled: true,
                    registration_closes_before_minutes: 1260,
                }}
                publishing={{
                    can_publish: true,
                    actor_id: 9,
                    cutoff_supported: true,
                }}
            />,
        );
        expect(screen.getByLabelText('Minutes before the start')).toHaveValue(
            1260,
        );
        fireEvent.change(screen.getByLabelText('Minutes before the start'), {
            target: { value: '' },
        });
        expect(screen.getByLabelText('Minutes before the start')).toBeVisible();
        fireEvent.change(screen.getByLabelText('Minutes before the start'), {
            target: { value: '120' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({
            registration_closes_before_minutes: 120,
        });
    });
    it('edits the Hub on the page, protects unsaved changes and saves the canonical private draft once', () => {
        render(
            <EventWizardDialog
                {...props}
                presentation="page"
                initial={{ ...initial, scope: 'all' }}
                publishing={{ can_publish: true, actor_id: 9 }}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Event title'), {
            target: { value: 'A clearer invitation' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Leave without these changes',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Event title')).toHaveValue(
            'A clearer invitation',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0]).toEqual(
            expect.arrayContaining([
                '/company-hub/event-drafts',
                expect.objectContaining({
                    mode: 'draft',
                    actor_id: 9,
                    title: 'A clearer invitation',
                    source_event_id: 4,
                    edit_version: 7,
                    scope: 'all',
                }),
            ]),
        );
    });
    it('previews the invitation and schedules only an explicit future New Zealand time', async () => {
        render(
            <EventWizardDialog
                {...props}
                presentation="page"
                initial={{ ...initial, scope: 'all' }}
                publishing={{
                    can_publish: true,
                    can_schedule: true,
                    actor_id: 9,
                }}
            />,
        );
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Review invitation' })[0],
        );
        expect(
            screen.getByRole('heading', { name: 'Team learning' }),
        ).toBeInTheDocument();
        await chooseEventOption('Publication', 'Schedule for later');
        fireEvent.click(screen.getByRole('button', { name: 'Schedule event' }));
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Publish date and time date: Choose date',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Next month' }),
        );
        const nextMonth = new Date(
            new Date().getFullYear(),
            new Date().getMonth() + 1,
            1,
        );
        const dayLabel = nextMonth
            .toLocaleDateString('en-NZ', {
                weekday: 'short',
                day: 'numeric',
                month: 'long',
                year: 'numeric',
            })
            .replace(',', '');
        fireEvent.click(
            screen.getByRole('button', { name: new RegExp(`^${dayLabel}`) }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Use date' }));
        fireEvent.click(screen.getByRole('button', { name: 'Schedule event' }));
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Publish date and time time: Choose time',
            }),
        );
        fireEvent.change(
            screen.getByLabelText('Publish date and time time hour'),
            { target: { value: '10' } },
        );
        fireEvent.change(
            screen.getByLabelText('Publish date and time time minute'),
            { target: { value: '00' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'AM' }));
        fireEvent.click(screen.getByRole('button', { name: 'Use time' }));
        fireEvent.click(screen.getByRole('button', { name: 'Schedule event' }));
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({
            mode: 'schedule',
            publish_at: eventTimeInstant(
                `${nextMonth.getFullYear()}-${String(nextMonth.getMonth() + 1).padStart(2, '0')}-01T10:00`,
                false,
            ),
            actor_id: 9,
        });
    });
    it.each(['page', 'dialog'] as const)(
        'uses confirmed Rory dates and clocks in the %s event editor',
        async (presentation) => {
            render(
                <EventWizardDialog
                    {...props}
                    presentation={presentation}
                    initial={{ ...initial, scope: 'all' }}
                    publishing={{ can_publish: true }}
                />,
            );
            if (presentation === 'dialog') continueTo(1);
            expect(
                document.querySelector(
                    'input[type="datetime-local"], input[type="date"], input[type="time"]',
                ),
            ).toBeNull();
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Starts date: 21 Oct 2026',
                }),
            );
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Thu 22 October 2026',
                }),
            );
            fireEvent.click(screen.getByRole('button', { name: 'Use date' }));
            expect(
                screen.getByRole('button', {
                    name: 'Starts date: 22 Oct 2026',
                }),
            ).toBeVisible();
            expect(
                screen.getByRole('button', { name: 'Starts time: 10:00 AM' }),
            ).toBeVisible();
            fireEvent.click(
                screen.getByRole('checkbox', { name: 'All-day event' }),
            );
            expect(
                screen.getByRole('button', { name: 'Starts: 22 Oct 2026' }),
            ).toBeVisible();
            expect(
                screen.queryByRole('button', { name: /Starts time:/ }),
            ).not.toBeInTheDocument();
            const untilLabel =
                presentation === 'page' ? 'Repeat until' : 'Until (optional)';
            fireEvent.click(
                screen.getByRole('button', {
                    name: `${untilLabel}: Choose date`,
                }),
            );
            expect(
                await screen.findByRole('button', { name: 'Use date' }),
            ).toBeDisabled();
        },
    );
    it('keeps inherited series files and audience locked in the on-page occurrence editor', () => {
        render(
            <EventWizardDialog
                {...props}
                presentation="page"
                initial={initial}
                publishing={{ can_publish: false }}
            />,
        );
        expect(
            screen.getByRole('combobox', { name: 'Repeats' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('combobox', {
                name: 'Who is invited?',
            }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Cover & files' }));
        expect(screen.getByText('Shared by the series')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Remove series.txt' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Review invitation' })[0],
        );
        expect(
            screen.queryByRole('button', { name: 'Publish event' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Send for review' }),
        ).toBeEnabled();
    });
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
                publishing={{
                    draft_id: 12,
                    version: 3,
                    can_publish: false,
                    cutoff_supported: true,
                }}
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
        expect(vi.mocked(router.post).mock.calls[0][1]).not.toHaveProperty(
            'registration_closes_before_minutes',
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
            options?.onFinish?.({} as never);
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'This event changed',
        );
        expect(props.onSaved).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(props.onSaved).toHaveBeenCalledOnce();
    });
});
