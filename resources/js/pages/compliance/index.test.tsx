/* eslint-disable no-restricted-syntax -- Test doubles intentionally use native buttons to preserve click and pressed-state semantics. */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ComplianceIndex from './index';

const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    toastError: vi.fn(),
    toastSuccess: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: {
        get: mocks.get,
        post: mocks.post,
        reload: mocks.reload,
        visit: mocks.visit,
    },
}));

vi.mock('sonner', () => ({
    toast: {
        error: mocks.toastError,
        success: mocks.toastSuccess,
    },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

vi.mock('@/components/rostering', () => ({
    ShiftContextMenu: () => null,
}));

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuContent: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuItem: ({
        children,
        onClick,
    }: {
        children: ReactNode;
        onClick?: () => void;
    }) => (
        <button type="button" onClick={onClick}>
            {children}
        </button>
    ),
    DropdownMenuLabel: ({ children }: { children: ReactNode }) => (
        <span>{children}</span>
    ),
    DropdownMenuSeparator: () => <hr />,
    DropdownMenuTrigger: ({ children }: { children: ReactNode }) => (
        <>{children}</>
    ),
}));

vi.mock('@/pages/health-safety/components/hs-hero-kit', () => ({
    HeroCluster: ({
        title,
        children,
    }: {
        title: string;
        children: ReactNode;
    }) => (
        <section>
            <h2>{title}</h2>
            {children}
        </section>
    ),
    HeroClusterTile: ({
        label,
        value,
        caption,
    }: {
        label: string;
        value: string;
        caption: string;
    }) => (
        <div>
            {label}: {value} ({caption})
        </div>
    ),
    HeroComplianceBadges: ({ items }: { items: Array<{ label: string }> }) => (
        <div>{items.map((item) => item.label).join(' | ')}</div>
    ),
    HeroMedallion: () => null,
    HeroSegmented: ({
        ariaLabel,
        items,
        onChange,
        value,
    }: {
        ariaLabel: string;
        items: Array<{ key: string; label: string }>;
        onChange: (key: string) => void;
        value: string;
    }) => (
        <div aria-label={ariaLabel} role="group">
            {items.map((item) => (
                <button
                    key={item.key}
                    type="button"
                    aria-pressed={item.key === value}
                    onClick={() => onChange(item.key)}
                >
                    {item.label}
                </button>
            ))}
        </div>
    ),
    HeroShell: ({
        children,
        footer,
    }: {
        children: ReactNode;
        footer?: ReactNode;
    }) => (
        <section>
            {children}
            {footer}
        </section>
    ),
    HeroStatusPill: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    HeroSummaryMetric: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    HeroSummaryStrip: ({
        label,
        children,
    }: {
        label: string;
        children: ReactNode;
    }) => <section aria-label={label}>{children}</section>,
}));

vi.mock('@/pages/health-safety/analytics-charts', () => ({
    ChartCard: ({
        title,
        children,
    }: {
        title: string;
        children: ReactNode;
    }) => (
        <section aria-label={title}>
            <h2>{title}</h2>
            {children}
        </section>
    ),
    TOKEN: {
        axis: '#000',
        critical: '#000',
        grid: '#000',
        info: '#000',
        primary: '#000',
        success: '#000',
        warning: '#000',
    },
    severityFill: () => '#000',
}));

vi.mock('recharts', () => ({
    Area: () => null,
    AreaChart: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    Bar: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    BarChart: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    CartesianGrid: () => null,
    Cell: () => null,
    Line: () => null,
    LineChart: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    ResponsiveContainer: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    Tooltip: () => null,
    XAxis: () => null,
    YAxis: () => null,
}));

vi.mock('./wizards/log-obligation-dialog', () => ({
    LogObligationDialog: ({ open }: { open: boolean }) =>
        open ? <div data-testid="log-obligation-dialog" /> : null,
}));

vi.mock('./wizards/record-evidence-dialog', () => ({
    RecordEvidenceDialog: ({
        open,
        initialObligationId,
    }: {
        open: boolean;
        initialObligationId: number | null;
    }) =>
        open ? (
            <div data-testid="record-evidence-dialog">
                Obligation {initialObligationId ?? 'none'}
            </div>
        ) : null,
}));

vi.mock('./wizards/complete-obligation-dialog', () => ({
    CompleteObligationDialog: ({
        open,
        initialObligationId,
    }: {
        open: boolean;
        initialObligationId: number | null;
    }) =>
        open ? (
            <div data-testid="complete-obligation-dialog">
                Obligation {initialObligationId ?? 'none'}
            </div>
        ) : null,
}));

vi.mock('./wizards/log-notifiable-dialog', () => ({
    LogNotifiableDialog: ({ open }: { open: boolean }) =>
        open ? <div data-testid="log-notifiable-dialog" /> : null,
}));

type PageProps = ComponentProps<typeof ComplianceIndex>;

