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

    it('renders service management setup with workload and contextual create actions', async () => {
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
                sites={[]}
                apiIdentities={[]}
                oneTimeApiCredential={null}
                provisioningTemplates={[]}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Teams, queues & services' }),
        ).toBeVisible();
        expect(screen.getByText('Network operations')).toBeVisible();
        expect(screen.getAllByText('4 open')[0]).toBeVisible();
        expect(screen.getByRole('button', { name: /New team/i })).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /New queue/i }),
        ).not.toBeInTheDocument();

        const { fireEvent } = await import('@testing-library/react');
        fireEvent.click(screen.getByRole('tab', { name: 'Queues' }));
        expect(
            screen.getByRole('button', { name: /New queue/i }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /New team/i }),
        ).not.toBeInTheDocument();
    });

    it('shows scoped API identity metadata and a one-time credential in setup', () => {
        render(
            <ItSetupIndex
                teams={[]}
                queues={[]}
                services={[]}
                agents={[{ id: 8, name: 'Integration agent' }]}
                sites={[{ id: 3, name: 'Central House' }]}
                apiIdentities={[
                    {
                        id: 12,
                        public_id: 'abc123publicid',
                        name: 'Native monitoring',
                        description: 'Approved event intake.',
                        actor: { id: 8, name: 'Integration agent' },
                        creator: { id: 7, name: 'IT manager' },
                        abilities: ['work:create', 'work:read'],
                        allowed_work_types: ['incident'],
                        allowed_site_ids: [3],
                        allowed_fields: {
                            create: ['title', 'category'],
                            read: [],
                        },
                        require_signature: true,
                        rate_limit_per_minute: 60,
                        expires_at: null,
                        revoked_at: null,
                        last_used_at: null,
                        created_at: '2026-07-19T12:00:00Z',
                        is_active: true,
                    },
                ]}
                oneTimeApiCredential={{
                    identity_id: 12,
                    name: 'Native monitoring',
                    token: 'ofi_public_secret-shown-once',
                }}
                provisioningTemplates={[]}
            />,
        );

        expect(
            screen.getByRole('tab', { name: 'API identities' }),
        ).toHaveAttribute('aria-selected', 'true');

        expect(
            screen.getByRole('heading', { name: 'API identities', level: 2 }),
        ).toBeVisible();
        expect(screen.getByText('Native monitoring')).toBeVisible();
        expect(screen.getByText('Signed')).toBeVisible();
        expect(screen.getByLabelText('One-time API credential')).toHaveValue(
            'ofi_public_secret-shown-once',
        );
        expect(screen.queryByText('token_hash')).not.toBeInTheDocument();
    });

    it('makes joiner mover and leaver templates understandable from setup', async () => {
        const { fireEvent } = await import('@testing-library/react');
        render(
            <ItSetupIndex
                teams={[]}
                queues={[]}
                services={[]}
                agents={[]}
                sites={[]}
                apiIdentities={[]}
                oneTimeApiCredential={null}
                provisioningTemplates={[
                    {
                        id: 4,
                        name: 'Clinical joiner',
                        description: 'Approved access for clinical staff.',
                        lifecycle_type: 'joiner',
                        position_role: 'Registered Nurse',
                        site_id: null,
                        site: null,
                        employment_type: null,
                        selection_priority: 50,
                        is_active: true,
                        tasks: [
                            {
                                task_key: 'healthcare',
                                title: 'Grant approved healthcare system access',
                                description: null,
                                category: 'healthcare_access',
                                action: 'grant',
                                request_type: 'access',
                                responsible_team_id: null,
                                responsible_team: null,
                                stage: 2,
                                sort_order: 0,
                                dependency_task_keys: [],
                                trigger_fields: [],
                                approval_required: true,
                                evidence_required: true,
                                due_offset_days: 0,
                                fulfiller_fields: [
                                    'employee_number',
                                    'position_role',
                                ],
                            },
                        ],
                    },
                ]}
            />,
        );

        fireEvent.click(
            screen.getByRole('tab', { name: 'Provisioning workflows' }),
        );

        expect(
            screen.getByRole('heading', {
                name: 'Lifecycle workflow templates',
            }),
        ).toBeVisible();
        expect(screen.getByText('Clinical joiner')).toBeVisible();
        expect(
            screen.getByText('Grant approved healthcare system access'),
        ).toBeVisible();
        expect(screen.getByText('Role: Registered Nurse')).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'New template' }));
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('New lifecycle template');
        expect(dialog).toHaveTextContent('Workflow steps');
        expect(dialog).toHaveTextContent('Minimum employee details shown');
        expect(dialog).toHaveTextContent('Approval required');
        expect(dialog).toHaveTextContent('Evidence required');
    });

    it('shows configuration, email delivery, and existing scheduler health in one operations audit', async () => {
        const { fireEvent } = await import('@testing-library/react');
        render(
            <ItSetupIndex
                teams={[]}
                queues={[]}
                services={[]}
                agents={[]}
                sites={[]}
                apiIdentities={[]}
                oneTimeApiCredential={null}
                provisioningTemplates={[]}
                operationsAudit={{
                    teams: {
                        total: 2,
                        active: 2,
                        missing_manager: 1,
                        without_members: 0,
                    },
                    queues: {
                        total: 3,
                        active: 3,
                        missing_team: 0,
                        without_default_assignee: 1,
                    },
                    catalogue: { total: 4, published: 3, missing_service: 1 },
                    forms: { configured: 3, empty: 1 },
                    email: {
                        connections: 1,
                        connected: 1,
                        connection_errors: 0,
                        failed_or_bounced: 1,
                    },
                    api: {
                        identities: 2,
                        active: 1,
                        revoked: 1,
                        request_errors: 0,
                    },
                    slas: { custom_policies: 4, effective_priorities: 4 },
                    settings: {
                        inbound_status_callback: true,
                        outbound_status_callback: true,
                    },
                }}
                emailDeliveries={[
                    {
                        id: 14,
                        notification_uuid: 'delivery-14',
                        ticket: {
                            id: 9,
                            reference: 'IT-0009',
                            title: 'Cannot connect',
                        },
                        recipient: 'Alex Agent',
                        recipient_email: 'alex@example.test',
                        subject: 'Update on IT-0009',
                        status: 'bounced',
                        attempt_count: 1,
                        retry_count: 0,
                        last_error: 'Mailbox rejected the message.',
                        queued_at: '2026-07-19T10:00:00Z',
                        delivered_at: null,
                        can_retry: true,
                    },
                ]}
                automationDefinitions={[
                    {
                        key: 'it.check-sla',
                        label: 'SLA watchdog',
                        expression: '* * * * *',
                        timezone: 'Pacific/Auckland',
                        next_run_at: '2026-07-19T10:01:00Z',
                        without_overlapping: true,
                        on_one_server: true,
                        latest_status: 'succeeded',
                        latest_at: '2026-07-19T10:00:00Z',
                    },
                ]}
                automationRuns={[]}
            />,
        );

        fireEvent.click(screen.getByRole('tab', { name: 'Operations audit' }));

        expect(
            screen.getByRole('heading', { name: 'Configuration audit' }),
        ).toBeVisible();
        expect(
            screen.getByRole('heading', { name: 'Email delivery' }),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: /Retry delivery/ }),
        ).toBeVisible();
        expect(screen.getByText('SLA watchdog')).toBeVisible();
        expect(screen.getByText(/existing Laravel schedules/)).toBeVisible();
        expect(
            screen.getByText(/does not create a second scheduler/),
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
                                {
                                    key: 'employee_profile_id',
                                    label: 'Who needs this?',
                                    type: 'employee',
                                    required: true,
                                },
                            ],
                        },
                    },
                ]}
                fieldOptions={{
                    employee: [
                        {
                            id: 42,
                            name: 'Aroha Worker',
                            detail: 'Harbour House',
                        },
                    ],
                    user: [],
                    asset: [],
                }}
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
        expect(screen.getByLabelText(/Who needs this/)).toHaveValue('');
        expect(
            screen.getByRole('option', { name: /Aroha Worker.*Harbour House/ }),
        ).toBeVisible();
    });
});
