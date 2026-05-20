import { PageHero, PageLayout } from '@/components/page';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
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
    HelpCircle,
    MoreVertical,
    Pill,
    Plus,
    Send,
    Shield,
    ShieldAlert,
    Users,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

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
        border: 'border-l-emerald-500',
    },
    medium: {
        bg: 'bg-status-warning-bg',
        text: 'text-status-warning',
        dot: 'bg-status-warning',
        border: 'border-l-amber-500',
    },
    high: {
        bg: 'bg-status-critical-bg',
        text: 'text-status-critical',
        dot: 'bg-status-critical',
        border: 'border-l-red-500',
    },
    critical: {
        bg: 'bg-status-critical-bg',
        text: 'text-status-critical',
        dot: 'bg-status-critical',
        border: 'border-l-red-600',
    },
};

const statusConfig: Record<
    string,
    { bg: string; text: string; icon: typeof Clock }
> = {
    draft: { bg: 'bg-muted', text: 'text-foreground', icon: FileEdit },
    submitted: {
        bg: 'bg-status-info-bg',
        text: 'text-status-info',
        icon: Clock,
    },
    reviewed: { bg: 'bg-primary/10', text: 'text-primary', icon: CheckCircle2 },
    closed: {
        bg: 'bg-status-success-bg',
        text: 'text-status-success',
        icon: CheckCircle2,
    },
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

export default function ClientIncidents({
    client,
    incidents,
    templates,
    can,
}: Props) {
    const { labels } = usePage().props as any;
    const name = `${client.first_name} ${client.last_name}`.trim();
    const [showNew, setShowNew] = useState(false);

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

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['client.plural'] ?? 'Clients',
                    href: '/clients',
                },
                { title: name, href: `/clients/${client.id}` },
                { title: 'Incidents', href: `/clients/${client.id}/incidents` },
            ]}
        >
            <Head title={`Incidents - ${name}`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={AlertTriangle}
                        title={`Incidents for ${name}`}
                        description={`${incidents.length} incident${incidents.length !== 1 ? 's' : ''} recorded`}
                        stats={[
                            { label: 'Total', value: incidents.length },
                            { label: 'Drafts', value: draftCount },
                            { label: 'High severity', value: highCount },
                            { label: 'Awaiting review', value: awaitingReview },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    asChild
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Link href={`/clients/${client.id}`}>
                                        Back to{' '}
                                        {(
                                            labels?.['client.singular'] ?? 'Client'
                                        ).toLowerCase()}
                                    </Link>
                                </Button>
                                {can.create && (
                                    <Button
                                        size="sm"
                                        onClick={() => setShowNew((v) => !v)}
                                    >
                                        <Plus className="mr-1 h-4 w-4" />
                                        {showNew ? 'Cancel' : 'New incident'}
                                    </Button>
                                )}
                            </div>
                        }
                    />
                }
            >
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
                                            `/clients/${client.id}/incidents`,
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

                {/* Incident list */}
                <div className="space-y-2">
                    {incidents.map((i) => {
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
                                                <span className="text-muted-foreground">
                                                    |
                                                </span>
                                                <Badge
                                                    className={`${sev.bg} ${sev.text} border-0 text-[10px] font-medium`}
                                                >
                                                    {i.severity}
                                                </Badge>
                                                <Badge
                                                    className={`${stat.bg} ${stat.text} border-0 text-[10px] font-medium`}
                                                >
                                                    <StatusIcon className="mr-1 h-3 w-3" />
                                                    {i.status}
                                                </Badge>
                                                {i.is_notifiable && (
                                                    <Badge className="border-0 bg-status-critical-bg text-[10px] text-status-critical">
                                                        WorkSafe
                                                    </Badge>
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
                                                        {formatDateTime(
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

                    {!incidents.length && (
                        <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-12 text-center">
                            <ShieldAlert className="h-10 w-10 text-muted-foreground" />
                            <div className="mt-2 text-sm font-medium text-muted-foreground">
                                No incidents recorded
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {can.create
                                    ? 'Create your first incident above'
                                    : 'No incidents have been logged for this client'}
                            </div>
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
