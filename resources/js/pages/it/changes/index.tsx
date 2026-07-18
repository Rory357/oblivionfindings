import { Button } from '@/components/ui/button';
import { ItModuleShell } from '@/components/it/it-module-shell';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    Plus,
    Search,
    ShieldCheck,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

export interface ChangeTicketOption {
    id: number;
    reference: string;
    title: string;
    priority: string;
    status: string;
    workflow_state: string;
    href: string;
}

interface ChangeRow extends ChangeTicketOption {
    change_id: number;
    change_type: string;
    risk_level: string;
    is_restricted: boolean;
    impact_summary: string | null;
    maintenance_starts_at: string | null;
    maintenance_ends_at: string | null;
    maintenance_state: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    changes: { data: ChangeRow[]; links: PaginationLink[]; total: number };
    filters: {
        type: string | null;
        risk: string | null;
        state: string | null;
        q: string | null;
    };
    can: { manage: boolean };
}

export const changeLabel = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());

export const changeStateVariant: Record<string, StatusVariant> = {
    draft: 'neutral',
    assessment: 'info',
    approval_pending: 'warning',
    approved: 'info',
    scheduled: 'info',
    implementing: 'warning',
    validation: 'warning',
    completed: 'success',
    failed: 'critical',
    backed_out: 'critical',
    review: 'info',
    closed: 'neutral',
    rejected: 'critical',
    cancelled: 'neutral',
};

const maintenanceLabels: Record<string, string> = {
    upcoming: 'Upcoming window',
    active: 'Window active',
    overdue: 'Window overdue',
    unscheduled: 'Window not set',
    emergency: 'Emergency execution',
    finished: 'Execution finished',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'IT & Support', href: '/it' },
    { title: 'Changes', href: '/it/changes' },
];

