import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Clock3,
    Megaphone,
    Plus,
    Search,
    Siren,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';

interface UserOption {
    id: number;
    name: string;
}

export interface MajorIncidentTicketOption {
    id: number;
    reference: string;
    title: string;
    priority: string;
    status: string;
    workflow_state: string;
    href: string;
}

interface MajorIncidentRow extends MajorIncidentTicketOption {
    major_incident_id: number;
    severity: string;
    impact_summary: string | null;
    commander: UserOption | null;
    communications_lead: UserOption | null;
    next_update_due_at: string | null;
    update_state: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    majorIncidents: {
        data: MajorIncidentRow[];
        links: PaginationLink[];
        total: number;
    };
    filters: {
        severity: string | null;
        state: string | null;
        q: string | null;
    };
    options: { agents: UserOption[] };
    can: { manage: boolean };
}

export const majorIncidentLabel = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());

export const majorIncidentStateVariant: Record<string, StatusVariant> = {
    declared: 'critical',
    responding: 'critical',
    monitoring: 'warning',
    restored: 'success',
    resolved: 'success',
    review: 'info',
    closed: 'neutral',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'IT & Support', href: '/it' },
    { title: 'Major incidents', href: '/it/major-incidents' },
];

const formatDateTime = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('en-NZ', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'Pacific/Auckland',
          }).format(new Date(value))
        : 'Not scheduled';

