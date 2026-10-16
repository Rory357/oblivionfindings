import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { toast } from 'sonner';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { hubRequest } from './_api';
import { PeopleDirectory } from './_people';
import { PersonProfile } from './_person-profile';
import type { HubPageSummary, HubPerson } from './_types';

const navigationMock = vi.hoisted(() => ({
    visit: vi.fn(),
    before: null as
        | null
        | ((event: {
              preventDefault: () => void;
              detail: { visit: { method: string; url: URL } };
          }) => void),
}));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: {
        reload: vi.fn(),
        visit: navigationMock.visit,
        on: (_type: string, callback: typeof navigationMock.before) => {
            navigationMock.before = callback;
            return () => {
                navigationMock.before = null;
            };
        },
    },
}));
vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: vi.fn(),
}));
const person: HubPerson = {
    id: 4,
    user_id: 9,
    name: 'Casey Colleague',
    position: 'Coordinator',
    department: 'People',
    site: 'Main site',
    work_email: 'casey@company.test',
    work_phone: null,
    first_aider: false,
    fire_warden: false,
    can_edit_profile: true,
    shared_profile: {
        about: 'Helping new colleagues settle in.',
        help_with: ['Orientation'],
        version: 3,
        updated_at: null,
    },
};
beforeEach(() => vi.clearAllMocks());
afterEach(() => {
    cleanup();
    Reflect.deleteProperty(window, 'navigation');
});