export default function ItChangesIndex({ changes, filters, can }: Props) {
    const [creating, setCreating] = useState(false);
    const [query, setQuery] = useState(filters.q ?? '');
    const [type, setType] = useState(filters.type ?? 'all');
    const [risk, setRisk] = useState(filters.risk ?? 'all');
    const [state, setState] = useState(filters.state ?? 'all');
    const form = useForm({
        title: '',
        description: '',
        category: 'other',
        priority: 'normal',
        change_type: 'normal',
        risk_level: 'medium',
        is_restricted: false,
        impact_summary: '',
    });

    const search = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            '/it/changes',
            {
                q: query || undefined,
                type: type === 'all' ? undefined : type,
                risk: risk === 'all' ? undefined : risk,
                state: state === 'all' ? undefined : state,
            },
            { preserveState: true, replace: true },
        );
    };

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post('/it/changes', { onSuccess: () => setCreating(false) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Changes" />
            <ItModuleShell>
            <main className="mx-auto w-full max-w-[1500px] space-y-6 px-4 py-6 sm:px-6">
                <header className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                        <div>
                            <div className="flex items-center gap-2 text-primary">
                                <ShieldCheck
                                    className="h-5 w-5"
                                    aria-hidden="true"
                                />
                                <span className="text-xs font-bold tracking-wide uppercase">
                                    IT & Support
                                </span>
                            </div>
                            <h1 className="mt-2 text-2xl font-bold tracking-tight">
                                Changes
                            </h1>
                            <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                                Plan, approve, schedule, execute, validate, and
                                review infrastructure changes without losing the
                                shared ticket record.
                            </p>
                        </div>
                        {can.manage ? (
                            <Button
                                onClick={() => setCreating(true)}
                                className="min-h-11"
                            >
                                <Plus className="h-4 w-4" aria-hidden="true" />{' '}
                                New change
                            </Button>
                        ) : null}
                    </div>
                </header>

                <form
                    onSubmit={search}
                    className="grid gap-3 rounded-2xl border border-border bg-card p-4 lg:grid-cols-[minmax(18rem,1fr)_11rem_11rem_13rem_auto]"
                >
                    <label className="relative">
                        <span className="sr-only">Search changes</span>
                        <Search
                            className="pointer-events-none absolute top-3 left-3 h-4 w-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            className="min-h-11 pl-9"
                            placeholder="Search reference, title, or impact"
                        />
                    </label>
                    <FilterSelect
                        label="Change type"
                        value={type}
                        onChange={setType}
                        values={['all', 'standard', 'normal', 'emergency']}
                    />
                    <FilterSelect
                        label="Risk level"
                        value={risk}
                        onChange={setRisk}
                        values={['all', 'low', 'medium', 'high', 'critical']}
                    />
                    <FilterSelect
                        label="Workflow state"
                        value={state}
                        onChange={setState}
                        values={[
                            'all',
                            'draft',
                            'assessment',
                            'approval_pending',
                            'approved',
                            'scheduled',
                            'implementing',
                            'validation',
                            'completed',
                            'failed',
                            'backed_out',
                            'review',
                            'closed',
                        ]}
                    />
                    <Button
                        type="submit"
                        variant="secondary"
                        className="min-h-11"
                    >
                        Apply filters
                    </Button>
                </form>

                <section
                    aria-label="Change records"
                    className="overflow-hidden rounded-2xl border border-border bg-card"
                >
                    <div className="flex items-center justify-between border-b border-border px-4 py-3">
                        <h2 className="font-semibold">Change register</h2>
                        <span className="text-sm text-muted-foreground">
                            {changes.total} records
                        </span>
                    </div>
                    {changes.data.length === 0 ? (
                        <div className="px-6 py-16 text-center">
                            <CalendarClock
                                className="mx-auto h-6 w-6 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p className="mt-3 font-medium">
                                No changes match these filters.
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Clear a filter or open a governed change record.
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-border">
                            {changes.data.map((change) => (
                                <li key={change.change_id}>
                                    <Link
                                        href={`/it/changes/${change.change_id}`}
                                        className="frontline-focus grid min-h-24 gap-3 px-4 py-4 hover:bg-muted/50 lg:grid-cols-[minmax(0,1fr)_12rem_12rem_auto] lg:items-center"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-mono text-xs font-bold text-primary">
                                                    {change.reference}
                                                </span>
                                                <StatusBadge
                                                    variant={
                                                        changeStateVariant[
                                                            change
                                                                .workflow_state
                                                        ] ?? 'neutral'
                                                    }
                                                    size="sm"
                                                >
                                                    {changeLabel(
                                                        change.workflow_state,
                                                    )}
                                                </StatusBadge>
                                                {change.is_restricted ? (
                                                    <StatusBadge
                                                        variant="critical"
                                                        size="sm"
                                                    >
                                                        Restricted
                                                    </StatusBadge>
                                                ) : null}
                                            </div>
                                            <h3 className="mt-1 truncate font-semibold">
                                                {change.title}
                                            </h3>
                                            <p className="mt-1 line-clamp-1 text-sm text-muted-foreground">
                                                {change.impact_summary ||
                                                    'Impact is still being assessed.'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-muted-foreground">
                                                Type / risk
                                            </p>
                                            <p className="mt-1 text-sm font-medium">
                                                {changeLabel(
                                                    change.change_type,
                                                )}{' '}
                                                ·{' '}
                                                {changeLabel(change.risk_level)}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-muted-foreground">
                                                Maintenance
                                            </p>
                                            <p className="mt-1 text-sm font-medium">
                                                {maintenanceLabels[
                                                    change.maintenance_state
                                                ] ??
                                                    changeLabel(
                                                        change.maintenance_state,
                                                    )}
                                            </p>
                                            {change.maintenance_starts_at ? (
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDate(
                                                        change.maintenance_starts_at,
                                                    )}
                                                </p>
                                            ) : null}
                                        </div>
                                        <ArrowRight
                                            className="hidden h-4 w-4 text-muted-foreground lg:block"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                    {changes.links.length > 3 ? (
                        <nav
                            aria-label="Change pages"
                            className="flex flex-wrap gap-1 border-t border-border px-4 py-3"
                        >
                            {changes.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={`${link.label}-${index}`}
                                        href={link.url}
                                        className={`frontline-focus rounded-md px-3 py-2 text-sm ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ) : null,
                            )}
                        </nav>
                    ) : null}
                </section>
            </main>
            </ItModuleShell>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="sm:max-w-2xl">
                    <form onSubmit={create}>
                        <DialogHeader>
                            <DialogTitle>Open a governed change</DialogTitle>
                            <DialogDescription>
                                Start with the purpose, type, risk, and expected
                                impact. Plans and operational links can be
                                completed in the workspace.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Change title"
                                error={form.errors.title}
                                className="sm:col-span-2"
                            >
                                <Input
                                    value={form.data.title}
                                    onChange={(event) =>
                                        form.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                label="What will change?"
                                error={form.errors.description}
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    rows={3}
                                />
                            </Field>
                            <Field
                                label="Expected impact"
                                error={form.errors.impact_summary}
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    value={form.data.impact_summary}
                                    onChange={(event) =>
                                        form.setData(
                                            'impact_summary',
                                            event.target.value,
                                        )
                                    }
                                    rows={3}
                                />
                            </Field>
                            <Field label="Change type">
                                <FormSelect
                                    value={form.data.change_type}
                                    onChange={(value) =>
                                        form.setData('change_type', value)
                                    }
                                    values={['standard', 'normal', 'emergency']}
                                />
                            </Field>
                            <Field label="Risk level">
                                <FormSelect
                                    value={form.data.risk_level}
                                    onChange={(value) =>
                                        form.setData('risk_level', value)
                                    }
                                    values={[
                                        'low',
                                        'medium',
                                        'high',
                                        'critical',
                                    ]}
                                />
                            </Field>
                            <Field label="Category">
                                <FormSelect
                                    value={form.data.category}
                                    onChange={(value) =>
                                        form.setData('category', value)
                                    }
                                    values={[
                                        'hardware',
                                        'account',
                                        'network',
                                        'other',
                                    ]}
                                />
                            </Field>
                            <Field label="Priority">
                                <FormSelect
                                    value={form.data.priority}
                                    onChange={(value) =>
                                        form.setData('priority', value)
                                    }
                                    values={['low', 'normal', 'high', 'urgent']}
                                />
                            </Field>
                            <label className="flex min-h-11 items-center gap-3 rounded-lg border border-border px-3 text-sm font-medium sm:col-span-2">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_restricted}
                                    onChange={(event) =>
                                        form.setData(
                                            'is_restricted',
                                            event.target.checked,
                                        )
                                    }
                                    className="h-4 w-4"
                                />
                                Restricted or privileged change
                            </label>
                        </div>
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreating(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Open change
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function FilterSelect({
    label,
    value,
    onChange,
    values,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    values: string[];
}) {
    return (
        <FormSelect
            label={label}
            value={value}
            onChange={onChange}
            values={values}
        />
    );
}

function FormSelect({
    label,
    value,
    onChange,
    values,
}: {
    label?: string;
    value: string;
    onChange: (value: string) => void;
    values: string[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="min-h-11" aria-label={label}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {values.map((item) => (
                    <SelectItem key={item} value={item}>
                        {item === 'all'
                            ? `All ${label?.toLowerCase() ?? 'values'}s`
                            : changeLabel(item)}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function Field({
    label,
    error,
    className,
    children,
}: {
    label: string;
    error?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <label
            className={`block space-y-1.5 text-sm font-medium ${className ?? ''}`}
        >
            <span>{label}</span>
            {children}
            {error ? (
                <span className="block text-xs text-destructive">{error}</span>
            ) : null}
        </label>
    );
}

function formatDate(value: string) {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
