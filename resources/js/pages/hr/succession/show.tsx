import {
    SuccessionCandidateDialog,
    type ExistingSuccessionCandidate,
    type SuccessionEmployeeOption,
} from '@/components/hr/performance/succession-candidate-dialog';
import {
    SuccessionPlanWizard,
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
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, Sparkles, Star, Trash2, Users, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

type Candidate = {
    id: number;
    employee: { id: number; name: string } | null;
    readiness: string;
    strengths: string | null;
    development_needs: string | null;
    overall_rating: number | null;
};
type Plan = {
    id: number;
    role_title: string;
    department: string | null;
    risk_level: string;
    current_holder_name: string | null;
    current_holder: { id: number; name: string } | null;
    position: { id: number; title: string } | null;
    notes: string | null;
    candidates: Candidate[];
};
type Props = {
    plan: Plan;
    employees: SuccessionEmployeeOption[];
    positions?: SuccessionPositionOption[];
    holders?: SuccessionHolderOption[];
    can: { manage?: boolean };
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Succession', href: '/hr/succession' },
    { title: 'Detail', href: '#' },
];
const readinessLabels: Record<string, string> = {
    ready_now: 'Ready Now',
    ready_1_year: '1 Year',
    ready_2_years: '2 Years',
    developing: 'Developing',
};
const readinessColors: Record<string, string> = {
    ready_now: 'border-status-success/30 text-status-success bg-status-success-bg',
    ready_1_year: 'border-status-info/30 text-status-info bg-status-info-bg',
    ready_2_years:
        'border-status-warning/30 text-status-warning bg-status-warning-bg',
    developing: 'border-border/30 text-muted-foreground',
};

export default function SuccessionShow({
    plan,
    employees,
    positions = [],
    holders = [],
    can,
}: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<ExistingSuccessionCandidate | null>(
        null,
    );
    const [planWizardOpen, setPlanWizardOpen] = useState(false);
    const [deletingPlan, setDeletingPlan] = useState(false);
    const [removing, setRemoving] = useState<Candidate | null>(null);

    const openAdd = () => {
        setEditing(null);
        setDialogOpen(true);
    };
    const openEdit = (c: Candidate) => {
        setEditing({
            id: c.id,
            employee: c.employee,
            readiness: c.readiness,
            strengths: c.strengths,
            development_needs: c.development_needs,
            overall_rating: c.overall_rating,
        });
        setDialogOpen(true);
    };

    const nominate = (c: Candidate) => {
        router.post(
            `/hr/succession/candidates/${c.id}/nominate`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        `${c.employee?.name ?? 'Candidate'} nominated to the ready-now talent pool.`,
                    ),
            },
        );
    };

    const confirmRemove = () => {
        if (!removing) return;
        router.delete(`/hr/succession/candidates/${removing.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Candidate removed from plan.');
                setRemoving(null);
            },
        });
    };

    const confirmDeletePlan = () => {
        router.delete(`/hr/succession/${plan.id}`, {
            onSuccess: () => toast.success('Succession plan deleted.'),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Succession: ${plan.role_title}`} />
            <PageShell>
                <PageHero category="hr" variant="compact"
                    backHref="/hr/succession"
                    backLabel="Succession planning"
                    title={plan.role_title}
                    description={plan.department || 'Succession Plan'}
                    actions={
                        can.manage ? (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => setPlanWizardOpen(true)}
                                >
                                    <Pencil className="mr-2 h-4 w-4" />
                                    Edit plan
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setDeletingPlan(true)}
                                >
                                    <Trash2 className="mr-2 h-4 w-4 text-status-critical" />
                                    Delete
                                </Button>
                            </>
                        ) : undefined
                    }
                />
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">
                                Current Holder
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-lg font-medium">
                                {plan.current_holder_name || 'Vacant'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">
                                Risk Level
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Badge variant="outline">{plan.risk_level}</Badge>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">
                                Candidates
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="flex items-center gap-1 text-lg font-medium">
                                <Users className="h-4 w-4" />
                                {plan.candidates.length}
                            </p>
                        </CardContent>
                    </Card>
                </div>
                {plan.notes && (
                    <Card>
                        <CardContent className="pt-4">
                            <p className="text-sm whitespace-pre-line text-muted-foreground">
                                {plan.notes}
                            </p>
                        </CardContent>
                    </Card>
                )}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle>Succession Candidates</CardTitle>
                            {can.manage && (
                                <Button size="sm" variant="outline" onClick={openAdd}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add candidate
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        {plan.candidates.length === 0 ? (
                            <p className="py-8 text-center text-muted-foreground">
                                No candidates added yet.
                            </p>
                        ) : (
                            <div className="grid gap-4 md:grid-cols-2">
                                {plan.candidates.map((c) => (
                                    <Card key={c.id}>
                                        <CardContent className="space-y-2 pt-4">
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="font-medium">
                                                    {c.employee?.name ??
                                                        'Unknown'}
                                                </p>
                                                <div className="flex items-center gap-2">
                                                    <Badge
                                                        variant="outline"
                                                        className={
                                                            readinessColors[
                                                                c.readiness
                                                            ]
                                                        }
                                                    >
                                                        {readinessLabels[
                                                            c.readiness
                                                        ] || c.readiness}
                                                    </Badge>
                                                    {can.manage && (
                                                        <>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-7 w-7"
                                                                onClick={() =>
                                                                    openEdit(c)
                                                                }
                                                                aria-label="Edit candidate"
                                                            >
                                                                <Pencil className="h-3.5 w-3.5" />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-7 w-7"
                                                                onClick={() =>
                                                                    setRemoving(c)
                                                                }
                                                                aria-label="Remove candidate"
                                                            >
                                                                <X className="h-3.5 w-3.5 text-status-critical" />
                                                            </Button>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                            {c.overall_rating && (
                                                <div className="flex gap-0.5">
                                                    {[1, 2, 3, 4, 5].map(
                                                        (s) => (
                                                            <Star
                                                                key={s}
                                                                className={`h-4 w-4 ${s <= c.overall_rating! ? 'fill-status-warning text-status-warning' : 'text-muted-foreground'}`}
                                                            />
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                            {c.strengths && (
                                                <div>
                                                    <p className="text-xs text-muted-foreground">
                                                        Strengths
                                                    </p>
                                                    <p className="text-sm">
                                                        {c.strengths}
                                                    </p>
                                                </div>
                                            )}
                                            {c.development_needs && (
                                                <div>
                                                    <p className="text-xs text-muted-foreground">
                                                        Development Needs
                                                    </p>
                                                    <p className="text-sm">
                                                        {c.development_needs}
                                                    </p>
                                                </div>
                                            )}
                                            {can.manage &&
                                                c.readiness !== 'ready_now' && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="mt-1"
                                                        onClick={() =>
                                                            nominate(c)
                                                        }
                                                    >
                                                        <Sparkles className="mr-1.5 h-3.5 w-3.5" />
                                                        Nominate as ready now
                                                    </Button>
                                                )}
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {can.manage && (
                    <SuccessionCandidateDialog
                        key={editing?.id ?? 'new'}
                        open={dialogOpen}
                        onClose={() => {
                            setDialogOpen(false);
                            setEditing(null);
                        }}
                        planId={plan.id}
                        employees={employees}
                        candidate={editing}
                    />
                )}

                {can.manage && planWizardOpen && (
                    <SuccessionPlanWizard
                        onClose={() => setPlanWizardOpen(false)}
                        positions={positions}
                        holders={holders}
                        plan={{
                            id: plan.id,
                            role_title: plan.role_title,
                            department: plan.department,
                            risk_level: plan.risk_level,
                            current_holder: plan.current_holder,
                            position: plan.position,
                            notes: plan.notes,
                        }}
                    />
                )}

                <Dialog
                    open={!!removing}
                    onOpenChange={(o) => {
                        if (!o) setRemoving(null);
                    }}
                >
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Remove candidate?</DialogTitle>
                            <DialogDescription>
                                {removing?.employee?.name ?? 'This candidate'} and
                                their readiness assessment will be removed from
                                this plan.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button
                                variant="ghost"
                                onClick={() => setRemoving(null)}
                            >
                                Cancel
                            </Button>
                            <Button variant="destructive" onClick={confirmRemove}>
                                Remove
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={deletingPlan} onOpenChange={setDeletingPlan}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Delete succession plan?</DialogTitle>
                            <DialogDescription>
                                “{plan.role_title}” and its{' '}
                                {plan.candidates.length} candidate assessment
                                {plan.candidates.length === 1 ? '' : 's'} will be
                                removed. This can’t be undone.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button
                                variant="ghost"
                                onClick={() => setDeletingPlan(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={confirmDeletePlan}
                            >
                                Delete plan
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}
