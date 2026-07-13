import { PerformanceTabs } from '@/components/hr';
import { SuccessionHero } from '@/components/hr/succession-hero';
import {
    SuccessionPlanWizard,
    type ExistingSuccessionPlan,
    type SuccessionHolderOption,
    type SuccessionPositionOption,
} from '@/components/hr/succession-wizards';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Archive, Pencil, Plus, Shield, Users } from 'lucide-react';
import { useState, type MouseEvent } from 'react';
import { toast } from 'sonner';

type SuccessionPlan = {
    id: number;
    role_title: string;
    department: string | null;
    risk_level: string;
    current_holder_name: string | null;
    current_holder: { id: number; name: string } | null;
    position: { id: number; title: string } | null;
    notes: string | null;
    candidates_count: number;
    is_active: boolean;
};

type Props = {
    plans: {
        data: SuccessionPlan[];
        current_page: number;
        last_page: number;
        total: number;
    };
    stats?: {
        total: number;
        high_risk: number;
        vacant: number;
        ready_now: number;
    };
    positions?: SuccessionPositionOption[];
    holders?: SuccessionHolderOption[];
    can: { manage?: boolean };
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Succession Planning', href: '/hr/succession' },
];

const riskConfig: Record<string, { className: string; label: string }> = {
    critical: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical-bg',
        label: 'Critical',
    },
    high: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'High',
    },
    medium: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'Medium',
    },
    low: {
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        label: 'Low',
    },
};

export default function SuccessionIndex({
    plans,
    stats,
    positions = [],
    holders = [],
    can,
}: Props) {
    // /hr/succession/create now redirects here with ?new=1 — open the wizard on mount.
    const [wizardOpen, setWizardOpen] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).has('new'),
    );
    const [editing, setEditing] = useState<ExistingSuccessionPlan | null>(null);
    const [deleting, setDeleting] = useState<SuccessionPlan | null>(null);

    const openEdit = (e: MouseEvent, plan: SuccessionPlan) => {
        e.stopPropagation();
        setEditing({
            id: plan.id,
            role_title: plan.role_title,
            department: plan.department,
            risk_level: plan.risk_level,
            current_holder: plan.current_holder,
            position: plan.position,
            notes: plan.notes,
        });
    };

    const confirmDelete = () => {
        if (!deleting) return;
        router.delete(`/hr/succession/${deleting.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Succession plan archived.');
                setDeleting(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Succession Planning" />
            <SuccessionHero
                stats={{
                    total: stats?.total ?? plans.total,
                    high_risk: stats?.high_risk ?? 0,
                    vacant: stats?.vacant ?? 0,
                    ready_now: stats?.ready_now ?? 0,
                }}
                canManage={!!can.manage}
                onCreate={() => setWizardOpen(true)}
            />
            <PageShell>
                <PerformanceTabs active="succession" />

                {plans.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <Shield className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p>No succession plans created yet.</p>
                            {can.manage && (
                                <Button
                                    variant="outline"
                                    className="mt-4"
                                    onClick={() => setWizardOpen(true)}
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create the first plan
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {plans.data.map((plan) => {
                            const risk =
                                riskConfig[plan.risk_level] || riskConfig.low;
                            return (
                                <Card
                                    key={plan.id}
                                    className="group cursor-pointer transition-colors hover:border-primary/30"
                                    onClick={() =>
                                        router.get(`/hr/succession/${plan.id}`)
                                    }
                                >
                                    <CardHeader className="pb-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <CardTitle className="text-base">
                                                {plan.role_title}
                                            </CardTitle>
                                            <div className="flex items-center gap-1">
                                                <Badge
                                                    variant="outline"
                                                    className={risk.className}
                                                >
                                                    {risk.label}
                                                </Badge>
                                                {can.manage && (
                                                    <>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7 opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                                                            aria-label={`Edit ${plan.role_title}`}
                                                            onClick={(e) =>
                                                                openEdit(
                                                                    e,
                                                                    plan,
                                                                )
                                                            }
                                                        >
                                                            <Pencil className="h-3.5 w-3.5" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7 opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                                                            aria-label={`Archive ${plan.role_title}`}
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                setDeleting(
                                                                    plan,
                                                                );
                                                            }}
                                                        >
                                                            <Archive className="h-3.5 w-3.5 text-muted-foreground" />
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                        {plan.department && (
                                            <p className="text-sm text-muted-foreground">
                                                {plan.department}
                                            </p>
                                        )}
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-2 text-sm">
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Current Holder
                                                </span>
                                                <span>
                                                    {plan.current_holder_name ||
                                                        'Vacant'}
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Candidates
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Users className="h-3 w-3" />
                                                    {plan.candidates_count}
                                                </span>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}
            </PageShell>

            {can.manage && wizardOpen && (
                <SuccessionPlanWizard
                    onClose={() => setWizardOpen(false)}
                    positions={positions}
                    holders={holders}
                />
            )}
            {can.manage && editing && (
                <SuccessionPlanWizard
                    key={editing.id}
                    onClose={() => setEditing(null)}
                    positions={positions}
                    holders={holders}
                    plan={editing}
                />
            )}

            <Dialog
                open={!!deleting}
                onOpenChange={(o) => {
                    if (!o) setDeleting(null);
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Archive succession plan?</DialogTitle>
                        <DialogDescription>
                            “{deleting?.role_title}” will leave the active
                            pipeline. Its {deleting?.candidates_count ?? 0}{' '}
                            candidate assessment
                            {(deleting?.candidates_count ?? 0) === 1
                                ? ''
                                : 's'}{' '}
                            will be retained.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setDeleting(null)}
                        >
                            Cancel
                        </Button>
                        <Button onClick={confirmDelete}>Archive plan</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
