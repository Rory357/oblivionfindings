import ItSetupIndex from '@/pages/it/setup';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ItServiceCatalogue } from '../it-service-catalogue';
import { ItSideNavigation } from '../it-side-navigation';

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { post: vi.fn(), patch: vi.fn() },
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
        processing: false,
        errors: {},
    }),
    usePage: () => ({ props: { itNavigation: navigation }, url: '/it/setup' }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

const navigation = [
    {
        label: 'Service Desk',
        items: [{ label: 'Overview', href: '/it', icon: 'layout-dashboard' }],
    },
    {
        label: 'Service Delivery',
        items: [
            {
                label: 'Service catalogue',
                href: '/it?tab=catalog',
                icon: 'book-open',
            },
            {
                label: 'Provisioning',
                href: '/it?tab=provisioning',
                icon: 'package-check',
            },
        ],
    },
    {
        label: 'Operations',
        items: [
            {
                label: 'Major incidents',
                href: '/it/major-incidents',
                icon: 'siren',
            },
        ],
    },
    {
        label: 'Setup',
        items: [
            {
                label: 'Teams, queues & services',
                href: '/it/setup',
                icon: 'settings-2',
            },
        ],
    },
];

describe('IT & Support grouped navigation', () => {
    it('shows the four understandable groups with icon and text links', () => {
        render(<ItSideNavigation groups={navigation} currentUrl="/it/setup" />);

        for (const group of [
            'Service Desk',
            'Service Delivery',
            'Operations',
            'Setup',
        ]) {
            expect(screen.getByText(group)).toBeVisible();
        }
        expect(screen.getByRole('link', { name: /Overview/ })).toHaveAttribute(
            'href',
            '/it',
        );
        expect(
            screen.getByRole('link', { name: /Teams, queues & services/ }),
        ).toHaveAttribute('aria-current', 'page');
        expect(
            screen.getByRole('link', { name: /Service catalogue/ }),
        ).toHaveAttribute('href', '/it?tab=catalog');
    });

    it('keeps the correct workspace selected when table filters reorder the query string', () => {
        render(
            <ItSideNavigation
                groups={navigation}
                currentUrl="/it?status=pending&tab=provisioning"
            />,
        );

        expect(
            screen.getByRole('link', { name: /Provisioning/ }),
        ).toHaveAttribute('aria-current', 'page');
    });

    it('renders service management setup with workload and clear create actions', () => {
        render(
            <ItSetupIndex
                teams={[
                    {
                        id: 1,
                        name: 'Network operations',
                        description: null,
                        is_active: true,
                        manager: null,
                        members: [],
                        workload: {
                            open_tickets: 4,
                            open_tasks: 2,
                            queues: 1,
                            members: 2,
                        },
                    },
                ]}
                queues={[
                    {
                        id: 2,
                        key: 'network',
                        name: 'Network queue',
                        description: null,
                        is_active: true,
                        team: { id: 1, name: 'Network operations' },
                        filter_rules: {},
                        workload: {
                            open_tickets: 4,
                            unassigned: 1,
                            sla_risk: 1,
                        },
                    },
                ]}
                services={[
                    {
                        id: 3,
                        key: 'identity',
                        name: 'Identity and access',
                        description: null,
                        is_active: true,
                        status: 'operational',
                        criticality: 'critical',
                        owner: null,
                        workload: { open_tickets: 4, sla_risk: 1 },
                    },
                ]}
                agents={[]}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Teams, queues & services' }),
        ).toBeVisible();
        expect(screen.getByText('Network operations')).toBeVisible();
        expect(screen.getAllByText('4 open')[0]).toBeVisible();
        expect(screen.getByRole('button', { name: /New team/i })).toBeVisible();
        expect(
            screen.getByRole('button', { name: /New queue/i }),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: /New service/i }),
        ).toBeVisible();
    });

    it('turns the service catalogue into a searchable human workspace', async () => {
        const { fireEvent } = await import('@testing-library/react');
        render(
            <ItServiceCatalogue
                items={[
                    {
                        id: 9,
                        name: 'Request VPN access',
                        slug: 'request-vpn-access',
                        description: 'Secure remote access for your role.',
                        outcome_type: 'service_request',
                        category: 'account',
                        default_priority: 'normal',
                        requires_approval: true,
                        form_schema_version: 2,
                        form_schema: {
                            fields: [
                                {
                                    key: 'details',
                                    label: 'What do you need?',
                                    type: 'textarea',
                                    required: true,
                                },
                            ],
                        },
                    },
                ]}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Service catalogue' }),
        ).toBeVisible();
        expect(
            screen.getByText('Secure remote access for your role.'),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Request VPN access' }),
        );
        expect(
            screen.getByRole('heading', { name: 'Request VPN access' }),
        ).toBeVisible();
        expect(screen.getByLabelText(/What do you need/)).toBeVisible();
    });
});
