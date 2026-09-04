import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Landmark, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';

import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { data as dashboardData } from '@/routes/governance/dashboard';
import { PageProps } from '@/types';

import type { WorkflowAction } from '@/components/governance/BoardPriorityCard';
import { CockpitSkeleton } from '@/components/governance/CockpitSkeleton';
import { CockpitLayout, type CockpitPayload } from './Cockpit/CockpitLayout';

interface DashboardPayload {
    snapshot_id: number | null;
    workflow: {
        summary: { total: number; critical: number; overdue: number };
        actions: WorkflowAction[];
    };
    cockpit: CockpitPayload;
}

type Props = PageProps & {
    isBoardMember: boolean;
    boardRole?: string;
};

const formatLabel = (value: string) => value.replace(/_/g, ' ');

/**
 * Governance Dashboard — thin orchestrator. The hero stays purple via the
 * `category="governance"` dynamic accent. The body is composed entirely
 * inside `CockpitLayout` to keep this file focused on page chrome + data
 * fetch.
 */
export default function GovernanceDashboard({
    auth,
    isBoardMember,
    boardRole,
}: Props) {
    const [period, setPeriod] = useState('month');
    const [payload, setPayload] = useState<DashboardPayload | null>(null);
    const [loading, setLoading] = useState(true);

    // Manual refresh bypasses the server-side dashboard cache (fresh=1)
    // so the button always returns just-computed numbers.
    const fetchData = async () => {
        setLoading(true);
        try {
            const response = await axios.get(dashboardData.url(), {
                params: { period, fresh: 1 },
            });
            setPayload(response.data);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        let cancelled = false;
        const load = async () => {
            setLoading(true);
            try {
                const response = await axios.get(dashboardData.url(), {
                    params: { period },
                });
                if (!cancelled) setPayload(response.data);
            } finally {
                if (!cancelled) setLoading(false);
            }
        };
        void load();
        return () => {
            cancelled = true;
        };
    }, [period]);

    const workflow = payload?.workflow;
    const cockpit = payload?.cockpit;
    const permissions =
        (auth as { can?: { governance?: Record<string, unknown> } })?.can
            ?.governance ?? null;

    const stats = workflow
        ? [
              { label: 'Open actions', value: workflow.summary.total },
              { label: 'Critical', value: workflow.summary.critical },
              { label: 'Overdue', value: workflow.summary.overdue },
          ]
        : undefined;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
            ]}
        >
            <Head title="Governance Dashboard" />

            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        icon={Landmark}
                        title={
                            <span dusk="governance-cockpit-heading">
                                Executive &amp; Board Cockpit
                            </span>
                        }
                        description="Your central hub for meetings, decisions, risks, compliance, and financial governance."
                        stats={stats}
                        badges={
                            isBoardMember && boardRole
                                ? [{ label: formatLabel(boardRole) }]
                                : undefined
                        }
                        actions={
                            <div className="flex items-center gap-2">
                                <Select
                                    value={period}
                                    onValueChange={setPeriod}
                                >
                                    <SelectTrigger
                                        className="w-36 border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm"
                                        aria-label="Reporting period"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="today">
                                            Today
                                        </SelectItem>
                                        <SelectItem value="week">
                                            This Week
                                        </SelectItem>
                                        <SelectItem value="month">
                                            This Month
                                        </SelectItem>
                                        <SelectItem value="year">
                                            This Year
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Button
                                    variant="outline"
                                    onClick={fetchData}
                                    disabled={loading}
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    aria-label="Refresh dashboard"
                                >
                                    <RefreshCw
                                        className={
                                            loading
                                                ? 'h-4 w-4 animate-spin'
                                                : 'h-4 w-4'
                                        }
                                        aria-hidden="true"
                                    />
                                    <span className="ml-2">
                                        {loading ? 'Refreshing' : 'Refresh'}
                                    </span>
                                </Button>
                            </div>
                        }
                    />
                }
            >
                {loading && !payload ? (
                    <CockpitSkeleton />
                ) : cockpit && workflow ? (
                    <CockpitLayout
                        cockpit={cockpit}
                        workflow={workflow}
                        permissions={permissions}
                        currentUserName={auth.user?.name ?? null}
                        boardRole={boardRole ?? null}
                        userRole={
                            (auth.user as { role?: string } | undefined)
                                ?.role ?? null
                        }
                    />
                ) : (
                    <CockpitSkeleton />
                )}
            </PageLayout>
        </AppLayout>
    );
}