describe('shared work profiles', () => {
    it('keeps the work email available when clipboard access is missing or denied', async () => {
        const original = Object.getOwnPropertyDescriptor(
            navigator,
            'clipboard',
        );
        const error = vi.spyOn(toast, 'error').mockReturnValue('copy-error');
        try {
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: undefined,
            });
            render(<PersonProfile initial={person} people={[person]} />);
            fireEvent.click(screen.getByRole('button', { name: 'Copy email' }));
            expect(error).toHaveBeenCalledWith(
                'Could not copy. Select the email address to copy it manually.',
            );
            expect(
                screen.getByRole('link', { name: person.work_email! }),
            ).toHaveAttribute('href', `mailto:${person.work_email}`);
            error.mockClear();
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: {
                    writeText: vi
                        .fn()
                        .mockRejectedValue(new Error('Permission denied')),
                },
            });
            fireEvent.click(screen.getByRole('button', { name: 'Copy email' }));
            await waitFor(() =>
                expect(error).toHaveBeenCalledWith(
                    'Could not copy. Select the email address to copy it manually.',
                ),
            );
        } finally {
            if (original)
                Object.defineProperty(navigator, 'clipboard', original);
            else Reflect.deleteProperty(navigator, 'clipboard');
            error.mockRestore();
        }
    });
    it('previews the current identity and pending skill in a side panel, then returns to editing without saving', () => {
        render(<PersonProfile initial={person} people={[person]} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        expect(
            screen.getByRole('dialog', { name: 'A little more you' }),
        ).toHaveAttribute('data-slot', 'sheet-content');
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: 'I help teams learn together.' },
        });
        fireEvent.change(
            screen.getByRole('textbox', { name: 'I can help with' }),
            { target: { value: ' Accessible documents ' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Preview' }));
        const panel = within(
            screen.getByRole('dialog', { name: 'Preview your profile' }),
        );
        expect(panel.getByRole('heading', { name: person.name })).toBeVisible();
        expect(
            panel.getByText('Coordinator · People · Main site'),
        ).toBeVisible();
        expect(panel.getByText('I help teams learn together.')).toBeVisible();
        expect(panel.getByText('Accessible documents')).toBeVisible();
        expect(panel.queryByRole('textbox')).not.toBeInTheDocument();
        expect(hubRequest).not.toHaveBeenCalled();
        fireEvent.click(panel.getByRole('button', { name: 'Back to editing' }));
        expect(screen.getByRole('textbox', { name: 'About me' })).toHaveValue(
            'I help teams learn together.',
        );
        expect(
            screen.getByRole('textbox', { name: 'I can help with' }),
        ).toHaveValue('');
        expect(
            screen.getByRole('button', { name: 'Remove Accessible documents' }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Preview' }));
        fireEvent.keyDown(
            screen.getByRole('dialog', { name: 'Preview your profile' }),
            { key: 'Escape' },
        );
        expect(
            screen.getByRole('dialog', { name: 'Discard profile changes?' }),
        ).toBeVisible();
    });

    it('validates pending topics before preview and displays the colleague empty state when sharing nothing', () => {
        render(<PersonProfile initial={person} people={[person]} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'I can help with' }),
            { target: { value: 'x' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Preview' }));
        expect(screen.getByRole('alert')).toHaveTextContent('between 2 and 60');
        expect(
            screen.getByRole('textbox', { name: 'I can help with' }),
        ).toHaveValue('x');
        fireEvent.change(
            screen.getByRole('textbox', { name: 'I can help with' }),
            { target: { value: '' } },
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: '' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Orientation' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Preview' }));
        expect(
            screen.getByText(
                'This colleague hasn’t added an introduction yet.',
            ),
        ).toBeVisible();
        expect(
            screen.getByText('This colleague hasn’t added skills yet.'),
        ).toBeVisible();
        expect(hubRequest).not.toHaveBeenCalled();
    });

    it('saves from preview once with the version captured when editing began', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(hubRequest).mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const view = render(
            <PersonProfile initial={person} people={[person]} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: 'My local writing.' },
        });
        view.rerender(
            <PersonProfile
                initial={{
                    ...person,
                    shared_profile: {
                        ...person.shared_profile!,
                        version: 4,
                        about: 'Saved by another window.',
                    },
                }}
                people={[person]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Preview' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save shared profile' }),
        );
        fireEvent.submit(
            screen.getByRole('button', { name: 'Saving…' }).closest('form')!,
        );
        expect(hubRequest).toHaveBeenCalledOnce();
        expect(hubRequest).toHaveBeenCalledWith(
            '/company-hub/people/4/profile',
            'PUT',
            {
                expected_version: 3,
                about: 'My local writing.',
                help_with: ['Orientation'],
            },
        );
        expect(
            screen.getByRole('button', { name: 'Back to editing' }),
        ).toBeDisabled();
        await act(async () =>
            finish({
                person: {
                    ...person,
                    id: 999,
                    shared_profile: { ...person.shared_profile!, version: 4 },
                },
            }),
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'could not be confirmed',
        );
        expect(
            screen.getByRole('button', { name: 'Save shared profile' }),
        ).toBeDisabled();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(screen.getByText('My local writing.')).toBeVisible();
    });

    it('does not carry writing into a different editable profile without remounting the whole page', () => {
        const view = render(
            <PersonProfile initial={person} people={[person]} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: 'Private unfinished writing.' },
        });
        view.rerender(
            <PersonProfile
                initial={{
                    ...person,
                    id: 8,
                    user_id: 15,
                    name: 'New account',
                    shared_profile: {
                        about: '',
                        help_with: [],
                        version: 0,
                        updated_at: null,
                    },
                }}
                people={[]}
            />,
        );
        expect(screen.getByRole('textbox', { name: 'About me' })).toHaveValue(
            '',
        );
        expect(
            screen.queryByText('Private unfinished writing.'),
        ).not.toBeInTheDocument();
    });
    it('protects unsaved introduction and skills from native browser Back and reload', () => {
        const navigation = Object.assign(new EventTarget(), {
            currentEntry: { key: 'profile' },
            traverseTo: vi.fn(() => ({ finished: Promise.resolve() })),
        });
        Object.defineProperty(window, 'navigation', {
            configurable: true,
            value: navigation,
        });
        const back = () => {
            const event = Object.assign(
                new Event('navigate', { cancelable: true }),
                {
                    navigationType: 'traverse',
                    destination: { key: 'directory', sameDocument: true },
                },
            );
            act(() => {
                navigation.dispatchEvent(event);
            });
            return event;
        };
        render(<PersonProfile initial={person} people={[person]} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: 'My unsaved introduction' },
        });
        expect(back().defaultPrevented).toBe(true);
        expect(
            screen.getByRole('alertdialog', {
                name: 'Discard profile changes?',
            }),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('alertdialog').querySelector('button')!,
        );
        expect(screen.getByRole('textbox', { name: 'About me' })).toHaveValue(
            'My unsaved introduction',
        );
        expect(navigation.traverseTo).not.toHaveBeenCalled();
        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(true);
        back();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(navigation.traverseTo).toHaveBeenCalledExactlyOnceWith(
            'directory',
        );
        expect(hubRequest).not.toHaveBeenCalled();
    });

    it('requires an explicit discard before continuing a Hub link with unsaved profile work', () => {
        render(<PersonProfile initial={person} people={[person]} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: 'Unsaved introduction' },
        });
        const event = {
            preventDefault: vi.fn(),
            detail: {
                visit: {
                    method: 'get',
                    url: new URL(
                        '/company-hub?view=people',
                        window.location.origin,
                    ),
                },
            },
        };
        act(() => {
            navigationMock.before?.(event);
        });
        expect(event.preventDefault).toHaveBeenCalled();
        expect(navigationMock.visit).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(navigationMock.visit).toHaveBeenCalledWith(
            event.detail.visit.url,
            event.detail.visit,
        );
    });

    it('applies a fresh server permission and work-detail projection while the old editor is open', () => {
        const { rerender } = render(
            <PersonProfile initial={person} people={[person]} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: 'Old account draft' },
        });
        rerender(
            <PersonProfile
                initial={{
                    ...person,
                    position: 'Updated role',
                    can_edit_profile: false,
                }}
                people={[person]}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Edit shared profile' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Updated role · People')).toBeInTheDocument();
    });
    it('links only the colleague’s published team spaces and uses the permitted staff-record destination', () => {
        const pages = [
            {
                id: 1,
                kind: 'department',
                team_ids: [10],
                slug: 'people-team',
                title: 'People team',
                summary: 'Meet our team',
            },
            {
                id: 2,
                kind: 'department',
                team_ids: [11],
                slug: 'different-team',
                title: 'Different team',
            },
            { id: 3, kind: 'news', team_ids: [10], title: 'Team news' },
        ] as HubPageSummary[];
        render(
            <PersonProfile
                initial={{
                    ...person,
                    department_id: 10,
                    staff_record_url: '/hr/people/4',
                }}
                people={[person]}
                pages={pages}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'People team' }),
        ).toHaveAttribute('href', '/company-hub/read/people-team');
        expect(
            screen.getByRole('link', { name: 'Open staff record' }),
        ).toHaveAttribute('href', '/hr/people/4');
        expect(screen.queryByText('Different team')).not.toBeInTheDocument();
        expect(screen.queryByText('Team news')).not.toBeInTheDocument();
    });
    it('opens a readable profile URL from skill search and shows only the owner’s profile shortcut', () => {
        render(
            <PeopleDirectory
                people={[
                    person,
                    {
                        ...person,
                        id: 5,
                        name: 'Other colleague',
                        can_edit_profile: false,
                        shared_profile: undefined,
                    },
                ]}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'My shared profile' }),
        ).toHaveAttribute('href', '/company-hub/people/4');
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search colleagues' }),
            { target: { value: 'orientation' } },
        );
        expect(
            screen.getByRole('link', { name: /Casey Colleague/ }),
        ).toHaveAttribute('href', '/company-hub/people/4');
        expect(
            screen.queryByRole('link', { name: /Other colleague/ }),
        ).not.toBeInTheDocument();
    });

    it('shows work contacts and permitted reporting links without letting colleagues edit another person', () => {
        const recognise = vi.fn();
        render(
            <PersonProfile
                initial={{ ...person, can_edit_profile: false, manager_id: 5 }}
                people={[{ ...person, id: 5, name: 'Team leader' }]}
                canViewOrg
                onRecognise={recognise}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'casey@company.test' }),
        ).toHaveAttribute('href', 'mailto:casey@company.test');
        expect(
            screen.getByRole('link', { name: 'Team leader' }),
        ).toHaveAttribute('href', '/company-hub/people/5');
        expect(
            screen.queryByRole('button', { name: 'Edit shared profile' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Give a shout-out' }),
        );
        expect(recognise).toHaveBeenCalledWith(9);
    });

    it('saves pending skill text with the exact profile version and refreshes the displayed introduction', async () => {
        vi.mocked(hubRequest).mockResolvedValue({
            person: {
                ...person,
                shared_profile: {
                    ...person.shared_profile!,
                    about: 'My new introduction.',
                    help_with: ['Orientation', 'Accessible documents'],
                    version: 4,
                },
            },
        });
        render(<PersonProfile initial={person} people={[person]} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: 'My new introduction.' },
        });
        fireEvent.change(
            screen.getByRole('textbox', { name: 'I can help with' }),
            { target: { value: 'Accessible documents' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save shared profile' }),
        );
        await waitFor(() =>
            expect(hubRequest).toHaveBeenCalledWith(
                '/company-hub/people/4/profile',
                'PUT',
                {
                    expected_version: 3,
                    about: 'My new introduction.',
                    help_with: ['Orientation', 'Accessible documents'],
                },
            ),
        );
        expect(await screen.findByRole('status')).toHaveTextContent(
            'Your shared profile has been saved.',
        );
        expect(screen.getByText('My new introduction.')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: 'Next edit.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save shared profile' }),
        );
        await waitFor(() =>
            expect(hubRequest).toHaveBeenLastCalledWith(
                '/company-hub/people/4/profile',
                'PUT',
                expect.objectContaining({ expected_version: 4 }),
            ),
        );
    });

    it('retains an unsaved draft after a conflict and requires an explicit discard', async () => {
        vi.mocked(hubRequest).mockRejectedValue(
            new Error('Your shared profile changed in another window.'),
        );
        render(<PersonProfile initial={person} people={[person]} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: 'My unsaved text.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save shared profile' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'changed in another window',
        );
        expect(screen.getByRole('textbox', { name: 'About me' })).toHaveValue(
            'My unsaved text.',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('dialog', { name: 'Discard profile changes?' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByRole('textbox', { name: 'About me' })).toHaveValue(
            'My unsaved text.',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByText(person.shared_profile!.about),
        ).toBeInTheDocument();
    });

    it('does not apply a late response after navigating to another colleague', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(hubRequest).mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const { rerender } = render(
            <PersonProfile key="first" initial={person} people={[person]} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: 'Pending update.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save shared profile' }),
        );
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        rerender(
            <PersonProfile
                key="second"
                initial={{
                    ...person,
                    id: 6,
                    name: 'Another colleague',
                    can_edit_profile: false,
                    shared_profile: undefined,
                }}
                people={[]}
            />,
        );
        await act(async () => finish({ person }));
        expect(
            screen.getByRole('heading', { name: 'Another colleague' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('does not reveal reporting links without permission and supports clearing previously shared fields', async () => {
        vi.mocked(hubRequest).mockResolvedValue({
            person: {
                ...person,
                shared_profile: {
                    about: '',
                    help_with: [],
                    version: 4,
                    updated_at: null,
                },
            },
        });
        render(
            <PersonProfile
                initial={{ ...person, manager_id: 5 }}
                people={[{ ...person, id: 5, name: 'Hidden reporting line' }]}
            />,
        );
        expect(
            screen.queryByText('Hidden reporting line'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit shared profile' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'About me' }), {
            target: { value: '' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Orientation' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save shared profile' }),
        );
        await waitFor(() =>
            expect(hubRequest).toHaveBeenCalledWith(
                '/company-hub/people/4/profile',
                'PUT',
                { expected_version: 3, about: '', help_with: [] },
            ),
        );
        expect(
            await screen.findByText(
                'Help colleagues get to know you. Add a short introduction to your shared profile.',
            ),
        ).toBeInTheDocument();
    });
});