export default function ItMajorIncidentsIndex({
    majorIncidents,
    filters,
    options,
    can,
}: Props) {
    const [creating, setCreating] = useState(false);
    const [query, setQuery] = useState(filters.q ?? '');
    const [severity, setSeverity] = useState(filters.severity ?? 'all');
    const [state, setState] = useState(filters.state ?? 'all');
    const form = useForm({
        title: '',
        description: '',
        category: 'other',
        priority: 'urgent',
        severity: 'sev2',
        impact_summary: '',
        communications_lead_user_id: '',
        target_update_minutes: 30,
    });

    const search = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            '/it/major-incidents',
            {
                q: query || undefined,
                severity: severity === 'all' ? undefined : severity,
                state: state === 'all' ? undefined : state,
            },
            { preserveState: true, replace: true },
        );
    };

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post('/it/major-incidents', {
            onSuccess: () => setCreating(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Major incidents" />
            <main className="mx-auto w-full max-w-[1500px] space-y-6 px-4 py-6 sm:px-6">
                <header className="overflow-hidden rounded-2xl border border-status-critical/25 bg-card shadow-sm">
                    <div className="border-l-4 border-status-critical p-5">
                        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                            <div>
                                <div className="flex items-center gap-2 text-status-critical">
                                    <Siren
                                        className="h-5 w-5"
                                        aria-hidden="true"
                                    />
                                    <span className="text-xs font-bold tracking-wide uppercase">
                                        IT command
                                    </span>
                                </div>
                                <h1 className="mt-2 text-2xl font-bold tracking-tight">
                                    Major incidents
                                </h1>
                                <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                                    Coordinate technical response, link
                                    operational impact, and publish
                                    audience-safe updates from one accountable
                                    command record.
                                </p>
                            </div>
                            {can.manage ? (
                                <Button
                                    onClick={() => setCreating(true)}
                                    className="min-h-11"
                                >
                                    <Plus
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />{' '}
                                    Declare major incident
                                </Button>
                            ) : null}
                        </div>
                    </div>
                </header>

                <form
                    onSubmit={search}
                    className="grid gap-3 rounded-2xl border border-border bg-card p-4 lg:grid-cols-[minmax(18rem,1fr)_11rem_14rem_auto]"
                >
                    <label className="relative">
                        <span className="sr-only">Search major incidents</span>
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
                    <Filter
                        label="Severity"
                        value={severity}
                        onChange={setSeverity}
                        values={['all', 'sev1', 'sev2', 'sev3', 'sev4']}
                    />
                    <Filter
                        label="Command state"
                        value={state}
                        onChange={setState}
                        values={[
                            'all',
                            'declared',
                            'responding',
                            'monitoring',
                            'restored',
                            'resolved',
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
                    aria-label="Major incident records"
                    className="overflow-hidden rounded-2xl border border-border bg-card"
                >
                    <div className="flex items-center justify-between border-b border-border px-4 py-3">
                        <div>
                            <h2 className="font-semibold">Command register</h2>
                            <p className="text-xs text-muted-foreground">
                                {majorIncidents.total} accountable records
                            </p>
                        </div>
                        <Megaphone
                            className="h-5 w-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>
                    {majorIncidents.data.length === 0 ? (
                        <div className="px-6 py-14 text-center text-sm text-muted-foreground">
                            No major incidents match these filters.
                        </div>
                    ) : (
                        <ul className="divide-y divide-border">
                            {majorIncidents.data.map((incident) => (
                                <li
                                    key={incident.major_incident_id}
                                    className="p-4 transition-colors hover:bg-muted/25"
                                >
                                    <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <StatusBadge
                                                    variant={
                                                        incident.severity ===
                                                            'sev1' ||
                                                        incident.severity ===
                                                            'sev2'
                                                            ? 'critical'
                                                            : 'warning'
                                                    }
                                                >
                                                    {incident.severity.toUpperCase()}
                                                </StatusBadge>
                                                <StatusBadge
                                                    variant={
                                                        majorIncidentStateVariant[
                                                            incident
                                                                .workflow_state
                                                        ] ?? 'neutral'
                                                    }
                                                >
                                                    {majorIncidentLabel(
                                                        incident.workflow_state,
                                                    )}
                                                </StatusBadge>
                                                {incident.update_state ===
                                                'overdue' ? (
                                                    <StatusBadge variant="critical">
                                                        Update overdue
                                                    </StatusBadge>
                                                ) : (
                                                    <StatusBadge variant="success">
                                                        Updates on time
                                                    </StatusBadge>
                                                )}
                                            </div>
                                            <Link
                                                href={`/it/major-incidents/${incident.major_incident_id}`}
                                                className="frontline-focus mt-2 inline-flex items-center gap-2 rounded-md font-semibold text-foreground hover:text-primary"
                                            >
                                                <span className="font-mono text-sm text-primary">
                                                    {incident.reference}
                                                </span>
                                                {incident.title}
                                                <ArrowRight
                                                    className="h-4 w-4"
                                                    aria-hidden="true"
                                                />
                                            </Link>
                                            <p className="mt-1 max-w-4xl text-sm text-muted-foreground">
                                                {incident.impact_summary ||
                                                    'Impact summary not recorded.'}
                                            </p>
                                        </div>
                                        <div className="grid min-w-[18rem] gap-2 text-xs sm:grid-cols-2">
                                            <CommandFact
                                                label="Commander"
                                                value={
                                                    incident.commander?.name ??
                                                    'Unassigned'
                                                }
                                            />
                                            <CommandFact
                                                label="Communications"
                                                value={
                                                    incident.communications_lead
                                                        ?.name ?? 'Unassigned'
                                                }
                                            />
                                            <div className="flex items-center gap-2 rounded-lg bg-muted/45 px-3 py-2 text-muted-foreground sm:col-span-2">
                                                <Clock3
                                                    className="h-4 w-4"
                                                    aria-hidden="true"
                                                />{' '}
                                                Next update{' '}
                                                {formatDateTime(
                                                    incident.next_update_due_at,
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </main>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <form onSubmit={create}>
                        <DialogHeader>
                            <DialogTitle>Declare major incident</DialogTitle>
                            <DialogDescription>
                                Create the accountable technical command record.
                                Control Room remains the owner of operational
                                alerts.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <Field label="Title" className="sm:col-span-2">
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
                                label="Description"
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
                            <NativeSelect
                                label="Category"
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
                            <NativeSelect
                                label="Priority"
                                value={form.data.priority}
                                onChange={(value) =>
                                    form.setData('priority', value)
                                }
                                values={['urgent', 'high', 'normal', 'low']}
                            />
                            <NativeSelect
                                label="Severity"
                                value={form.data.severity}
                                onChange={(value) =>
                                    form.setData('severity', value)
                                }
                                values={['sev1', 'sev2', 'sev3', 'sev4']}
                            />
                            <label className="space-y-1.5 text-sm font-medium">
                                Communications lead
                                <select
                                    className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                                    value={
                                        form.data.communications_lead_user_id
                                    }
                                    onChange={(event) =>
                                        form.setData(
                                            'communications_lead_user_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Assign later</option>
                                    {options.agents.map((agent) => (
                                        <option key={agent.id} value={agent.id}>
                                            {agent.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <Field
                                label="Impact summary"
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
                                    required
                                />
                            </Field>
                            <Field label="Update cadence (minutes)">
                                <Input
                                    type="number"
                                    min={5}
                                    max={240}
                                    value={form.data.target_update_minutes}
                                    onChange={(event) =>
                                        form.setData(
                                            'target_update_minutes',
                                            Number(event.target.value),
                                        )
                                    }
                                    required
                                />
                            </Field>
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
                                Declare incident
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function Filter({
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
        <label>
            <span className="sr-only">{label}</span>
            <select
                className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {values.map((item) => (
                    <option key={item} value={item}>
                        {majorIncidentLabel(item)}
                    </option>
                ))}
            </select>
        </label>
    );
}

function Field({
    label,
    className = '',
    children,
}: {
    label: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <label className={`space-y-1.5 text-sm font-medium ${className}`}>
            {label}
            {children}
        </label>
    );
}

function NativeSelect({
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
        <label className="space-y-1.5 text-sm font-medium">
            {label}
            <select
                className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {values.map((item) => (
                    <option key={item} value={item}>
                        {majorIncidentLabel(item)}
                    </option>
                ))}
            </select>
        </label>
    );
}

function CommandFact({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg bg-muted/45 px-3 py-2">
            <span className="block text-[10px] font-bold tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span className="mt-0.5 block font-medium">{value}</span>
        </div>
    );
}
