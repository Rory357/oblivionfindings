import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    Check,
    Copy,
    MoreVertical,
    Pencil,
    Plus,
    Power,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import type { PersonOption } from '@/components/hr/people-picker';
import { Card as GuardrailCard } from '@/components/ui/card';
import {
    CHECK_TYPE_BADGE,
    ComplianceContextMenu,
    useContextMenu,
    type CtxItem,
} from './components/compliance-bits';
import {
    ComplianceHubHeader,
    type HeroPayload,
} from './components/compliance-hub-header';
import {
    ComplianceWizards,
    type ReqOption,
    type RoleOption,
    type WizardState,
} from './components/compliance-wizards';

interface Requirement {
    id: number;
    name: string;
    code: string;
    category: string;
    check_type: string;
    validity_months: number | null;
    renewal_reminder_days: number | null;
    hard_stop: boolean;
    is_active: boolean;
}

interface MatrixEntry {
    id: number;
    requirement_id: number;
    role: string;
    site_type: string | null;
    is_mandatory: boolean;
}

interface Props {
    hero: HeroPayload;
    requirements: Requirement[];
    matrixEntries: MatrixEntry[];
    roles: string[];
    siteTypes: string[];
    wizard: {
        people: PersonOption[];
        requirements: ReqOption[];
        roles: RoleOption[];
        siteTypes: string[];
    };
    can: { manage: boolean; vetting_manage: boolean; driver_manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Staff compliance', href: '/hr/compliance' },
    { title: 'Matrix', href: '/hr/compliance/matrix' },
];

function humanize(role: string) {
    return role.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function ComplianceMatrix({
    hero,
    requirements,
    matrixEntries,
    roles,
    siteTypes,
    wizard,
    can,
}: Props) {
    const [wz, setWz] = useState<WizardState>(null);
    const [deactivate, setDeactivate] = useState<Requirement | null>(null);
    const [siteScope, setSiteScope] = useState('all');
    const { ctx, open: openCtx, close: closeCtx } = useContextMenu();
    const siteScopes = [
        'all',
        ...siteTypes.filter((type) => type.trim().toLowerCase() !== 'all'),
    ];

    const cellLevel = (
        reqId: number,
        role: string,
        scope: string,
    ): 0 | 1 | 2 => {
        const normalizedScope = scope.trim().toLowerCase() || 'all';
        const e = matrixEntries.find((m) => {
            const entryScope =
                (m.site_type ?? '').trim().toLowerCase() || 'all';
            return (
                m.requirement_id === reqId &&
                m.role === role &&
                entryScope === normalizedScope
            );
        });
        if (!e) return 0;
        return e.is_mandatory ? 2 : 1;
    };

    const cycleCell = (reqId: number, role: string, scope: string) => {
        if (!can.manage) return;
        const level = cellLevel(reqId, role, scope);
        const payload =
            level === 0
                ? {
                      requirement_id: reqId,
                      role,
                      site_type: scope,
                      is_mandatory: false,
                      action: 'assign',
                  }
                : level === 1
                  ? {
                        requirement_id: reqId,
                        role,
                        site_type: scope,
                        is_mandatory: true,
                        action: 'assign',
                    }
                  : {
                        requirement_id: reqId,
                        role,
                        site_type: scope,
                        is_mandatory: true,
                        action: 'unassign',
                    };
        router.post('/hr/compliance/matrix', payload, {
            preserveScroll: true,
            onSuccess: () => toast.success('Assignment updated.'),
            onError: () => toast.error('Could not update the matrix.'),
        });
    };

    const reqMenu = (q: Requirement): CtxItem[] => [
        {
            icon: Pencil,
            label: 'Edit',
            onClick: () => setWz({ type: 'requirement', preset: { ...q } }),
        },
        {
            icon: Users,
            label: 'Assign to roles',
            onClick: () =>
                setWz({ type: 'assign', preset: { requirement_id: q.id } }),
        },
        {
            icon: Copy,
            label: 'Duplicate',
            onClick: () =>
                setWz({
                    type: 'requirement',
                    preset: {
                        name: `${q.name} (copy)`,
                        category: q.category,
                        check_type: q.check_type,
                        hard_stop: q.hard_stop,
                    },
                }),
        },
        {
            icon: Power,
            label: 'Deactivate',
            tone: 'critical',
            onClick: () => setDeactivate(q),
        },
    ];

    const doDeactivate = () => {
        if (!deactivate) return;
        router.delete(`/hr/compliance/requirements/${deactivate.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(`Deactivated ${deactivate.code}.`),
        });
        setDeactivate(null);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Compliance matrix" />
            <div className="space-y-4 px-4 py-4 lg:px-6">
                <ComplianceHubHeader
                    hero={hero}
                    active="matrix"
                    counts={{ matrix: requirements.length || undefined }}
                    can={{
                        manage: can.manage,
                        vetting: can.vetting_manage,
                        driver: can.driver_manage,
                    }}
                    onWizard={(type) => setWz({ type })}
                />

                {/* Requirements library */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 className="text-base font-bold">
                            Requirements library
                        </h2>
                        <p className="mt-0.5 text-[12.5px] text-muted-foreground">
                            Define what staff must hold, then assign by role
                            &amp; site type.
                        </p>
                    </div>
                    {can.manage && (
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={() => setWz({ type: 'assign' })}
                            >
                                Bulk assign
                            </Button>
                            <Button
                                onClick={() => setWz({ type: 'requirement' })}
                            >
                                <Plus className="h-4 w-4" /> Add requirement
                            </Button>
                        </div>
                    )}
                </div>

                <GuardrailCard
                    unstyled
                    className="overflow-hidden rounded-xl border border-border bg-card"
                >
                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="border-b border-border bg-muted text-left text-muted-foreground">
                                <th className="px-3 py-3 font-semibold">
                                    Code
                                </th>
                                <th className="px-3 py-3 font-semibold">
                                    Requirement
                                </th>
                                <th className="px-3 py-3 font-semibold">
                                    Check type
                                </th>
                                <th className="px-3 py-3 text-center font-semibold">
                                    Validity
                                </th>
                                <th className="px-3 py-3 text-center font-semibold">
                                    Reminder
                                </th>
                                <th className="px-3 py-3 text-center font-semibold">
                                    Hard-stop
                                </th>
                                <th className="w-10" />
                            </tr>
                        </thead>
                        <tbody>
                            {requirements.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-4 py-10 text-center text-muted-foreground"
                                    >
                                        <ShieldCheck className="mx-auto mb-2 h-8 w-8 opacity-40" />
                                        No requirements yet. Add one to start
                                        tracking.
                                    </td>
                                </tr>
                            ) : (
                                requirements.map((q) => {
                                    const tb =
                                        CHECK_TYPE_BADGE[q.check_type] ??
                                        CHECK_TYPE_BADGE.manual;
                                    return (
                                        <tr
                                            key={q.id}
                                            onContextMenu={(e) =>
                                                openCtx(e, reqMenu(q))
                                            }
                                            className="border-b border-border last:border-0 hover:bg-muted/60"
                                        >
                                            <td className="px-3 py-2.5 font-mono text-xs text-muted-foreground">
                                                {q.code}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <div className="font-semibold">
                                                    {q.name}
                                                </div>
                                                <div className="text-[11px] text-muted-foreground">
                                                    {q.category}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <StatusBadge
                                                    variant={tb.variant}
                                                >
                                                    {tb.label}
                                                </StatusBadge>
                                            </td>
                                            <td className="px-3 py-2.5 text-center text-muted-foreground">
                                                {q.validity_months
                                                    ? `${q.validity_months} mo`
                                                    : '—'}
                                            </td>
                                            <td className="px-3 py-2.5 text-center text-muted-foreground">
                                                {q.renewal_reminder_days
                                                    ? `${q.renewal_reminder_days} d`
                                                    : '—'}
                                            </td>
                                            <td className="px-3 py-2.5 text-center">
                                                {q.hard_stop ? (
                                                    <StatusBadge variant="critical">
                                                        Hard-stop
                                                    </StatusBadge>
                                                ) : (
                                                    <StatusBadge variant="neutral">
                                                        Soft
                                                    </StatusBadge>
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5 text-right">
                                                <Button
                                                    unstyled
                                                    onClick={(e) =>
                                                        openCtx(e, reqMenu(q))
                                                    }
                                                    aria-label="Requirement actions"
                                                    className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent"
                                                >
                                                    <MoreVertical className="h-4 w-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </GuardrailCard>

                {/* Role × requirement grid */}
                <div>
                    <h2 className="text-base font-bold">
                        Role × requirement assignment
                    </h2>
                    <p className="mt-0.5 text-[12.5px] text-muted-foreground">
                        Choose All Sites or a specific Site type, then click a
                        cell to cycle: none → assigned → mandatory.
                    </p>
                </div>
                <div
                    className="flex flex-wrap gap-2"
                    aria-label="Matrix Site type"
                >
                    {siteScopes.map((scope) => (
                        <Button
                            key={scope}
                            type="button"
                            variant={
                                siteScope === scope ? 'default' : 'outline'
                            }
                            size="sm"
                            onClick={() => setSiteScope(scope)}
                        >
                            {scope === 'all' ? 'All Sites' : humanize(scope)}
                        </Button>
                    ))}
                </div>
                {roles.length === 0 ? (
                    <GuardrailCard
                        unstyled
                        className="rounded-xl border border-dashed border-border bg-card p-8 text-center text-sm text-muted-foreground"
                    >
                        No role assignments yet. Use{' '}
                        <span className="font-semibold text-foreground">
                            Bulk assign
                        </span>{' '}
                        to map requirements to roles.
                    </GuardrailCard>
                ) : (
                    <GuardrailCard
                        unstyled
                        className="overflow-auto rounded-xl border border-border bg-card"
                    >
                        <table className="w-full min-w-[760px] text-[12.5px]">
                            <thead>
                                <tr className="bg-muted">
                                    <th className="sticky left-0 z-10 min-w-[200px] bg-muted px-3 py-2.5 text-left font-semibold text-muted-foreground">
                                        Requirement
                                    </th>
                                    {roles.map((r) => (
                                        <th
                                            key={r}
                                            className="min-w-[96px] px-2 py-2.5 text-center font-semibold text-muted-foreground"
                                        >
                                            {humanize(r)}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {requirements.map((q) => (
                                    <tr
                                        key={q.id}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="sticky left-0 z-10 bg-card px-3 py-2 font-semibold">
                                            {q.name}
                                        </td>
                                        {roles.map((role) => {
                                            const level = cellLevel(
                                                q.id,
                                                role,
                                                siteScope,
                                            );
                                            return (
                                                <td
                                                    key={role}
                                                    className="p-1.5 text-center"
                                                >
                                                    <Button
                                                        unstyled
                                                        type="button"
                                                        disabled={!can.manage}
                                                        onClick={() =>
                                                            cycleCell(
                                                                q.id,
                                                                role,
                                                                siteScope,
                                                            )
                                                        }
                                                        aria-label={`${q.name} for ${humanize(role)} at ${siteScope === 'all' ? 'all Sites' : humanize(siteScope)}`}
                                                        className={`grid h-[26px] w-[26px] place-items-center rounded-md border transition-colors ${
                                                            level === 2
                                                                ? 'border-primary bg-primary text-primary-foreground'
                                                                : level === 1
                                                                  ? 'border-primary/40 bg-accent text-primary'
                                                                  : 'border-border bg-transparent hover:bg-muted'
                                                        }`}
                                                    >
                                                        {level > 0 ? (
                                                            <Check
                                                                className="h-3.5 w-3.5"
                                                                strokeWidth={3}
                                                            />
                                                        ) : null}
                                                    </Button>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </GuardrailCard>
                )}
                <div className="flex gap-4 text-xs text-muted-foreground">
                    <Legend className="border border-border" label="None" />
                    <Legend
                        className="border border-primary/40 bg-accent"
                        label="Assigned"
                    />
                    <Legend className="bg-primary" label="Mandatory" />
                </div>
            </div>

            <AlertDialog
                open={!!deactivate}
                onOpenChange={(o) => !o && setDeactivate(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Deactivate {deactivate?.code}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This deactivates “{deactivate?.name}” and removes
                            its role assignments. Recorded staff statuses are
                            kept for audit.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={doDeactivate}>
                            Deactivate
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <ComplianceContextMenu ctx={ctx} onClose={closeCtx} />
            <ComplianceWizards
                state={wz}
                onClose={() => setWz(null)}
                people={wizard.people}
                requirements={wizard.requirements}
                roles={wizard.roles}
                siteTypes={wizard.siteTypes}
            />
        </AppLayout>
    );
}

function Legend({ className, label }: { className: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span className={`h-3.5 w-3.5 rounded ${className}`} />
            {label}
        </span>
    );
}
