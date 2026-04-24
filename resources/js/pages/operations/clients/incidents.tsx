import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Activity,
    Pill,
    Users,
    Shield,
    Eye,
    HelpCircle,
    XCircle,
    Calendar,
    User,
    CheckCircle2,
    Clock,
    FileEdit,
    MoreVertical,
    Plus,
    ShieldAlert,
    Send,
    ExternalLink,
    Copy,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type Props = {
    client: { id: number; first_name: string; last_name: string; status: string };
    incidents: Array<any>;
    templates: Array<any>;
    can: { create: boolean; templatesManage: boolean };
};

const severityConfig: Record<string, { bg: string; text: string; dot: string; border: string }> = {
    low: { bg: 'bg-emerald-50', text: 'text-emerald-700', dot: 'bg-emerald-500', border: 'border-l-emerald-500' },
    medium: { bg: 'bg-amber-50', text: 'text-amber-700', dot: 'bg-amber-500', border: 'border-l-amber-500' },
    high: { bg: 'bg-red-50', text: 'text-red-700', dot: 'bg-red-500', border: 'border-l-red-500' },
    critical: { bg: 'bg-red-100', text: 'text-red-800', dot: 'bg-red-600', border: 'border-l-red-600' },
};

const statusConfig: Record<string, { bg: string; text: string; icon: typeof Clock }> = {
    draft: { bg: 'bg-muted', text: 'text-foreground', icon: FileEdit },
    submitted: { bg: 'bg-blue-100', text: 'text-blue-700', icon: Clock },
    reviewed: { bg: 'bg-primary/10', text: 'text-primary', icon: CheckCircle2 },
    closed: { bg: 'bg-green-100', text: 'text-green-700', icon: CheckCircle2 },
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

export default function ClientIncidents({ client, incidents, templates, can }: Props) {
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
        if (t.default_description && !form.data.description) form.setData('description', t.default_description);
    };

    const draftCount = incidents.filter((i) => i.status === 'draft').length;
    const highCount = incidents.filter((i) => i.severity === 'high' || i.severity === 'critical').length;
    const awaitingReview = incidents.filter((i) => i.status === 'submitted').length;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Operations', href: '/operations' },
                { title: labels?.['client.plural'] ?? 'Clients', href: '/operations/clients' },
                { title: name, href: `/operations/clients/${client.id}` },
                { title: 'Incidents', href: `/operations/clients/${client.id}/incidents` },
            ]}
        >
            <Head title={`Incidents - ${name}`} />

            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-red-100">
                            <ShieldAlert className="h-5 w-5 text-red-600" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold">Incidents for {name}</h1>
                            <div className="text-sm text-muted-foreground">
                                {incidents.length} incident{incidents.length !== 1 ? 's' : ''} recorded
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href={`/operations/clients/${client.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back to {(labels?.['client.singular'] ?? 'Client').toLowerCase()}
                        </Link>
                        {can.create && (
                            <Button size="sm" onClick={() => setShowNew((v) => !v)}>
                                <Plus className="mr-1 h-4 w-4" />
                                {showNew ? 'Cancel' : 'New incident'}
                            </Button>
                        )}
                    </div>
                </div>

                {/* Quick stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-lg border bg-white p-3">
                        <div className="text-2xl font-bold">{incidents.length}</div>
                        <div className="text-xs text-muted-foreground">Total incidents</div>
                    </div>
                    <div className="rounded-lg border bg-white p-3">
                        <div className="text-2xl font-bold text-muted-foreground">{draftCount}</div>
                        <div className="text-xs text-muted-foreground">Drafts</div>
                    </div>
                    <div className="rounded-lg border bg-white p-3">
                        <div className={`text-2xl font-bold ${highCount > 0 ? 'text-red-600' : 'text-muted-foreground'}`}>{highCount}</div>
                        <div className="text-xs text-muted-foreground">High severity</div>
                    </div>
                    <div className="rounded-lg border bg-white p-3">
                        <div className="text-2xl font-bold text-blue-600">{awaitingReview}</div>
                        <div className="text-xs text-muted-foreground">Awaiting review</div>
                    </div>
                </div>

                {/* Inline create form */}
                {showNew && can.create && (
                    <Card>
                        <CardContent className="pt-5 space-y-5">
                            <div className="flex items-center gap-2 text-sm font-medium text-foreground">
                                <Plus className="h-4 w-4" />
                                New incident (draft)
                            </div>

                            {/* Template */}
                            {templates.length > 0 && (
                                <div className="space-y-1.5">
                                    <Label className="text-xs text-muted-foreground">Template (optional)</Label>
                                    <Select
                                        value={form.data.template_id || '__none__'}
                                        onValueChange={(v) => applyTemplate(v === '__none__' ? '' : v)}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Pick a template" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">None</SelectItem>
                                            {templates.map((t) => (
                                                <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}

                            {/* Type selection with icons */}
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">Type</Label>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    {typeOptions.map((opt) => {
                                        const Icon = opt.icon;
                                        const selected = form.data.type === opt.value;
                                        return (
                                            <button
                                                key={opt.value}
                                                type="button"
                                                onClick={() => form.setData('type', opt.value)}
                                                className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-all ${
                                                    selected
                                                        ? 'border-primary bg-primary/5 text-primary font-medium ring-1 ring-primary/20'
                                                        : 'hover:bg-muted text-muted-foreground'
                                                }`}
                                            >
                                                <Icon className="h-4 w-4 shrink-0" />
                                                {opt.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Severity buttons */}
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">Severity</Label>
                                <div className="flex gap-2">
                                    {(['low', 'medium', 'high'] as const).map((s) => {
                                        const colors = severityConfig[s];
                                        const selected = form.data.severity === s;
                                        return (
                                            <button
                                                key={s}
                                                type="button"
                                                onClick={() => form.setData('severity', s)}
                                                className={`flex items-center gap-2 rounded-lg border px-4 py-2 text-sm capitalize transition-all ${
                                                    selected
                                                        ? `${colors.bg} ${colors.text} font-medium ring-1 ring-current/20`
                                                        : 'hover:bg-muted text-muted-foreground'
                                                }`}
                                            >
                                                <span className={`h-2 w-2 rounded-full ${colors.dot}`} />
                                                {s}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Date and follow-up */}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label className="text-xs text-muted-foreground">Occurred at</Label>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.occurred_at}
                                        onChange={(e) => form.setData('occurred_at', e.target.value)}
                                    />
                                </div>
                                <div className="flex items-center gap-2 pt-6">
                                    <Checkbox
                                        checked={!!form.data.requires_followup}
                                        onCheckedChange={(v) => form.setData('requires_followup', !!v)}
                                    />
                                    <Label>Requires follow-up</Label>
                                </div>
                            </div>

                            {/* Text fields */}
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">Description</Label>
                                <Textarea
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    rows={3}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">Immediate action taken</Label>
                                <Textarea
                                    value={form.data.immediate_action_taken}
                                    onChange={(e) => form.setData('immediate_action_taken', e.target.value)}
                                    rows={2}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">Witnesses</Label>
                                <Textarea
                                    value={form.data.witnesses}
                                    onChange={(e) => form.setData('witnesses', e.target.value)}
                                    rows={2}
                                />
                            </div>

                            {/* Submit */}
                            <div className="flex items-center justify-end gap-2 border-t pt-4">
                                <Button variant="outline" size="sm" onClick={() => setShowNew(false)}>
                                    Cancel
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={form.processing}
                                    onClick={() =>
                                        form.post(`/operations/clients/${client.id}/incidents`, {
                                            onSuccess: () => {
                                                form.reset();
                                                setShowNew(false);
                                            },
                                        })
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
                        const sev = severityConfig[i.severity] ?? severityConfig.low;
                        const stat = statusConfig[i.status] ?? statusConfig.draft;
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
                                className={`group relative cursor-pointer rounded-lg border border-l-4 bg-white transition-all hover:shadow-md ${sev.border}`}
                                onClick={() => router.visit(`/incidents/${i.id}`)}
                            >
                                <div className="block px-4 py-3 pr-12">
                                    <div className="flex items-start gap-4">
                                        <div className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${sev.bg}`}>
                                            <TypeIcon className={`h-5 w-5 ${sev.text}`} />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="font-semibold capitalize">{i.type?.replace(/_/g, ' ')}</span>
                                                <span className="text-slate-300">|</span>
                                                <Badge className={`${sev.bg} ${sev.text} border-0 text-[10px] font-medium`}>
                                                    {i.severity}
                                                </Badge>
                                                <Badge className={`${stat.bg} ${stat.text} border-0 text-[10px] font-medium`}>
                                                    <StatusIcon className="mr-1 h-3 w-3" />
                                                    {i.status}
                                                </Badge>
                                                {i.is_notifiable && (
                                                    <Badge className="bg-red-100 text-red-700 border-0 text-[10px]">WorkSafe</Badge>
                                                )}
                                                {i.requires_followup && (
                                                    <Badge className="bg-primary/10 text-primary border-0 text-[10px]">Follow-up</Badge>
                                                )}
                                            </div>
                                            {preview && (
                                                <p className="mt-1 text-sm text-muted-foreground line-clamp-1">{preview}</p>
                                            )}
                                            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                {i.occurred_at && (
                                                    <span className="flex items-center gap-1">
                                                        <Calendar className="h-3 w-3" />
                                                        {formatDateTime(i.occurred_at)}
                                                    </span>
                                                )}
                                                <span className="text-muted-foreground">{i.shift_id ? 'Shift-linked' : 'Standalone'}</span>
                                                {i.reported_by?.name && (
                                                    <span className="text-muted-foreground">by {i.reported_by.name}</span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Three-dot menu */}
                                <div className="absolute right-2 top-2.5 z-10" onClick={(e) => e.stopPropagation()}>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <button className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-muted-foreground transition-colors">
                                                <MoreVertical className="h-4 w-4" />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" className="w-48">
                                            <DropdownMenuItem onClick={() => router.visit(`/incidents/${i.id}`)}>
                                                <ExternalLink className="mr-2 h-4 w-4" />
                                                Open incident
                                            </DropdownMenuItem>
                                            {i.status === 'draft' && (
                                                <DropdownMenuItem onClick={() => router.post(`/incidents/${i.id}/submit`)}>
                                                    <Send className="mr-2 h-4 w-4" />
                                                    Submit for review
                                                </DropdownMenuItem>
                                            )}
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                onClick={() => {
                                                    navigator.clipboard.writeText(`${window.location.origin}/incidents/${i.id}`);
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
                            <ShieldAlert className="h-10 w-10 text-slate-300" />
                            <div className="mt-2 text-sm font-medium text-muted-foreground">No incidents recorded</div>
                            <div className="text-xs text-muted-foreground">
                                {can.create ? 'Create your first incident above' : 'No incidents have been logged for this client'}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