function pageProps(overrides: Partial<PageProps> = {}): PageProps {
    return {
        period: '30d',
        kpis: [
            {
                key: 'obligations',
                label: 'Overdue obligations',
                value: 1,
                caption: 'Needs action',
                href: '/governance/compliance?status=overdue',
                tone: 'critical',
                spark: [],
            },
            {
                key: 'break_glass',
                label: 'Break-glass access',
                value: 0,
                caption: 'None this period',
                href: '/audit-logs?action=break-glass',
                tone: 'success',
                spark: [],
            },
        ],
        whatsDue: {
            obligations: [
                {
                    id: 11,
                    type: 'obligation',
                    title: 'Site evacuation evidence',
                    framework: 'HSWA 2015',
                    reference: 'SITE-4',
                    priority: 'high',
                    due_date: '2026-08-03',
                    days: -1,
                    owner: 'Aroha Manager',
                    status: 'overdue',
                    evidence_provided: false,
                    href: '/governance/compliance/11',
                },
            ],
            reviews: [
                {
                    id: 22,
                    type: 'review',
                    title: 'Mere care-plan review',
                    framework: 'Care plan',
                    reference: 'CLIENT-22',
                    priority: 'normal',
                    due_date: '2026-08-08',
                    days: 4,
                    owner: 'Wiremu Worker',
                    status: 'due_soon',
                    evidence_provided: true,
                    client_id: 22,
                    href: '/clients/22?tab=care-support-plan',
                },
            ],
        },
        controlRoom: {
            open: 2,
            critical: 1,
            escalated: 1,
            recentAlerts: [
                {
                    id: 33,
                    alert_type: 'Site alarm offline',
                    severity: 'critical',
                    status: 'open',
                    source: 'Native monitoring',
                    triggered_at: '2026-08-04T08:00:00+12:00',
                },
            ],
            alertTrend: [],
        },
        charts: {
            incidentBySeverity: [],
            marTrend: [],
            cdTrend: [],
        },
        can: {
            manage: true,
            triage: true,
            viewControlRoom: true,
            viewAudit: true,
            viewReports: true,
        },
        frameworks: [{ value: 'hswa', label: 'HSWA 2015' }],
        owners: [{ id: 7, name: 'Aroha Manager' }],
        obligations: [
            {
                id: 11,
                title: 'Site evacuation evidence',
                framework: 'HSWA 2015',
                due_date: '2026-08-03',
            },
        ],
        relatedIncidents: [{ id: 9, label: 'INC-0009' }],
        ...overrides,
    };
}

beforeEach(() => {
    vi.clearAllMocks();
});

afterEach(() => {
    cleanup();
});

describe('Compliance command centre', () => {
    it('presents application-wide assurance only across accessible Sites and redacts restricted alert detail', () => {
        const props = pageProps({
            can: {
                manage: false,
                triage: false,
                viewControlRoom: false,
                viewAudit: false,
                viewReports: false,
            },
        });

        render(<ComplianceIndex {...props} />);

        expect(
            screen.getByText(
                /Application-wide obligations plus operational assurance across your accessible Sites/,
            ),
        ).toBeVisible();
        expect(
            screen.getAllByText('Site evacuation evidence')[0],
        ).toBeVisible();
        expect(screen.getAllByText('Mere care-plan review')[0]).toBeVisible();
        expect(screen.getByText('Alert details restricted')).toBeVisible();
        expect(
            screen.queryByText('Site alarm offline'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Log obligation' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Log notifiable' }),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Reviews' }));

        expect(
            screen.queryByText('Site evacuation evidence'),
        ).not.toBeInTheDocument();
        expect(screen.getAllByText('Mere care-plan review')[0]).toBeVisible();
    });

    it('uses canonical server routes for period, record, Control Room and report navigation', () => {
        render(<ComplianceIndex {...pageProps()} />);

        fireEvent.click(screen.getByRole('button', { name: '90 days' }));
        expect(mocks.get).toHaveBeenCalledWith(
            '/compliance',
            { period: '90d' },
            {
                preserveScroll: true,
                preserveState: false,
                replace: true,
            },
        );

        fireEvent.click(screen.getAllByText('Site evacuation evidence')[0]);
        expect(mocks.visit).toHaveBeenCalledWith('/governance/compliance/11');

        fireEvent.click(screen.getByRole('button', { name: /View all/i }));
        expect(mocks.visit).toHaveBeenCalledWith('/control-room');

        fireEvent.click(screen.getByRole('button', { name: 'Export' }));
        expect(mocks.visit).toHaveBeenCalledWith('/reports');
    });

    it('opens governed workflows and posts convenience triage to the canonical Control Room endpoint', () => {
        render(<ComplianceIndex {...pageProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Log obligation' }));
        expect(screen.getByTestId('log-obligation-dialog')).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Complete obligation' }),
        );
        expect(
            screen.getByTestId('complete-obligation-dialog'),
        ).toHaveTextContent('Obligation 11');

        fireEvent.click(screen.getByRole('button', { name: 'Acknowledge' }));
        expect(mocks.post).toHaveBeenCalledTimes(1);
        const [url, payload, options] = mocks.post.mock.calls[0];
        expect(url).toBe('/control-room/alerts/33/acknowledge');
        expect(payload).toEqual({ _modal: true });
        expect(options).toMatchObject({
            preserveScroll: true,
            preserveState: true,
        });

        options.onSuccess();
        expect(mocks.toastSuccess).toHaveBeenCalledWith('Alert acknowledged');
        expect(mocks.reload).toHaveBeenCalledWith({ only: ['controlRoom'] });
    });
});
