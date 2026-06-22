/* eslint-disable no-restricted-syntax -- This read-only detail modal uses
 * compact custom layout surfaces (stat tiles, detail rows, chips) rather than
 * shadcn Card primitives. All colours are semantic design tokens. */
import {
    Briefcase,
    Building2,
    GitBranch,
    MapPin,
    Pencil,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

import { StatusBadge } from './status-badge';

interface DepartmentDetail {
    id: number;
    name: string;
    code: string | null;
    cost_centre: string | null;
    description: string | null;
    is_active: boolean;
    manager: { id: number; name: string } | null;
    parent: { id: number; name: string } | null;
    direct_employee_count: number;
    rolled_up_employee_count: number;
    sites: Array<{ id: number; name: string }>;
    children: Array<{
        id: number;
        name: string;
        code: string | null;
        is_active: boolean;
        employee_count: number;
    }>;
    linked_positions: Array<{
        id: number;
        title: string;
        code: string | null;
        headcount_budget: number;
        current_headcount: number;
        is_active: boolean;
    }>;
}

/** Read-only department detail — head, parent, children, headcount roll-up, linked positions. */
export function DepartmentViewDialog({
    open,
    departmentId,
    onClose,
    onEdit,
    canManage = false,
}: {
    open: boolean;
    departmentId: number | null;
    onClose: () => void;
    onEdit?: () => void;
    canManage?: boolean;
}) {
    const [data, setData] = useState<DepartmentDetail | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!open || !departmentId) return;
        let cancelled = false;
        setLoading(true);
        setData(null);
        fetch(`/hr/departments/${departmentId}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => {
                if (!cancelled) setData(d);
            })
            .catch(() => {
                if (!cancelled) setData(null);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [open, departmentId]);

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[88vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <DialogTitle className="flex items-center gap-2">
                                <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-status-success-bg text-status-success">
                                    <Building2 className="h-4 w-4" />
                                </span>
                                {data?.name ?? 'Department'}
                                {data ? (
                                    <StatusBadge
                                        status={data.is_active ? 'active' : 'inactive'}
                                    />
                                ) : null}
                            </DialogTitle>
                            <DialogDescription>
                                {[data?.code, data?.cost_centre && `Cost centre ${data.cost_centre}`]
                                    .filter(Boolean)
                                    .join(' · ') || 'Department detail'}
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                {loading ? (
                    <div className="py-12 text-center text-sm text-muted-foreground">
                        Loading…
                    </div>
                ) : !data ? (
                    <div className="py-12 text-center text-sm text-muted-foreground">
                        Couldn’t load this department.
                    </div>
                ) : (
                    <div className="space-y-4">
                        {/* Stats */}
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <Stat icon={Users} label="Direct staff" value={data.direct_employee_count} />
                            <Stat icon={Users} label="Total (incl. sub-depts)" value={data.rolled_up_employee_count} />
                            <Stat icon={GitBranch} label="Sub-departments" value={data.children.length} />
                            <Stat icon={Briefcase} label="Linked positions" value={data.linked_positions.length} />
                        </div>

                        {/* Details */}
                        <div className="rounded-xl border border-border bg-card/70 p-4 text-sm">
                            <Row label="Head" value={data.manager?.name} />
                            <Row label="Parent department" value={data.parent?.name} />
                            <Row label="Description" value={data.description} />
                        </div>

                        {/* Sites */}
                        {data.sites.length > 0 ? (
                            <Section title="Sites">
                                <div className="flex flex-wrap gap-2">
                                    {data.sites.map((s) => (
                                        <span
                                            key={s.id}
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-muted/40 px-2.5 py-1 text-xs font-medium"
                                        >
                                            <MapPin className="h-3.5 w-3.5 text-muted-foreground" />
                                            {s.name}
                                        </span>
                                    ))}
                                </div>
                            </Section>
                        ) : null}

                        {/* Children */}
                        {data.children.length > 0 ? (
                            <Section title="Sub-departments">
                                <div className="flex flex-wrap gap-2">
                                    {data.children.map((c) => (
                                        <span
                                            key={c.id}
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-muted/40 px-2.5 py-1 text-xs font-medium"
                                        >
                                            {c.name}
                                            <span className="text-muted-foreground">
                                                {c.employee_count}
                                            </span>
                                            {!c.is_active ? (
                                                <StatusBadge status="inactive" />
                                            ) : null}
                                        </span>
                                    ))}
                                </div>
                            </Section>
                        ) : null}

                        {/* Linked positions */}
                        {data.linked_positions.length > 0 ? (
                            <Section title="Positions in this department">
                                <div className="divide-y rounded-xl border border-border">
                                    {data.linked_positions.map((p) => (
                                        <div
                                            key={p.id}
                                            className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                        >
                                            <span className="min-w-0">
                                                <span className="font-medium">
                                                    {p.title}
                                                </span>
                                                {p.code ? (
                                                    <span className="ml-2 font-mono text-xs text-muted-foreground">
                                                        {p.code}
                                                    </span>
                                                ) : null}
                                            </span>
                                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                {p.current_headcount}/{p.headcount_budget}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </Section>
                        ) : null}

                        <div className="flex justify-end gap-2 pt-1">
                            <Button variant="outline" onClick={onClose}>
                                Close
                            </Button>
                            {canManage && onEdit ? (
                                <Button onClick={onEdit} className="gap-1.5">
                                    <Pencil className="h-4 w-4" />
                                    Edit department
                                </Button>
                            ) : null}
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function Stat({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof Users;
    label: string;
    value: number;
}) {
    return (
        <div className="rounded-xl border border-border bg-card/70 p-3">
            <div className="mb-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                <Icon className="h-3.5 w-3.5" />
                {label}
            </div>
            <div className="text-xl font-bold tabular-nums">{value}</div>
        </div>
    );
}

function Row({ label, value }: { label: string; value?: string | null }) {
    return (
        <div className="flex justify-between gap-4 border-b border-border py-1.5 last:border-0">
            <span className="shrink-0 text-muted-foreground">{label}</span>
            <span className="min-w-0 text-right font-medium">
                {value ? value : <span className="font-normal text-muted-foreground">—</span>}
            </span>
        </div>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                {title}
            </div>
            {children}
        </div>
    );
}

export default DepartmentViewDialog;
