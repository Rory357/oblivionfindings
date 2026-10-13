import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowUpRight,
    CalendarDays,
    CheckCheck,
    ClipboardList,
    Clock3,
    Plus,
    Search,
    UserRound,
} from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import './plans.css';

type Status = 'planned' | 'in_progress' | 'blocked' | 'completed' | 'cancelled';
const statuses: Record<Status, string> = {
    planned: 'Planned',
    in_progress: 'In progress',
    blocked: 'Blocked',
    completed: 'Completed',
    cancelled: 'Cancelled',
};
type Definition = {
    kind: string;
    title: string;
    role: string;
    scope: 'personal' | 'site' | 'organisation';
    guide: string;
    fields: Record<string, string>;
    numbers?: string[];
};
type Owner = { id: number; name: string; site_ids: number[] };
type Plan = {
    overdue?: boolean;
    id: number;
    title: string;
    due_date: string;
    status: Status;
    progress: number;
    site_id: number | null;
    owner_id: number;
    owner: { name: string };
    site: { name: string } | null;
    details: Record<string, string | number | null>;
    completion_note: string | null;
    completed_at: string | null;
    updated_at: string;
    version: number;
};
type History = {
    version: number;
    at: string;
    status: Status;
    progress: number;
}[];
type Props = {
    errors?: Record<string, string>;
    definition: Definition;
    canWrite: boolean;
    currentUserId: number;
    sites: { id: number; name: string }[];
    owners: Owner[];
    records: {
        data: Plan[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
    selected: Plan | null;
    history: History;
    counts: Partial<Record<Status | 'overdue', number>>;
    filters: { site?: string; status?: string; search?: string };
};
type FormValues = {
    title: string;
    due_date: string;
    status: Status;
    progress: number;
    site_id: number | null;
    owner_id: number;
    details: Record<string, string | number | null>;
    completion_note: string;
    version?: number;
};

export function PlanEditor({
    definition,
    plan,
    sites,
    owners,
    currentUserId,
    canWrite,
    history,
    onClose,
}: Pick<
    Props,
    'definition' | 'sites' | 'owners' | 'currentUserId' | 'canWrite' | 'history'
> & { plan: Plan | null; onClose: () => void }) {
    const [discard, setDiscard] = useState(false);
    const [confirmReload, setConfirmReload] = useState(false);
    const form = useForm<FormValues>({
        title: plan?.title ?? '',
        due_date: plan?.due_date.slice(0, 10) ?? '',
        status: plan?.status ?? 'planned',
        progress: plan?.progress ?? 0,
        site_id:
            plan?.site_id ??
            (definition.scope === 'site' && sites.length === 1
                ? sites[0].id
                : null),
        owner_id: plan?.owner_id ?? currentUserId,
        details:
            plan?.details ??
            Object.fromEntries(
                Object.keys(definition.fields).map((key) => [key, '']),
            ),
        completion_note: plan?.completion_note ?? '',
        ...(plan ? { version: plan.version } : {}),
    });
    const leave = useSettingsLeaveConfirmation(
        canWrite && form.isDirty,
        'Discard unsaved plan changes?',
        'Your unsaved plan changes will be lost. Cancel to keep editing.',
    );
    const errors = form.errors as Record<string, string | undefined>;
    function close() {
        if (form.isDirty) setDiscard(true);
        else onClose();
    }
    function save(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (plan)
            form.put(`/work-plans/${definition.kind}/${plan.id}`, options);
        else form.post(`/work-plans/${definition.kind}`, options);
    }
    const eligibleOwners = owners.filter(
        (owner) =>
            definition.scope !== 'site' ||
            owner.site_ids.includes(form.data.site_id ?? 0),
    );
    return (
        <>
            {leave.confirmation}
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent className="wp-editor sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {plan
                                ? canWrite
                                    ? 'Update plan'
                                    : 'Plan details'
                                : 'Add a plan'}
                        </DialogTitle>
                        <DialogDescription>
                            {definition.title} ·{' '}
                            {definition.scope === 'personal'
                                ? 'Only you can see this plan'
                                : 'Visible within this workspace’s permitted audience'}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={save} className="wp-form">
                        <p className="wp-guide">{definition.guide}</p>
                        {Object.keys(errors).length > 0 && (
                            <div className="wp-error" role="alert">
                                <strong>
                                    Check the following before saving. Your
                                    changes are still here.
                                </strong>
                                <ul>
                                    {Object.entries(errors).map(
                                        ([key, value]) => (
                                            <li key={key}>{value}</li>
                                        ),
                                    )}
                                </ul>
                                {errors.version && !confirmReload && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setConfirmReload(true)}
                                    >
                                        Reload saved version
                                    </Button>
                                )}
                                {errors.version && confirmReload && (
                                    <div className="wp-discard">
                                        <span>
                                            Reloading replaces your unsaved
                                            changes with the latest saved
                                            version.
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                setConfirmReload(false)
                                            }
                                        >
                                            Keep my draft
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            onClick={() =>
                                                leave.discardAndRun(() =>
                                                    router.get(
                                                        `/work-plans/${definition.kind}`,
                                                        { record: plan?.id },
                                                        {
                                                            preserveState: false,
                                                            preserveScroll: true,
                                                        },
                                                    ),
                                                )
                                            }
                                        >
                                            Discard draft and reload
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}
                        <div className="wp-field">
                            <Label htmlFor="plan-title">Plan title</Label>
                            <Input
                                id="plan-title"
                                maxLength={160}
                                required
                                disabled={!canWrite}
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="A clear task and expected outcome"
                                aria-invalid={!!errors.title}
                            />
                        </div>
                        <div className="wp-two">
                            <div className="wp-field">
                                <Label htmlFor="plan-date">Due date</Label>
                                <Input
                                    id="plan-date"
                                    type="date"
                                    required
                                    disabled={!canWrite}
                                    value={form.data.due_date}
                                    onChange={(e) =>
                                        form.setData('due_date', e.target.value)
                                    }
                                    aria-invalid={!!errors.due_date}
                                />
                            </div>
                            <div className="wp-field">
                                <Label htmlFor="plan-status">Status</Label>
                                <Select
                                    disabled={!canWrite}
                                    value={form.data.status}
                                    onValueChange={(value) => {
                                        form.setData('status', value as Status);
                                        form.setData(
                                            'progress',
                                            value === 'completed'
                                                ? 100
                                                : value === 'planned'
                                                  ? 0
                                                  : Math.min(
                                                        99,
                                                        form.data.progress,
                                                    ),
                                        );
                                    }}
                                >
                                    <SelectTrigger id="plan-status">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(statuses).map(
                                            ([key, label]) => (
                                                <SelectItem
                                                    key={key}
                                                    value={key}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="wp-two">
                            {definition.scope === 'site' && (
                                <div className="wp-field">
                                    <Label htmlFor="plan-site">Site</Label>
                                    <Select
                                        disabled={!canWrite || !!plan}
                                        value={
                                            form.data.site_id
                                                ? String(form.data.site_id)
                                                : ''
                                        }
                                        onValueChange={(value) =>
                                            form.setData(
                                                'site_id',
                                                Number(value),
                                            )
                                        }
                                    >
                                        <SelectTrigger id="plan-site">
                                            <SelectValue placeholder="Choose a site" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {sites.map((site) => (
                                                <SelectItem
                                                    key={site.id}
                                                    value={String(site.id)}
                                                >
                                                    {site.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                            <div className="wp-field">
                                <Label htmlFor="plan-owner">Owner</Label>
                                {!canWrite ||
                                definition.scope === 'personal' ? (
                                    <Input
                                        id="plan-owner"
                                        readOnly
                                        value={
                                            plan?.owner.name ??
                                            owners.find(
                                                (owner) =>
                                                    owner.id === currentUserId,
                                            )?.name ??
                                            'You'
                                        }
                                    />
                                ) : (
                                    <Select
                                        value={String(form.data.owner_id)}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'owner_id',
                                                Number(value),
                                            )
                                        }
                                    >
                                        <SelectTrigger id="plan-owner">
                                            <SelectValue placeholder="Choose an owner" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {eligibleOwners.map((owner) => (
                                                <SelectItem
                                                    key={owner.id}
                                                    value={String(owner.id)}
                                                >
                                                    {owner.name}
                                                </SelectItem>
                                            ))}
                                            {plan &&
                                                !eligibleOwners.some(
                                                    (owner) =>
                                                        owner.id ===
                                                        plan.owner_id,
                                                ) && (
                                                    <SelectItem
                                                        value={String(
                                                            plan.owner_id,
                                                        )}
                                                    >
                                                        {plan.owner.name} ·
                                                        current owner
                                                    </SelectItem>
                                                )}
                                        </SelectContent>
                                    </Select>
                                )}
                            </div>
                        </div>
                        <div className="wp-field">
                            <Label htmlFor="plan-progress">
                                Progress ·{' '}
                                {form.data.status === 'completed'
                                    ? 100
                                    : form.data.status === 'planned'
                                      ? 0
                                      : form.data.progress}
                                %
                            </Label>
                            <Input
                                id="plan-progress"
                                type="number"
                                min={0}
                                max={99}
                                disabled={
                                    !canWrite ||
                                    form.data.status === 'completed' ||
                                    form.data.status === 'planned'
                                }
                                value={
                                    form.data.status === 'completed'
                                        ? 100
                                        : form.data.status === 'planned'
                                          ? 0
                                          : form.data.progress
                                }
                                onChange={(e) =>
                                    form.setData(
                                        'progress',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </div>
                        {Object.entries(definition.fields).map(
                            ([key, label]) => (
                                <div key={key} className="wp-field">
                                    <Label htmlFor={`detail-${key}`}>
                                        {label}
                                    </Label>
                                    {definition.numbers?.includes(key) ? (
                                        <Input
                                            id={`detail-${key}`}
                                            type="number"
                                            required
                                            min={0}
                                            max={1000000}
                                            disabled={!canWrite}
                                            value={form.data.details[key] ?? ''}
                                            onChange={(e) =>
                                                form.setData('details', {
                                                    ...form.data.details,
                                                    [key]: e.target.value,
                                                })
                                            }
                                        />
                                    ) : (
                                        <Textarea
                                            id={`detail-${key}`}
                                            rows={2}
                                            maxLength={2000}
                                            disabled={!canWrite}
                                            value={String(
                                                form.data.details[key] ?? '',
                                            )}
                                            onChange={(e) =>
                                                form.setData('details', {
                                                    ...form.data.details,
                                                    [key]: e.target.value,
                                                })
                                            }
                                        />
                                    )}
                                </div>
                            ),
                        )}
                        <div className="wp-field">
                            <Label htmlFor="plan-completion">
                                Completion or closure notes
                                {form.data.status === 'completed'
                                    ? ' (required)'
                                    : ''}
                            </Label>
                            <Textarea
                                id="plan-completion"
                                rows={2}
                                maxLength={2000}
                                required={form.data.status === 'completed'}
                                disabled={!canWrite}
                                value={form.data.completion_note}
                                onChange={(e) =>
                                    form.setData(
                                        'completion_note',
                                        e.target.value,
                                    )
                                }
                                placeholder="What was done, the observed result and any follow-up"
                            />
                        </div>
                        {plan && (
                            <div className="wp-history">
                                <strong>Record history</strong>
                                <p>
                                    Last saved {formatDateTime(plan.updated_at)}{' '}
                                    · version {plan.version}
                                </p>
                                {plan.completed_at && (
                                    <p>
                                        Completed{' '}
                                        {formatDateTime(plan.completed_at)}
                                    </p>
                                )}
                                {history.map((revision) => (
                                    <p key={revision.version}>
                                        Version {revision.version} ·{' '}
                                        {statuses[revision.status]} ·{' '}
                                        {revision.progress}% ·{' '}
                                        {formatDateTime(revision.at)}
                                    </p>
                                ))}
                            </div>
                        )}
                        {discard && (
                            <div className="wp-discard" role="alert">
                                <span>You have unsaved changes.</span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setDiscard(false)}
                                >
                                    Keep editing
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={onClose}
                                >
                                    Discard changes
                                </Button>
                            </div>
                        )}
                        <div className="wp-form-actions">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={close}
                            >
                                {canWrite ? 'Cancel' : 'Close'}
                            </Button>
                            {canWrite && (
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {form.processing
                                        ? 'Saving…'
                                        : plan
                                          ? 'Save changes'
                                          : 'Add plan'}
                                </Button>
                            )}
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

export default function WorkPlans(props: Props) {
    const { definition, records, filters, counts, canWrite, sites } = props;
    const [editing, setEditing] = useState<Plan | 'new' | null>(props.selected);
    const [search, setSearch] = useState(filters.search ?? '');
    useEffect(() => {
        // Validation redirects contain fresh server records. Keep the mounted
        // editor (and its draft/version) until the user chooses to reload.
        if (Object.keys(props.errors ?? {}).length > 0) return;
        setEditing((current) =>
            current && current !== 'new' && current.id === props.selected?.id
                ? current
                : props.selected,
        );
    }, [props.selected, props.errors]);
    const base = `/work-plans/${definition.kind}`;
    function statusLink(status: string) {
        const query = new URLSearchParams({ status });
        if (filters.site) query.set('site', filters.site);
        if (filters.search) query.set('search', filters.search);
        return `${base}?${query}`;
    }
    function filter(values: Partial<Props['filters']>) {
        router.get(
            base,
            { ...filters, ...values, record: undefined },
            { preserveScroll: true },
        );
    }
    const open =
        (counts.planned ?? 0) +
        (counts.in_progress ?? 0) +
        (counts.blocked ?? 0);
    const canCreate =
        canWrite && (definition.scope !== 'site' || sites.length > 0);
    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: 'Overview',
                    href: `/dashboard?role=${definition.role}`,
                },
                { title: definition.title, href: base },
            ]}
        >
            <Head title={definition.title} />
            <div className="wp-workspace">
                <PageHeader
                    icon={ClipboardList}
                    title={definition.title}
                    frontline={definition.role === 'worker'}
                    subline={
                        definition.scope === 'personal'
                            ? 'Your private plans · owned by you'
                            : 'Clear owners, next steps and recorded outcomes'
                    }
                    titleChip={
                        <PageHeaderStatusChip variant="neutral">
                            Recorded by your team
                        </PageHeaderStatusChip>
                    }
                    actions={
                        <>
                            <PageHeaderGlassButton asChild>
                                <Link
                                    href={`/dashboard?role=${definition.role}`}
                                >
                                    <ArrowLeft size={16} />
                                    Overview
                                </Link>
                            </PageHeaderGlassButton>
                            {canCreate && (
                                <PageHeaderPrimaryButton
                                    onClick={() => setEditing('new')}
                                >
                                    <Plus size={16} />
                                    Add plan
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                href={statusLink('open')}
                                label="Open plans"
                            >
                                <PageHeaderMeterBig>{open}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Work still to finish
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                href={statusLink('overdue')}
                                label="Overdue"
                            >
                                <PageHeaderMeterBig>
                                    {counts.overdue ?? 0}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Due date has passed
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                href={statusLink('blocked')}
                                label="Blocked"
                            >
                                <PageHeaderMeterBig>
                                    {counts.blocked ?? 0}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Needs a next step
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                href={statusLink('completed')}
                                label="Completed"
                            >
                                <PageHeaderMeterBig>
                                    {counts.completed ?? 0}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Recorded outcomes
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                />
                <div className="wp-body">
                    <p className="wp-guide">
                        <ClipboardList size={18} />
                        {definition.guide}
                    </p>
                    <div className="wp-toolbar">
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                filter({ search });
                            }}
                            className="wp-search"
                        >
                            <Search size={17} />
                            <Input
                                aria-label="Search plans"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Find a plan…"
                            />
                            <Button type="submit" variant="outline">
                                Search
                            </Button>
                        </form>
                        {definition.scope === 'site' && (
                            <Select
                                value={String(filters.site ?? 'all')}
                                onValueChange={(site) =>
                                    filter({
                                        site: site === 'all' ? undefined : site,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="wp-filter"
                                    aria-label="Filter by site"
                                >
                                    <SelectValue placeholder="All permitted sites" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All permitted sites
                                    </SelectItem>
                                    {sites.map((site) => (
                                        <SelectItem
                                            key={site.id}
                                            value={String(site.id)}
                                        >
                                            {site.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <Select
                            value={filters.status ?? 'open'}
                            onValueChange={(status) => filter({ status })}
                        >
                            <SelectTrigger
                                className="wp-filter"
                                aria-label="Filter by status"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="open">Open plans</SelectItem>
                                <SelectItem value="attention">
                                    Needs attention
                                </SelectItem>
                                <SelectItem value="overdue">Overdue</SelectItem>
                                <SelectItem value="planned">Planned</SelectItem>
                                <SelectItem value="in_progress">
                                    In progress
                                </SelectItem>
                                <SelectItem value="blocked">Blocked</SelectItem>
                                <SelectItem value="completed">
                                    Completed
                                </SelectItem>
                                <SelectItem value="cancelled">
                                    Cancelled
                                </SelectItem>
                                <SelectItem value="all">All records</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="wp-result-heading">
                        <h2>
                            {filters.status === 'completed'
                                ? 'Completed work'
                                : filters.status === 'cancelled'
                                  ? 'Cancelled plans'
                                  : 'Your work plans'}
                        </h2>
                        <span>
                            {records.total}{' '}
                            {records.total === 1 ? 'record' : 'records'}
                        </span>
                    </div>
                    {records.data.length ? (
                        <div className="wp-grid">
                            {records.data.map((plan) => (
                                <article className="wp-card" key={plan.id}>
                                    <div className="wp-card-top">
                                        <StatusBadge
                                            variant={
                                                plan.status === 'blocked' ||
                                                plan.overdue
                                                    ? 'warning'
                                                    : 'neutral'
                                            }
                                        >
                                            {plan.overdue
                                                ? `Overdue · ${statuses[plan.status]}`
                                                : statuses[plan.status]}
                                        </StatusBadge>
                                        <span>#{plan.id}</span>
                                    </div>
                                    <h3>
                                        <Link
                                            href={`${base}?record=${plan.id}`}
                                            preserveScroll
                                        >
                                            {plan.title}
                                            <ArrowUpRight size={17} />
                                        </Link>
                                    </h3>
                                    <p className="wp-card-meta">
                                        <CalendarDays size={15} />
                                        Due{' '}
                                        {formatDateOnly(
                                            plan.due_date.slice(0, 10),
                                        )}
                                    </p>
                                    <p className="wp-card-meta">
                                        <UserRound size={15} />
                                        {plan.owner.name}
                                        {plan.site
                                            ? ` · ${plan.site.name}`
                                            : ''}
                                    </p>
                                    <div className="wp-progress-heading">
                                        <span>
                                            {plan.status === 'completed'
                                                ? 'Completed'
                                                : 'Progress'}
                                        </span>
                                        <strong>{plan.progress}%</strong>
                                    </div>
                                    <progress
                                        aria-label={`${plan.title} progress`}
                                        max={100}
                                        value={plan.progress}
                                    />
                                    <div className="wp-card-footer">
                                        <span>
                                            {plan.status === 'completed' ? (
                                                <CheckCheck size={15} />
                                            ) : (
                                                <Clock3 size={15} />
                                            )}
                                            Version {plan.version}
                                        </span>
                                        <Button asChild variant="ghost">
                                            <Link
                                                href={`${base}?record=${plan.id}`}
                                                preserveScroll
                                            >
                                                {canWrite
                                                    ? 'Open plan'
                                                    : 'View details'}
                                                <ArrowUpRight size={15} />
                                            </Link>
                                        </Button>
                                    </div>
                                </article>
                            ))}
                        </div>
                    ) : (
                        <div className="wp-empty">
                            <ClipboardList size={34} />
                            <h3>No plans in this view</h3>
                            <p>
                                {filters.search
                                    ? 'Try a different search or change the filters.'
                                    : canCreate
                                      ? 'Give the next piece of work an owner and a due date.'
                                      : 'Plans appear here when someone with access records them.'}
                            </p>
                            {canCreate && (
                                <Button onClick={() => setEditing('new')}>
                                    <Plus size={16} />
                                    Add your first plan
                                </Button>
                            )}
                        </div>
                    )}
                    {records.last_page > 1 && (
                        <nav aria-label="Plan pages" className="wp-pagination">
                            <Button
                                variant="outline"
                                disabled={!records.prev_page_url}
                                onClick={() =>
                                    records.prev_page_url &&
                                    router.get(records.prev_page_url)
                                }
                            >
                                Previous
                            </Button>
                            <span>
                                Page {records.current_page} of{' '}
                                {records.last_page}
                            </span>
                            <Button
                                variant="outline"
                                disabled={!records.next_page_url}
                                onClick={() =>
                                    records.next_page_url &&
                                    router.get(records.next_page_url)
                                }
                            >
                                Next
                            </Button>
                        </nav>
                    )}
                </div>
            </div>
            {editing && (
                <PlanEditor
                    key={
                        editing === 'new'
                            ? 'new'
                            : `${editing.id}-${editing.version}`
                    }
                    {...props}
                    plan={editing === 'new' ? null : editing}
                    history={editing === 'new' ? [] : props.history}
                    onClose={() => setEditing(null)}
                />
            )}
        </AppLayout>
    );
}
