import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { hubRequest } from './_api';
import { PeopleDirectory } from './_people';
import { PersonProfile } from './_person-profile';
import type { HubPageSummary, HubPerson } from './_types';

vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { reload: vi.fn() },
}));
vi.mock('./_api', () => ({ hubRequest: vi.fn() }));
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

describe('shared work profiles', () => {
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
