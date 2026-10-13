import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { VehicleSearchSelect as SearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import InputError from '@/components/input-error';
import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    ListCaption,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { PageLayout } from '@/components/page/page-layout';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    CalendarDays,
    ClipboardList,
    Clock3,
    Eye,
    ListFilter,
    Pencil,
    Plus,
    UserRound,
} from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

type Status = 'planned' | 'in_progress' | 'blocked' | 'completed' | 'cancelled';
const statuses: Record<Status, string> = {
    planned: 'Planned',
    in_progress: 'In progress',
    blocked: 'Blocked',
    completed: 'Completed',
    cancelled: 'Cancelled',
};
const statusTone: Record<Status, StatusVariant> = {
    planned: 'neutral',
    in_progress: 'info',
    blocked: 'warning',
    completed: 'success',
    cancelled: 'neutral',
};
/** The list views the controller accepts as `status` (default: open). */
const statusFilters = [
    { value: 'open', label: 'Open plans' },
    { value: 'attention', label: 'Needs attention' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'planned', label: 'Planned' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'blocked', label: 'Blocked' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'all', label: 'All plans' },
];
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
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    selected: Plan | null;
    history: History;
    counts: Partial<Record<Status | 'overdue', number>>;
    filters: {
        site?: string;
        status?: string;
        search?: string;
        record?: string;
    };
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
    const [dueDateMissing, setDueDateMissing] = useState(false);
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
    const dueDateError = dueDateMissing
        ? 'Choose a due date.'
        : errors.due_date;
    function close() {
        if (form.isDirty) setDiscard(true);
        else onClose();
    }
    function save(event: FormEvent) {
        event.preventDefault();
        if (!form.data.due_date) {
            setDueDateMissing(true);
            document.getElementById('plan-date')?.focus();
            return;
        }
        const options = { preserveScroll: true, onSuccess: onClose };
        if (plan)
            form.put(`/work-plans/${definition.kind}/${plan.id}`, options);
        else form.post(`/work-plans/${definition.kind}`, options);
    }
    const siteMissing = definition.scope === 'site' && !form.data.site_id;
    const eligibleOwners = owners.filter(
        (owner) =>
            definition.scope !== 'site' ||
            owner.site_ids.includes(form.data.site_id ?? 0),
    );
    const ownerOptions = [
        ...eligibleOwners.map((owner) => ({
            value: String(owner.id),
            label: owner.name,
        })),
        ...(plan && !eligibleOwners.some((owner) => owner.id === plan.owner_id)
            ? [
                  {
                      value: String(plan.owner_id),
                      label: plan.owner.name,
                      description: 'Current owner',
                  },
              ]
            : []),
    ];
    return (
        <>
            {leave.confirmation}
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard unsaved plan changes?"
                description="Your changes to this plan have not been saved and will be lost."
                confirmText="Discard changes"
                cancelText="Keep editing"
            />
            <ConfirmDialog
                open={confirmReload}
                onClose={() => setConfirmReload(false)}
                onConfirm={() =>
                    leave.discardAndRun(() =>
                        router.get(
                            `/work-plans/${definition.kind}`,
                            { record: plan?.id },
                            { preserveState: false, preserveScroll: true },
                        ),
                    )
                }
                title="Reload the saved version?"
                description="Reloading replaces your unsaved changes with the latest saved version of this plan."
                confirmText="Discard draft and reload"
                cancelText="Keep my draft"
            />
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
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
                                : 'Visible to people with access to this workspace'}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={save} className="grid gap-4">
                        <p className="text-subtle rounded-lg border border-border bg-muted/40 px-3.5 py-3">
                            {definition.guide}
                        </p>
                        {Object.keys(errors).length > 0 && (
                            <div
                                className="grid gap-2 rounded-lg border border-status-critical/40 bg-status-critical-bg p-3.5 text-sm text-foreground"
                                role="alert"
                            >
                                <p className="font-semibold text-status-critical">
                                    Check the following before saving. Your
                                    changes are still here.
                                </p>
                                <ul className="list-disc pl-5">
                                    {Object.entries(errors).map(
                                        ([key, value]) => (
                                            <li key={key}>{value}</li>
                                        ),
                                    )}
                                </ul>
                                {errors.version && (
                                    <div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setConfirmReload(true)
                                            }
                                        >
                                            Reload saved version
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}
                        <div className="grid gap-2">
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
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor="plan-date">Due date</Label>
                                <DatePicker
                                    id="plan-date"
                                    label="Due date"
                                    value={form.data.due_date}
                                    onChange={(value) => {
                                        form.setData('due_date', value);
                                        setDueDateMissing(false);
                                    }}
                                    disabled={!canWrite}
                                    invalid={!!dueDateError}
                                    describedBy={
                                        dueDateError
                                            ? 'plan-date-error'
                                            : undefined
                                    }
                                />
                                <InputError
                                    id="plan-date-error"
                                    message={dueDateError}
                                />
                            </div>
                            <div className="grid content-start gap-2">
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
                        <div className="grid gap-4 sm:grid-cols-2">
                            {definition.scope === 'site' && (
                                <div className="grid content-start gap-2">
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
                            <div className="grid content-start gap-2">
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
                                    <>
                                        <SearchSelect
                                            id="plan-owner"
                                            label="Owner"
                                            value={
                                                siteMissing
                                                    ? ''
                                                    : String(form.data.owner_id)
                                            }
                                            options={ownerOptions}
                                            onChange={(value) =>
                                                form.setData(
                                                    'owner_id',
                                                    Number(value),
                                                )
                                            }
                                            disabled={siteMissing}
                                            invalid={!!errors.owner_id}
                                            describedBy={
                                                siteMissing
                                                    ? 'plan-owner-hint'
                                                    : undefined
                                            }
                                        />
                                        {siteMissing && (
                                            <p
                                                id="plan-owner-hint"
                                                className="text-caption"
                                            >
                                                Choose a site to see who can own
                                                this plan.
                                            </p>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                        <div className="grid gap-2">
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
                                <div key={key} className="grid gap-2">
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
                        <div className="grid gap-2">
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
                            <div className="text-caption grid gap-1 border-t border-border pt-3">
                                <p className="font-semibold text-foreground">
                                    Record history
                                </p>
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
                        <DialogFooter className="sticky -bottom-6 z-10 border-t border-border bg-background py-3">
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
                        </DialogFooter>
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
    const searchTimer = useRef<ReturnType<typeof setTimeout> | undefined>(
        undefined,
    );
    const ctxMenu = useEntityContextMenu<Plan>();
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
    // Keep the header search in step when the server echoes another query,
    // unless the user has typed a newer search that is still waiting to run.
    useEffect(() => {
        if (searchTimer.current === undefined) setSearch(filters.search ?? '');
    }, [filters.search]);
    useEffect(() => {
        const timer = searchTimer;
        return () => clearTimeout(timer.current);
    }, []);
    const base = `/work-plans/${definition.kind}`;
    function statusLink(status: string) {
        const query = new URLSearchParams({ status });
        if (filters.site) query.set('site', filters.site);
        if (filters.search) query.set('search', filters.search);
        return `${base}?${query}`;
    }
    function filter(values: Partial<Props['filters']>, replace = false) {
        router.get(
            base,
            { ...filters, ...values, record: undefined },
            { preserveScroll: true, preserveState: true, replace },
        );
    }
    function submitSearch(value: string) {
        clearTimeout(searchTimer.current);
        searchTimer.current = undefined;
        const next = value.trim();
        if (next === (filters.search ?? '')) return;
        filter({ search: next || undefined }, true);
    }
    function changeSearch(value: string) {
        setSearch(value);
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => submitSearch(value), 350);
    }
    function openPlan(plan: Plan) {
        router.get(
            base,
            { ...filters, record: plan.id },
            { preserveScroll: true, preserveState: true },
        );
    }
    // One action list feeds both the kebab and the right-click menu. Status
    // changes, completion and reopening all happen in the plan editor.
    const actionsFor = (plan: Plan): MenuItem[] => [
        {
            label: canWrite ? 'Open plan' : 'View details',
            icon: canWrite ? Pencil : Eye,
            onClick: () => openPlan(plan),
        },
    ];
    const open =
        (counts.planned ?? 0) +
        (counts.in_progress ?? 0) +
        (counts.blocked ?? 0);
    const canCreate =
        canWrite && (definition.scope !== 'site' || sites.length > 0);
    const statusView = filters.status ?? 'open';
    const statusLabel =
        statusFilters.find((option) => option.value === statusView)?.label ??
        'Open plans';
    const header = (
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
                    {definition.scope === 'personal'
                        ? 'Private to you'
                        : 'Recorded by your team'}
                </PageHeaderStatusChip>
            }
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={changeSearch}
                        onKeyDown={(event) => {
                            if (event.key !== 'Enter') return;
                            event.preventDefault();
                            submitSearch(search);
                        }}
                        placeholder="Search plan titles…"
                        ariaLabel="Search plans"
                    />
                    <PageHeaderGlassButton asChild>
                        <Link href={`/dashboard?role=${definition.role}`}>
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
            filters={
                <>
                    {definition.scope === 'site' && (
                        <PageHeaderFilterSelect
                            icon={Building2}
                            label="All your sites"
                            value={String(filters.site ?? 'all')}
                            options={[
                                { value: 'all', label: 'All your sites' },
                                ...sites.map((site) => ({
                                    value: String(site.id),
                                    label: site.name,
                                })),
                            ]}
                            onChange={(site) =>
                                filter({
                                    site: site === 'all' ? undefined : site,
                                })
                            }
                        />
                    )}
                    <PageHeaderFilterSelect
                        icon={ListFilter}
                        label="Open plans"
                        value={statusView}
                        allValue="open"
                        options={statusFilters}
                        onChange={(status) => filter({ status })}
                    />
                </>
            }
        />
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: 'Overview',
                    href: `/dashboard?role=${definition.role}`,
                },
                { title: definition.title },
            ]}
        >
            <Head title={definition.title} />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={statusLabel}
                        caption={`${records.data.length} of ${records.total} shown`}
                    />
                    {records.data.length ? (
                        <EntityCardGrid>
                            {records.data.map((plan) => (
                                <EntityCard
                                    key={plan.id}
                                    meridian={
                                        plan.overdue ||
                                        plan.status === 'blocked'
                                            ? 'warning'
                                            : 'success'
                                    }
                                    icon={ClipboardList}
                                    name={plan.title}
                                    subline={`Due ${formatDateOnly(
                                        plan.due_date.slice(0, 10),
                                    )}`}
                                    sublineIcon={CalendarDays}
                                    actions={actionsFor(plan)}
                                    onOpen={() => openPlan(plan)}
                                    onContextMenu={(event) =>
                                        ctxMenu.open(event, plan)
                                    }
                                    muted={plan.status === 'cancelled'}
                                    chips={
                                        <>
                                            <EntityStatusChip
                                                variant={
                                                    statusTone[plan.status]
                                                }
                                            >
                                                {statuses[plan.status]}
                                            </EntityStatusChip>
                                            {plan.site ? (
                                                <EntityChip icon={Building2}>
                                                    {plan.site.name}
                                                </EntityChip>
                                            ) : null}
                                        </>
                                    }
                                    metric={{
                                        label:
                                            plan.status === 'completed'
                                                ? 'Completed'
                                                : 'Progress',
                                        value: `${plan.progress}%`,
                                        percent: plan.progress,
                                        tone:
                                            plan.status === 'completed'
                                                ? 'success'
                                                : 'brand',
                                    }}
                                    alerts={
                                        plan.overdue ? (
                                            <EntityStatusChip
                                                variant="warning"
                                                icon={Clock3}
                                            >
                                                Overdue
                                            </EntityStatusChip>
                                        ) : undefined
                                    }
                                    footer={{
                                        personName: plan.owner.name,
                                        personIcon: UserRound,
                                        primary: plan.owner.name,
                                        secondary: 'Plan owner',
                                    }}
                                    openLabel={
                                        canWrite ? 'Open plan' : 'View details'
                                    }
                                />
                            ))}
                        </EntityCardGrid>
                    ) : (
                        <EmptyState
                            icon={ClipboardList}
                            title="No plans in this view"
                            description={
                                filters.search
                                    ? 'Try a different search or change the filters.'
                                    : canCreate
                                      ? 'Give the next piece of work an owner and a due date.'
                                      : 'Plans appear here when someone with access records them.'
                            }
                            action={
                                canCreate ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setEditing('new')}
                                    >
                                        <Plus className="size-4" />
                                        Add a plan
                                    </Button>
                                ) : undefined
                            }
                        />
                    )}
                    <LaravelPagination
                        links={records.links}
                        lastPage={records.last_page}
                    />
                </div>
            </PageLayout>
            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={ClipboardList}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}
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
