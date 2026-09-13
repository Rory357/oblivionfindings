import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ItSetupIndex from './index';
const state = vi.hoisted(() => ({ url: '/it/setup?tab=teams' }));
vi.mock('@inertiajs/react', async (original) => ({
    ...(await original<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ props: {}, url: state.url }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/it/it-module-shell', () => ({
    ItModuleShell: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
const props: ComponentProps<typeof ItSetupIndex> = {
    teams: [
        {
            id: 1,
            name: 'Network team',
            description: 'Network access',
            is_active: true,
            manager: { id: 10, name: 'Desk manager' },
            members: [{ id: 10, name: 'Desk manager', role: 'manager' }],
            workload: { open_tickets: 3, open_tasks: 2, queues: 1, members: 1 },
        },
        {
            id: 2,
            name: 'Archived team',
            description: null,
            is_active: false,
            manager: null,
            members: [],
            workload: { open_tickets: 0, open_tasks: 0, queues: 0, members: 0 },
        },
    ],
    queues: [
        {
            id: 3,
            key: 'gap-queue',
            name: 'Queue awaiting cover',
            description: null,
            configuration_version: 'a'.repeat(64),
            is_active: true,
            team: { id: 1, name: 'Network team' },
            filter_rules: {},
            readiness: {
                ready: false,
                gaps: ['No cover configured.'],
                accountable_owner: { id: 10, name: 'Desk manager' },
                cover: null,
            },
            workload: { open_tickets: 2, unassigned: 1, sla_risk: 1 },
        },
    ],
    services: [
        {
            id: 4,
            key: 'identity',
            name: 'Identity access',
            description: null,
            is_active: true,
            status: 'operational',
            criticality: 'high',
            owner: { id: 10, name: 'Desk manager' },
            workload: { open_tickets: 3, sla_risk: 0 },
        },
    ],
    agents: [
        {
            id: 10,
            name: 'Desk manager',
            site_ids: [100],
            organisation_wide: false,
        },
    ],
    sites: [{ id: 100, name: 'Site A' }],
    apiIdentities: [],
    oneTimeApiCredential: null,
    provisioningTemplates: [],
};
describe('Setup workspace presentation', () => {
    it('keeps the automation date period when searching or clearing search and starts at the first history page', () => {
        vi.useFakeTimers();
        const get = vi.spyOn(router, 'get').mockImplementation(() => {});
        state.url =
            '/it/setup?tab=operations&automation_from=2026-09-01&automation_to=2026-09-11&automation_page=2';
        render(<ItSetupIndex {...props} />);
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'Mailbox scan pending' },
        });
        act(() => vi.advanceTimersByTime(350));
        expect(get).toHaveBeenLastCalledWith(
            '/it/setup',
            expect.objectContaining({
                tab: 'operations',
                q: 'Mailbox scan pending',
                automation_from: '2026-09-01',
                automation_to: '2026-09-11',
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(get.mock.calls[0][1]).not.toHaveProperty('automation_page');
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: '' },
        });
        act(() => vi.advanceTimersByTime(350));
        expect(get.mock.calls[1][1]).toMatchObject({
            automation_from: '2026-09-01',
            automation_to: '2026-09-11',
        });
        expect(get.mock.calls[1][1]).not.toHaveProperty('q');
    });
    it('keeps recovery fragments out of the selected tab', () => {
        state.url = '/it/setup?tab=operations#deliveries';
        render(<ItSetupIndex {...props} />);
        expect(screen.getByRole('tab', { name: 'Operations' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByRole('tab', { name: 'Teams' })).toHaveAttribute(
            'aria-selected',
            'false',
        );
    });
    afterEach(() => {
        state.url = '/it/setup?tab=teams';
        vi.restoreAllMocks();
        vi.useRealTimers();
    });
    it('keeps old reply history scoped, counts visible page matches and offers bounded canonical pagination', () => {
        state.url =
            '/it/setup?tab=operations&delivery_comment_id=17&q=failed#deliveries';
        const delivery = {
            id: 1,
            notification_uuid: 'synthetic-1',
            ticket: {
                id: 4,
                reference: 'IT-000004',
                title: 'Synthetic request',
            },
            recipient: 'Synthetic requester',
            recipient_email: 'synthetic@demo.test',
            subject: 'Ticket reply',
            status: 'failed',
            attempt_count: 1,
            retry_count: 0,
            last_error: null,
            queued_at: null,
            delivered_at: null,
            can_retry: false,
        };
        const audit = {
            teams: {
                total: 0,
                active: 0,
                missing_manager: 0,
                without_members: 0,
            },
            queues: {
                total: 0,
                active: 0,
                missing_team: 0,
                without_default_assignee: 0,
            },
            catalogue: { total: 0, published: 0, missing_service: 0 },
            forms: { configured: 0, empty: 0 },
            email: {
                connections: 0,
                connected: 0,
                connection_errors: 0,
                failed_or_bounced: 1,
            },
            api: { identities: 0, active: 0, revoked: 0, request_errors: 0 },
            slas: { custom_policies: 0, effective_priorities: 0 },
            settings: {
                inbound_status_callback: false,
                outbound_status_callback: false,
            },
        };
        const filter = {
            comment_id: 17,
            ticket_reference: 'IT-000004',
            total: 51,
            page: 1,
            last_page: 2,
            shown: 2,
        };
        const result = render(
            <ItSetupIndex
                {...props}
                operationsAudit={audit}
                emailDeliveryFilter={filter}
                emailDeliveries={[
                    delivery,
                    { ...delivery, id: 2, status: 'accepted' },
                ]}
            />,
        );
        const history = screen.getByRole('region', {
            name: 'Reply delivery history',
        });
        expect(history).toHaveTextContent(
            'Showing 1 on this page · 51 attempts in total.',
        );
        expect(
            within(history).queryByRole('link', { name: 'Previous attempts' }),
        ).not.toBeInTheDocument();
        expect(
            within(history).getByRole('link', { name: 'Next attempts' }),
        ).toHaveAttribute(
            'href',
            '/it/setup?tab=operations&delivery_comment_id=17&delivery_page=2&q=failed',
        );
        result.rerender(
            <ItSetupIndex
                {...props}
                operationsAudit={audit}
                emailDeliveryFilter={{ ...filter, page: 2, shown: 1 }}
                emailDeliveries={[delivery]}
            />,
        );
        expect(
            within(history).queryByRole('link', { name: 'Next attempts' }),
        ).not.toBeInTheDocument();
        expect(
            within(history).getByRole('link', { name: 'Previous attempts' }),
        ).toHaveAttribute(
            'href',
            '/it/setup?tab=operations&delivery_comment_id=17&delivery_page=1&q=failed',
        );
        expect(
            within(history).getByRole('link', {
                name: 'View all delivery activity',
            }),
        ).toHaveAttribute('href', '/it/setup?tab=operations');
        vi.useFakeTimers();
        const get = vi.spyOn(router, 'get').mockImplementation(() => {});
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'accepted' },
        });
        act(() => vi.advanceTimersByTime(350));
        expect(get).toHaveBeenCalledWith(
            '/it/setup',
            expect.objectContaining({
                tab: 'operations',
                delivery_comment_id: 17,
                delivery_page: 2,
                q: 'accepted',
            }),
            expect.anything(),
        );
    });
    it('uses one connected header and filters the actual list while keeping scoped meters factual', () => {
        render(<ItSetupIndex {...props} />);
        expect(screen.getAllByRole('banner')).toHaveLength(1);
        expect(
            screen.getByRole('heading', { name: 'IT setup', level: 1 }),
        ).toBeVisible();
        expect(screen.getByText('2 of 2 shown')).toBeVisible();
        fireEvent.change(screen.getByPlaceholderText('Search teams'), {
            target: { value: 'Network' },
        });
        expect(screen.getByText('1 of 2 shown')).toBeVisible();
        expect(
            screen.queryByRole('heading', { name: 'Archived team' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /active teams/ }),
        ).toHaveTextContent('1 of 2 teams');
        expect(
            screen
                .getByRole('tablist', { name: 'Service management setup' })
                .closest('header'),
        ).toBe(screen.getByRole('banner'));
    });
    it('links meters and rail to real filtered views using history-preserving navigation', () => {
        const get = vi.spyOn(router, 'get').mockImplementation(() => {});
        render(<ItSetupIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /routing gaps/ }));
        expect(get.mock.calls[0]).toEqual([
            '/it/setup',
            { tab: 'queues', state: 'attention', list_view: 'cards' },
            { preserveState: true, preserveScroll: true },
        ]);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(get.mock.calls[1][1]).toEqual({
            tab: 'teams',
            list_view: 'table',
        });
    });

    it('shows empty counts without an undefined zero-of-zero percentage', () => {
        render(
            <ItSetupIndex {...props} teams={[]} queues={[]} services={[]} />,
        );
        expect(screen.queryByText('0%')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'View active teams' }),
        ).toHaveTextContent('No teams configured');
        expect(
            screen.getByRole('button', { name: 'View active queues' }),
        ).toHaveTextContent('No queues configured');
        expect(
            screen.getByRole('button', { name: 'View active services' }),
        ).toHaveTextContent('No services configured');
    });

    it('preserves search across layout and filters and restores Back without a stale delayed search', () => {
        vi.useFakeTimers();
        const get = vi.spyOn(router, 'get').mockImplementation(() => {});
        state.url = '/it/setup?tab=teams&q=Network';
        const result = render(<ItSetupIndex {...props} />);
        expect(screen.getByPlaceholderText('Search teams')).toHaveValue(
            'Network',
        );
        fireEvent.change(screen.getByPlaceholderText('Search teams'), {
            target: { value: 'Desk' },
        });
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(get.mock.calls[0][1]).toEqual({
            tab: 'teams',
            list_view: 'table',
            q: 'Desk',
        });
        act(() => vi.advanceTimersByTime(400));
        expect(get).toHaveBeenCalledOnce();
        state.url =
            '/it/setup?tab=teams&state=active&list_view=table&q=Network';
        result.rerender(<ItSetupIndex {...props} />);
        expect(screen.getByPlaceholderText('Search teams')).toHaveValue(
            'Network',
        );
        fireEvent.change(screen.getByPlaceholderText('Search teams'), {
            target: { value: 'Unsent search' },
        });
        state.url = '/it/setup?tab=teams&q=Archived';
        result.rerender(<ItSetupIndex {...props} />);
        expect(screen.getByPlaceholderText('Search teams')).toHaveValue(
            'Archived',
        );
        act(() => vi.advanceTimersByTime(400));
        expect(get).toHaveBeenCalledOnce();
        fireEvent.click(
            screen.getByRole('button', { name: 'View active teams' }),
        );
        expect(get.mock.calls[1][1]).toEqual({
            tab: 'teams',
            state: 'active',
            list_view: 'cards',
        });
    });
    it('restores URL-selected table and active filters and keeps row context actions available', () => {
        state.url = '/it/setup?tab=teams&state=active&list_view=table';
        const result = render(<ItSetupIndex {...props} />);
        expect(screen.getByRole('radio', { name: 'Table' })).toHaveAttribute(
            'aria-checked',
            'true',
        );
        expect(screen.getByText('1 of 2 shown')).toBeVisible();
        expect(screen.getByRole('table')).toBeVisible();
        const row = screen.getByText('Network team').closest('[role="row"]')!;
        fireEvent.contextMenu(row, { clientX: 100, clientY: 200 });
        expect(
            within(screen.getByRole('menu')).getByRole('menuitem', {
                name: 'Edit',
            }),
        ).toBeVisible();
        state.url = '/it/setup?tab=queues&state=attention&list_view=cards';
        result.rerender(<ItSetupIndex {...props} />);
        expect(screen.getByRole('tab', { name: 'Queues' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByText('No cover configured.')).toBeVisible();
        expect(screen.getByText('1 of 1 shown')).toBeVisible();
    });
});
