import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    extractErrorMessage,
    formatCurrency,
    type RoadmapCan,
    statusLabel,
} from '../shared';

type PlanDetail = {
    id: number;
    fiscal_year: number;
    quarter: number;
    status: string;
    revision_no: number;
    preset_profile?: string | null;
    items: Array<{
        id: number;
        rank?: number | null;
        planned_capex?: number | string | null;
        planned_opex?: number | string | null;
        score_at_snapshot?: number | string | null;
        initiative?: {
            id: number;
            code?: string | null;
            title: string;
            status: string;
        } | null;
    }>;
};

type Props = {
    item: PlanDetail;
    can: RoadmapCan;
};

type PlanAction =
    | 'submit-manager'
    | 'submit-executive'
    | 'approve'
    | 'publish'
    | 'revise';

function availableActions(plan: PlanDetail, can: RoadmapCan): PlanAction[] {
    const actions: PlanAction[] = [];

    if (plan.status === 'draft' && can.manageRoadmap)
        actions.push('submit-manager');
    if (plan.status === 'manager_review' && can.manageRoadmap)
        actions.push('submit-executive');
    if (
        ['draft', 'manager_review', 'exec_review'].includes(plan.status) &&
        can.approveRoadmap
    ) {
        actions.push('approve');
    }
    if (plan.status === 'approved' && can.approveRoadmap)
        actions.push('publish');
    if (plan.status === 'published' && can.approveRoadmap)
        actions.push('revise');

    return actions;
}

function actionLabel(action: PlanAction): string {
    return {
        'submit-manager': 'Submit Manager',
        'submit-executive': 'Submit Executive',
        approve: 'Approve',
        publish: 'Publish',
        revise: 'Revise',
    }[action];
}

export default function QuarterlyPlanShow({ item, can }: Props) {
    const [loadingAction, setLoadingAction] = useState<PlanAction | null>(null);

    const runAction = async (action: PlanAction) => {
        setLoadingAction(action);
        try {
            const endpoint = {
                'submit-manager': `/roadmap/quarterly-plans/${item.id}/submit-manager`,
                'submit-executive': `/roadmap/quarterly-plans/${item.id}/submit-executive`,
                approve: `/roadmap/quarterly-plans/${item.id}/approve`,
                publish: `/roadmap/quarterly-plans/${item.id}/publish`,
                revise: `/roadmap/quarterly-plans/${item.id}/revise`,
            }[action];

            await axios.post(
                endpoint,
                action === 'revise'
                    ? {
                          change_summary:
                              'Revision requested from roadmap plan detail.',
                      }
                    : {},
                { headers: { Accept: 'application/json' } },
            );
            toast.success(`Plan action completed: ${actionLabel(action)}.`);
            router.reload({ preserveScroll: true });
        } catch (error) {
            toast.error(
                extractErrorMessage(
                    error,
                    `Failed plan action: ${actionLabel(action)}.`,
                ),
            );
        } finally {
            setLoadingAction(null);
        }
    };

    const actions = availableActions(item, can);

    return (
        <AppLayout>
            <Head
                title={`FY${item.fiscal_year} Q${item.quarter} Roadmap Plan`}
            />
            <PageHero
                variant="compact"
                title={`FY${item.fiscal_year} Q${item.quarter} Roadmap Plan`}
                description={`Revision ${item.revision_no} ${statusLabel(item.status)} plan detail.`}
                backHref="/roadmap/quarterly-plans"
            />
            <PageShell>
                <Card className="mb-4">
                    <CardContent className="flex flex-col gap-3 p-4 md:flex-row md:items-center md:justify-between">
                        <div className="flex flex-wrap gap-2">
                            <Badge variant="outline">
                                Revision {item.revision_no}
                            </Badge>
                            <Badge variant="outline">
                                {statusLabel(item.status)}
                            </Badge>
                            <Badge variant="outline">
                                {statusLabel(item.preset_profile)}
                            </Badge>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {actions.map((action) => (
                                <Button
                                    key={action}
                                    size="sm"
                                    onClick={() => void runAction(action)}
                                    disabled={loadingAction === action}
                                >
                                    {actionLabel(action)}
                                </Button>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Plan Items</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table data-testid="quarterly-plan-detail-table">
                                <caption className="sr-only">
                                    Roadmap quarterly plan items
                                </caption>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Rank</TableHead>
                                        <TableHead>Initiative</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Score</TableHead>
                                        <TableHead>Capex</TableHead>
                                        <TableHead>Opex</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {item.items.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="text-muted-foreground"
                                            >
                                                This plan has no items yet.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {item.items.map((planItem) => (
                                        <TableRow key={planItem.id}>
                                            <TableCell>
                                                {planItem.rank ?? '-'}
                                            </TableCell>
                                            <TableCell className="max-w-[420px]">
                                                <div className="font-medium">
                                                    {planItem.initiative
                                                        ?.title ??
                                                        'Unknown initiative'}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {planItem.initiative
                                                        ?.code ??
                                                        `INIT-${planItem.initiative?.id ?? planItem.id}`}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {statusLabel(
                                                    planItem.initiative?.status,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {planItem.score_at_snapshot ??
                                                    '-'}
                                            </TableCell>
                                            <TableCell>
                                                {formatCurrency(
                                                    planItem.planned_capex,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {formatCurrency(
                                                    planItem.planned_opex,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
