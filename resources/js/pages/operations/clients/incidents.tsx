import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Calendar,
    CheckCircle2,
    Clock,
    Copy,
    ExternalLink,
    Eye,
    FileEdit,
    Flag,
    HelpCircle,
    Layers,
    MoreVertical,
    Pill,
    Plus,
    Send,
    Shield,
    ShieldAlert,
    Users,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type Props = {
    client: {
        id: number;
        first_name: string;
        last_name: string;
        status: string;
    };
    incidents: Array<any>;
    templates: Array<any>;
    can: { create: boolean; templatesManage: boolean };
};

const severityConfig: Record<
    string,
    { bg: string; text: string; dot: string; border: string }
> = {
    low: {
        bg: 'bg-status-success-bg',
        text: 'text-status-success',
        dot: 'bg-status-success',
        border: 'border-l-status-success',
    },
    medium: {
        bg: 'bg-status-warning-bg',
        text: 'text-status-warning',
        dot: 'bg-status-warning',
        border: 'border-l-status-warning',
    },
    high: {
        bg: 'bg-status-critical-bg',
        text: 'text-status-critical',
        dot: 'bg-status-critical',
        border: 'border-l-status-critical',
    },
    critical: {
        bg: 'bg-status-critical-bg',
        text: 'text-status-critical',
        dot: 'bg-status-critical',
        border: 'border-l-status-critical',
    },
};

const SEVERITY_BADGE: Record<string, StatusVariant> = {
    low: 'success',
    medium: 'warning',
    high: 'critical',
    critical: 'critical',
};

const statusConfig: Record<
    string,
    { variant: StatusVariant; label: string; icon: typeof Clock }
> = {
    draft: { variant: 'neutral', label: 'Draft', icon: FileEdit },
    submitted: { variant: 'info', label: 'Submitted', icon: Clock },
    reviewed: { variant: 'info', label: 'Reviewed', icon: CheckCircle2 },
    closed: { variant: 'success', label: 'Closed', icon: CheckCircle2 },
};

const typeIcons: Record<string, typeof AlertTriangle> = {
    injury: Activity,
    behaviour: Users,
    medication: Pill,
    safeguarding: Shield,
    near_miss: Eye,
    property_damage: AlertTriangle,
    missing_person: ShieldAlert,
    complaint: XCircle,
    other: HelpCircle,
    fall: AlertTriangle,
};

const typeOptions = [
    { value: 'injury', label: 'Injury', icon: Activity },
    { value: 'behaviour', label: 'Behaviour', icon: Users },
    { value: 'medication', label: 'Medication', icon: Pill },
    { value: 'safeguarding', label: 'Safeguarding', icon: Shield },
    { value: 'property_damage', label: 'Property damage', icon: AlertTriangle },
    { value: 'missing_person', label: 'Missing person', icon: ShieldAlert },
    { value: 'complaint', label: 'Complaint', icon: XCircle },
    { value: 'other', label: 'Other', icon: HelpCircle },
];

const SEVERITY_FILTER_OPTIONS = [
    { value: 'all', label: 'All severities' },
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];

type ViewKey = 'all' | 'drafts' | 'submitted' | 'high' | 'followup';

