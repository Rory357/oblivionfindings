import { fireEvent, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { HubSearchResults } from './_search';
import { ServicesWorkspace } from './_services';
import type { HubEvent, HubProps } from './_types';

vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
}));
vi.mock('@/components/it/it-service-catalogue', () => ({
    ItServiceCatalogue: ({
        query,
        draftRecoveryEnabled,
    }: {
        query: string;
        draftRecoveryEnabled?: boolean;
    }) => (
        <>
            <p>Catalogue query: {query}</p>
            <p>Saved drafts: {draftRecoveryEnabled ? 'available' : 'off'}</p>
        </>
    ),
}));
vi.mock('@/components/it/ticket-drawer', () => ({ TicketDrawer: () => null }));
const event = {
    id: 'event-4-date',
    title: 'Compass gathering',
    start: '2026-10-21T10:00:00+13:00',
    extendedProps: {
        description: 'Meet colleagues',
        location: 'Support office',
    },
} as HubEvent;
const data = {
    search: 'Compass',
    pages: [
        {
            id: 1,
            slug: 'compass-guide',
            title: 'Compass guide',
            summary: 'How it works',
            category: 'Learning',
        },
    ],
    resourceItems: [
        {
            resource_key: 'file:2:3',
            title: 'Compass handbook',
            summary: '',
            resource_type: 'File',
            resource_url: '/company-hub/media/3',
        },
    ],
    people: [
        {
            id: 2,
            name: 'Compass Colleague',
            position: 'Coordinator',
            site: 'Support office',
        },
    ],
    searchServices: [
        {
            id: 3,
            name: 'Compass equipment',
            description: 'Request help',
            category: 'equipment',
        },
    ],
    ideas: [
        {
            id: 4,
            title: 'Compass idea',
            body: 'Improve our work',
            category: 'Our people',
            status: 'open',
        },
    ],
    kudos: [
        {
            id: 5,
            to: 'Compass Colleague',
            from: 'Team member',
            category: 'Teamwork',
            message: 'Thank you for helping',
        },
    ],
    announcements: [
        {
            id: 6,
            title: 'Compass notice',
            content: '<p>Read this update</p>',
            requires_acknowledgement: true,
        },
    ],
    events: [event],
} as HubProps;