export default function ClientIncidents({
    client,
    incidents,
    templates,
    can,
}: Props) {
    const { labels } = usePage().props as any;
    const name = `${client.first_name} ${client.last_name}`.trim();
    const [showNew, setShowNew] = useState(false);

    const [view, setView] = useState<ViewKey>('all');
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('all');
    const [severityFilter, setSeverityFilter] = useState('all');

    const form = useForm({
        template_id: '',
        type: 'injury',
        severity: 'low',
        occurred_at: '',
        description: '',
        requires_followup: false,
        immediate_action_taken: '',
        witnesses: '',
    });

    const applyTemplate = (templateId: string) => {
        form.setData('template_id', templateId);
        const t = templates.find((x) => String(x.id) === String(templateId));
        if (!t) return;
        if (t.type) form.setData('type', t.type);
        if (t.severity) form.setData('severity', t.severity);
        if (t.default_description && !form.data.description)
            form.setData('description', t.default_description);
    };

    const draftCount = incidents.filter((i) => i.status === 'draft').length;
    const highCount = incidents.filter(
        (i) => i.severity === 'high' || i.severity === 'critical',
    ).length;
    const awaitingReview = incidents.filter(
        (i) => i.status === 'submitted',
    ).length;
    const followupCount = incidents.filter((i) => i.requires_followup).length;
    const typesPresent = new Set(incidents.map((i) => i.type ?? 'other')).size;

    const typeFilterOptions = useMemo(() => {
        const known = typeOptions.map((t) => ({
            value: t.value,
            label: t.label,
        }));
        const extras = Array.from(
            new Set(
                incidents
                    .map((i) => i.type)
                    .filter(
                        (t): t is string =>
                            !!t && !typeOptions.some((o) => o.value === t),
                    ),
            ),
        ).map((t) => ({
            value: t,
            label: t
                .replace(/_/g, ' ')
                .replace(/^\w/, (m: string) => m.toUpperCase()),
        }));
        return [{ value: 'all', label: 'All types' }, ...known, ...extras];
    }, [incidents]);

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();
        return incidents.filter((i) => {
            switch (view) {
                case 'drafts':
                    if (i.status !== 'draft') return false;
                    break;
                case 'submitted':
                    if (i.status !== 'submitted') return false;
                    break;
                case 'high':
                    if (i.severity !== 'high' && i.severity !== 'critical')
                        return false;
                    break;
                case 'followup':
                    if (!i.requires_followup) return false;
                    break;
            }
            if (typeFilter !== 'all' && i.type !== typeFilter) return false;
            if (severityFilter !== 'all' && i.severity !== severityFilter)
                return false;
            if (q) {
                const hay = `${i.type ?? ''} ${i.severity ?? ''} ${
                    i.status ?? ''
                } ${i.description ?? ''} ${
                    i.reported_by?.name ?? ''
                }`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [incidents, view, search, typeFilter, severityFilter]);

    const hasNarrowing =
        search.trim() !== '' ||
        typeFilter !== 'all' ||
        severityFilter !== 'all';

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        {
            key: 'all',
            label: 'All incidents',
            icon: Layers,
            count: incidents.length,
        },
        { key: 'drafts', label: 'Drafts', icon: FileEdit, count: draftCount },
        {
            key: 'submitted',
            label: 'Awaiting review',
            icon: Clock,
            count: awaitingReview,
        },
        {
            key: 'high',
            label: 'High severity',
            icon: AlertTriangle,
            count: highCount,
            alert: true,
        },
        {
            key: 'followup',
            label: 'Follow-up',
            icon: Flag,
            count: followupCount,
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All incidents';

    const titleChip =
        highCount > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {highCount} high severity
            </PageHeaderStatusChip>
        ) : awaitingReview > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {awaitingReview} awaiting review
            </PageHeaderStatusChip>
        ) : draftCount > 0 ? (
            <PageHeaderStatusChip variant="info">
                {draftCount} {draftCount === 1 ? 'draft' : 'drafts'}
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                Up to date
            </PageHeaderStatusChip>
        );

    const header = (
        <PageHeader
            variant="profile"
            backHref={`/operations/clients/${client.id}`}
            icon={ShieldAlert}
            title={name}
            titleChip={titleChip}
            subline={`Incident reports · ${incidents.length} recorded · ${followupCount} ${
                followupCount === 1 ? 'needs' : 'need'
            } follow-up`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search incidents…"
                    />
                    {can.create ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setShowNew((v) => !v)}
                        >
                            {showNew ? 'Cancel' : 'New incident'}
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total incidents"
                        ariaLabel="View all incidents"
                        onClick={() => setView('all')}
                    >
                        <PageHeaderMeterBig>
                            {incidents.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {typesPresent}{' '}
                            {typesPresent === 1 ? 'type' : 'types'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="High severity"
                        tone={highCount > 0 ? 'critical' : 'success'}
                        ariaLabel="View high severity incidents"
                        onClick={() => setView('high')}
                    >
                        <PageHeaderMeterBig>{highCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            high or critical
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Awaiting review"
                        tone={awaitingReview > 0 ? 'warning' : 'success'}
                        ariaLabel="View incidents awaiting review"
                        onClick={() => setView('submitted')}
                    >
                        <PageHeaderMeterBig>
                            {awaitingReview}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            submitted, not yet reviewed
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Drafts"
                        ariaLabel="View draft incidents"
                        onClick={() => setView('drafts')}
                    >
                        <PageHeaderMeterBig>{draftCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            not yet submitted
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Follow-up"
                        tone={followupCount > 0 ? 'warning' : 'success'}
                        ariaLabel="View incidents needing follow-up"
                        onClick={() => setView('followup')}
                    >
                        <PageHeaderMeterBig>{followupCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            actions outstanding
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={HelpCircle}
                        label="All types"
                        value={typeFilter}
                        options={typeFilterOptions}
                        onChange={setTypeFilter}
                    />
                    <PageHeaderFilterSelect
                        icon={AlertTriangle}
                        label="All severities"
                        value={severityFilter}
                        options={SEVERITY_FILTER_OPTIONS}
                        onChange={setSeverityFilter}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={setView}
                    ariaLabel="Incident views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: labels?.['client.plural'] ?? 'Clients',
                    href: '/operations/clients',
                },
                { title: name, href: `/operations/clients/${client.id}` },
                {
                    title: 'Incidents',
                    href: `/operations/clients/${client.id}/incidents`,
                },
            ]}
        >
            <Head title={`Incidents · ${name}`} />

            <PageLayout hero={header}>
                {/* Inline create form */}
                {showNew && can.create && (
                    <Card>
                        <CardContent className="space-y-5 pt-5">
                            <div className="flex items-center gap-2 text-sm font-medium text-foreground">
                                <Plus className="h-4 w-4" />
                                New incident (draft)
                            </div>

                            {/* Template */}
                            {templates.length > 0 && (
                                <div className="space-y-1.5">
                                    <Label className="text-xs text-muted-foreground">
                                        Template (optional)
                                    </Label>
                                    <Select
                                        value={
                                            form.data.template_id || '__none__'
                                        }
                                        onValueChange={(v) =>
                                            applyTemplate(
                                                v === '__none__' ? '' : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pick a template" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
                                                None
                                            </SelectItem>
                                            {templates.map((t) => (
                                                <SelectItem
                                                    key={t.id}
                                                    value={String(t.id)}
                                                >
                                                    {t.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}

                            {/* Type selection with icons */}
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">
                                    Type
                                </Label>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    {typeOptions.map((opt) => {
                                        const Icon = opt.icon;
                                        const selected =
                                            form.data.type === opt.value;
                                        return (
                                            <Button
                                                key={opt.value}
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    form.setData(
                                                        'type',
                                                        opt.value,
                                                    )
                                                }
                                                className={`h-auto justify-start gap-2 rounded-lg px-3 py-2 text-sm transition-all ${
                                                    selected
                                                        ? 'border-primary bg-primary/5 font-medium text-primary ring-1 ring-primary/20'
                                                        : 'text-muted-foreground hover:bg-muted'
                                                }`}
                                            >
                                                <Icon className="h-4 w-4 shrink-0" />
                                                {opt.label}
                                            </Button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Severity buttons */}
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">
                                    Severity
                                </Label>
                                <div className="flex gap-2">
                                    {(['low', 'medium', 'high'] as const).map(
                                        (s) => {
                                            const colors = severityConfig[s];
                                            const selected =
                                                form.data.severity === s;
                                            return (
                                                <Button
                                                    key={s}
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        form.setData(
                                                            'severity',
                                                            s,
                                                        )
                                                    }
                                                    className={`h-auto justify-start gap-2 rounded-lg px-4 py-2 text-sm capitalize transition-all ${
                                                        selected
                                                            ? `${colors.bg} ${colors.text} font-medium ring-1 ring-current/20`
                                                            : 'text-muted-foreground hover:bg-muted'
                                                    }`}
                                                >
                                                    <span
                                                        className={`h-2 w-2 rounded-full ${colors.dot}`}
                                                    />
                                                    {s}
                                                </Button>
                                            );
                                        },
                                    )}
                                </div>
                            </div>

                            {/* Date and follow-up */}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label className="text-xs text-muted-foreground">
                                        Occurred at
                                    </Label>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.occurred_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'occurred_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="flex items-center gap-2 pt-6">
                                    <Checkbox
                                        checked={!!form.data.requires_followup}
                                        onCheckedChange={(v) =>
                                            form.setData(
                                                'requires_followup',
                                                !!v,
                                            )
                                        }
                                    />
                                    <Label>Requires follow-up</Label>
                                </div>
                            </div>

                            {/* Text fields */}
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">
                                    Description
                                </Label>
                                <Textarea
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    rows={3}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">
                                    Immediate action taken
                                </Label>
                                <Textarea
                                    value={form.data.immediate_action_taken}
                                    onChange={(e) =>
                                        form.setData(
                                            'immediate_action_taken',
                                            e.target.value,
                                        )
                                    }
                                    rows={2}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">
                                    Witnesses
                                </Label>
                                <Textarea
                                    value={form.data.witnesses}
                                    onChange={(e) =>
                                        form.setData(
                                            'witnesses',
                                            e.target.value,
                                        )
                                    }
                                    rows={2}
                                />
                            </div>

                            {/* Submit */}
                            <div className="flex items-center justify-end gap-2 border-t pt-4">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setShowNew(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={form.processing}
                                    onClick={() =>
                                        form.post(
                                            `/operations/clients/${client.id}/incidents`,
                                            {
                                                onSuccess: () => {
                                                    form.reset();
                                                    setShowNew(false);
                                                },
                                            },
                                        )
                                    }
                                >
                                    Create draft
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <ListCaption
                    title={currentViewLabel}
                    caption={`${shown.length} of ${incidents.length} ${
                        incidents.length === 1 ? 'incident' : 'incidents'
                    } shown`}
                />

                {/* Incident list */}
                <div className="space-y-2">
                    {shown.map((i) => {
                        const sev =
                            severityConfig[i.severity] ?? severityConfig.low;
                        const stat =
                            statusConfig[i.status] ?? statusConfig.draft;
                        const TypeIcon = typeIcons[i.type] ?? AlertTriangle;
                        const StatusIcon = stat.icon;
                        const preview = i.description
                            ? i.description.length > 120
                                ? i.description.slice(0, 120) + '...'
                                : i.description
                            : null;

                        return (
                            <div
                                key={i.id}
                                className={`group relative cursor-pointer rounded-lg border border-l-4 bg-card transition-all hover:shadow-md ${sev.border}`}
                                onClick={() =>
                                    router.visit(`/incidents/${i.id}`)
                                }
                            >
                                <div className="block px-4 py-3 pr-12">
                                    <div className="flex items-start gap-4">
                                        <div
                                            className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${sev.bg}`}
                                        >
                                            <TypeIcon
                                                className={`h-5 w-5 ${sev.text}`}
                                            />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-semibold capitalize">
                                                    {i.type?.replace(/_/g, ' ')}
                                                </span>
                                                <StatusBadge
                                                    variant={
                                                        SEVERITY_BADGE[
                                                            i.severity
                                                        ] ?? 'neutral'
                                                    }
                                                    className="capitalize"
                                                >
                                                    {i.severity}
                                                </StatusBadge>
                                                <StatusBadge
                                                    variant={stat.variant}
                                                >
                                                    <StatusIcon className="h-3 w-3" />
                                                    {stat.label}
                                                </StatusBadge>
                                                {i.is_notifiable && (
                                                    <StatusBadge variant="critical">
                                                        WorkSafe
                                                    </StatusBadge>
                                                )}
                                                {i.requires_followup && (
                                                    <Badge className="border-0 bg-primary/10 text-[10px] text-primary">
                                                        Follow-up
                                                    </Badge>
                                                )}
                                            </div>
                                            {preview && (
                                                <p className="mt-1 line-clamp-1 text-sm text-muted-foreground">
                                                    {preview}
                                                </p>
                                            )}
                                            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                {i.occurred_at && (
                                                    <span className="flex items-center gap-1">
                                                        <Calendar className="h-3 w-3" />
                                                        {formatDateTimeLong(
                                                            i.occurred_at,
                                                        )}
                                                    </span>
                                                )}
                                                <span className="text-muted-foreground">
                                                    {i.shift_id
                                                        ? 'Shift-linked'
                                                        : 'Standalone'}
                                                </span>
                                                {i.reported_by?.name && (
                                                    <span className="text-muted-foreground">
                                                        by {i.reported_by.name}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Three-dot menu */}
                                <div
                                    className="absolute top-2.5 right-2 z-10"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <button className="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-muted-foreground">
                                                <MoreVertical className="h-4 w-4" />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            className="w-48"
                                        >
                                            <DropdownMenuItem
                                                onClick={() =>
                                                    router.visit(
                                                        `/incidents/${i.id}`,
                                                    )
                                                }
                                            >
                                                <ExternalLink className="mr-2 h-4 w-4" />
                                                Open incident
                                            </DropdownMenuItem>
                                            {i.status === 'draft' && (
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        router.post(
                                                            `/incidents/${i.id}/submit`,
                                                        )
                                                    }
                                                >
                                                    <Send className="mr-2 h-4 w-4" />
                                                    Submit for review
                                                </DropdownMenuItem>
                                            )}
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                onClick={() => {
                                                    navigator.clipboard.writeText(
                                                        `${window.location.origin}/incidents/${i.id}`,
                                                    );
                                                }}
                                            >
                                                <Copy className="mr-2 h-4 w-4" />
                                                Copy link
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>
                        );
                    })}

                    {!shown.length && (
                        <EmptyState
                            icon={ShieldAlert}
                            title="No incidents"
                            description={
                                hasNarrowing || view !== 'all'
                                    ? 'No incidents match this view or your filters.'
                                    : can.create
                                      ? 'Create the first incident with "New incident".'
                                      : 'No incidents have been logged for this client.'
                            }
                        />
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