describe('Company Hub discovery', () => {
    it.each([false, true])(
        'uses the canonical saved-draft setting: %s',
        (enabled) => {
            render(
                <ServicesWorkspace
                    data={{
                        actorId: 1,
                        items: [
                            {
                                id: 1,
                                name: 'Equipment help',
                                slug: 'equipment',
                                description: '',
                                outcome_type: 'ticket',
                                category: 'equipment',
                                default_priority: 'normal',
                                requires_approval: false,
                                form_schema_version: 1,
                                form_schema: { fields: [] },
                            },
                        ],
                        fieldOptions: { asset: [], user: [], employee: [] },
                        requests: [],
                        draftRecoveryEnabled: enabled,
                    }}
                />,
            );
            expect(
                screen.getByText(
                    `Saved drafts: ${enabled ? 'available' : 'off'}`,
                ),
            ).toBeInTheDocument();
        },
    );
    it('groups all authorised result types and opens the exact event without acknowledging a notice', () => {
        const onEvent = vi.fn();
        render(<HubSearchResults data={data} onEvent={onEvent} />);
        expect(
            screen.getByRole('button', { name: 'All results 8' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            screen.getByRole('link', { name: /Compass guide/ }),
        ).toHaveAttribute('href', '/company-hub/read/compass-guide');
        expect(
            screen.getByRole('link', { name: /Compass equipment/ }),
        ).toHaveAttribute(
            'href',
            '/company-hub?view=services&search=Compass%20equipment',
        );
        expect(
            screen.getByRole('link', { name: /Compass notice/ }),
        ).toHaveAttribute('href', '/company-hub?view=updates&announcement=6');
        fireEvent.click(
            screen.getByRole('button', { name: /Compass gathering/ }),
        );
        expect(onEvent).toHaveBeenCalledWith(event);
        fireEvent.click(screen.getByRole('button', { name: 'People 1' }));
        expect(
            screen.queryByRole('region', { name: 'Services results' }),
        ).not.toBeInTheDocument();
        expect(
            within(
                screen.getByRole('region', { name: 'People results' }),
            ).getByRole('link'),
        ).toHaveAttribute('href', '/company-hub/people/2');
        fireEvent.click(screen.getByRole('button', { name: 'All results 8' }));
        expect(
            screen.getByRole('region', { name: 'Services results' }),
        ).toBeInTheDocument();
    });

    it('shows additional matches without losing the current search and explains an empty result', () => {
        const many = {
            ...data,
            searchServices: Array.from({ length: 25 }, (_, i) => ({
                id: i,
                name: `Service ${i}`,
                category: 'other',
                description: '',
            })),
        };
        const { rerender } = render(
            <HubSearchResults data={many} onEvent={vi.fn()} />,
        );
        expect(
            within(
                screen.getByRole('region', { name: 'Services results' }),
            ).getAllByRole('link'),
        ).toHaveLength(4);
        fireEvent.click(screen.getByRole('button', { name: 'See all 25' }));
        expect(
            within(
                screen.getByRole('region', { name: 'Services results' }),
            ).getAllByRole('link'),
        ).toHaveLength(20);
        fireEvent.click(
            screen.getByRole('button', { name: 'Show more results' }),
        );
        expect(
            within(
                screen.getByRole('region', { name: 'Services results' }),
            ).getAllByRole('link'),
        ).toHaveLength(25);
        rerender(
            <HubSearchResults
                key="empty"
                data={{
                    ...data,
                    pages: [],
                    people: [],
                    resourceItems: [],
                    searchServices: [],
                    ideas: [],
                    events: [],
                    kudos: [],
                    announcements: [],
                }}
                onEvent={vi.fn()}
            />,
        );
        expect(screen.getByText('No matching results yet')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Back to the hub' }),
        ).toHaveAttribute('href', '/company-hub?view=home');
    });

    it('carries a global service result into the canonical service catalogue search', () => {
        render(
            <ServicesWorkspace
                data={{
                    actorId: 1,
                    items: ['Compass equipment', 'Another service'].map(
                        (name, id) => ({
                            id,
                            name,
                            slug: String(id),
                            description: '',
                            outcome_type: 'ticket',
                            category: 'equipment',
                            default_priority: 'normal',
                            requires_approval: false,
                            form_schema_version: 1,
                            form_schema: { fields: [] },
                        }),
                    ),
                    fieldOptions: { asset: [], user: [], employee: [] },
                    requests: [],
                }}
                initialSearch="Compass equipment"
            />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Search services' }),
        ).toHaveValue('Compass equipment');
        expect(
            screen.getByText('Catalogue query: Compass equipment'),
        ).toBeInTheDocument();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search services' }),
            { target: { value: 'Another service' } },
        );
        expect(
            screen.getByText('Catalogue query: Another service'),
        ).toBeInTheDocument();
    });

    it('opens staff workflows from service search and shows only supplied work contacts', () => {
        render(
            <ServicesWorkspace
                data={{
                    actorId: 1,
                    items: [],
                    fieldOptions: { asset: [], user: [], employee: [] },
                    requests: [],
                    handoffs: [
                        {
                            id: 'leave',
                            name: 'Plan and request leave',
                            description: 'Check your balance',
                            category: 'People & Culture',
                            icon: 'leave',
                            url: '/hr/my/leave',
                        },
                        {
                            id: 'people',
                            name: 'Ask People & Culture',
                            description: 'Find a work contact',
                            category: 'People & Culture',
                            icon: 'people',
                            url: null,
                        },
                        {
                            id: 'learning',
                            name: 'Explore learning',
                            description: 'Your development',
                            category: 'Learning & development',
                            icon: 'learning',
                            url: '/hr/my/training',
                        },
                    ],
                    peopleContacts: [
                        {
                            id: 2,
                            user_id: 2,
                            name: 'Casey People',
                            position: 'People coordinator',
                            department: 'HR',
                            site: 'Office',
                            work_email: 'casey@company.test',
                            work_phone: null,
                            first_aider: false,
                            fire_warden: false,
                        },
                    ],
                }}
            />,
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search services' }),
            { target: { value: 'leave' } },
        );
        expect(
            screen.getByRole('link', { name: /Plan and request leave/ }),
        ).toHaveAttribute('href', '/hr/my/leave');
        expect(
            screen.queryByRole('link', { name: /Explore learning/ }),
        ).not.toBeInTheDocument();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search services' }),
            { target: { value: '' } },
        );
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Service category' }),
            { target: { value: 'staff:People & Culture' } },
        );
        expect(
            screen.queryByRole('link', { name: /Explore learning/ }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /Ask People & Culture/ }),
        );
        const dialog = screen.getByRole('dialog', {
            name: 'People & Culture contacts',
        });
        expect(
            within(dialog).getByRole('link', { name: 'Email Casey People' }),
        ).toHaveAttribute('href', 'mailto:casey%40company.test');
        expect(
            within(dialog).getByRole('link', { name: /People directory/ }),
        ).toHaveAttribute('href', '/company-hub?view=people');
    });

    it('routes global staff-service results to the existing self-service destination', () => {
        render(
            <HubSearchResults
                data={{
                    ...data,
                    searchServices: [
                        {
                            id: 'staff:leave',
                            name: 'Plan and request leave',
                            description: 'Check your balance',
                            category: 'People & Culture',
                            url: '/hr/my/leave',
                        },
                    ],
                }}
                onEvent={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('link', { name: /Plan and request leave/ }),
        ).toHaveAttribute('href', '/hr/my/leave');
    });
});
