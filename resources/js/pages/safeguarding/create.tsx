import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import {
    Shield,
    ShieldAlert,
    AlertTriangle,
    User,
    Users,
    Briefcase,
    Calendar,
    MapPin,
    MessageSquare,
    ChevronDown,
    ChevronUp,
    Eye,
    FileEdit,
} from 'lucide-react';

type Props = {
    clients?: Array<{ id: number; first_name: string; last_name: string }>;
    staff?: Array<{ id: number; name?: string; first_name?: string; last_name?: string }>;
    sites?: Array<{ id: number; name: string }>;
    concern?: any;
};

const SUBJECT_TYPES = [
    { value: 'client', label: 'Client', icon: Users, color: 'border-status-info/30 bg-status-info-bg text-status-info hover:bg-status-info-bg' },
    { value: 'staff', label: 'Staff Member', icon: Briefcase, color: 'border-primary bg-primary/10 text-primary hover:bg-primary/10' },
    { value: 'other', label: 'Other Person', icon: User, color: 'border-border bg-muted text-foreground hover:bg-muted' },
];

const CONCERN_TYPES = [
    { value: 'concern', label: 'Concern', color: 'border-status-info/30 bg-status-info-bg text-status-info hover:bg-status-info-bg' },
    { value: 'allegation', label: 'Allegation', color: 'border-status-critical/30 bg-status-critical-bg text-status-critical hover:bg-status-critical-bg' },
    { value: 'disclosure', label: 'Disclosure', color: 'border-primary bg-primary/10 text-primary hover:bg-primary/10' },
    { value: 'observation', label: 'Observation', color: 'border-status-warning/30 bg-status-warning-bg text-status-warning hover:bg-status-warning-bg' },
    { value: 'third_party_report', label: 'Third Party Report', color: 'border-primary bg-primary/10 text-primary hover:bg-primary/10' },
];

const SEVERITY_OPTIONS = [
    { value: 'low', label: 'Low', color: 'border-status-info/30 bg-status-info-bg text-status-info', dot: 'bg-status-info', selectedBg: 'bg-status-info-bg ring-2 ring-status-info' },
    { value: 'medium', label: 'Medium', color: 'border-status-warning/30 bg-status-warning-bg text-status-warning', dot: 'bg-status-warning', selectedBg: 'bg-status-warning-bg ring-2 ring-status-warning' },
    { value: 'high', label: 'High', color: 'border-status-warning/30 bg-status-warning-bg text-status-warning', dot: 'bg-status-warning', selectedBg: 'bg-status-warning-bg ring-2 ring-status-warning' },
    { value: 'critical', label: 'Critical', color: 'border-status-critical/30 bg-status-critical-bg text-status-critical', dot: 'bg-status-critical', selectedBg: 'bg-status-critical-bg ring-2 ring-status-critical' },
];

const ABUSE_CATEGORIES = [
    { value: 'physical', label: 'Physical' },
    { value: 'sexual', label: 'Sexual' },
    { value: 'emotional', label: 'Emotional/Psychological' },
    { value: 'financial', label: 'Financial' },
    { value: 'neglect', label: 'Neglect' },
    { value: 'discriminatory', label: 'Discriminatory' },
    { value: 'domestic', label: 'Domestic Abuse' },
    { value: 'institutional', label: 'Institutional' },
    { value: 'self_neglect', label: 'Self-Neglect' },
    { value: 'modern_slavery', label: 'Modern Slavery' },
];

const PERPETRATOR_TYPES = [
    { value: 'client', label: 'Client' },
    { value: 'staff', label: 'Staff Member' },
    { value: 'family', label: 'Family Member' },
    { value: 'other', label: 'Other' },
];

export default function SafeguardingCreate({ clients = [], staff = [], sites = [], concern }: Props) {
    const isEdit = !!concern;
    const [siteSelection, setSiteSelection] = useState<string>(concern?.site_id ? String(concern.site_id) : (concern?.location ? 'other' : ''));
    const [perpetratorOpen, setPerpetratorOpen] = useState<boolean>(
        !!(concern?.alleged_perpetrator_type || concern?.alleged_perpetrator_id || concern?.other_perpetrator_name)
    );

    const { data, setData, post, put, processing, errors } = useForm({
        // Subject information
        subject_type: concern?.subject_type ?? '',
        subject_id: concern?.subject_id ? String(concern.subject_id) : '',
        other_subject_name: concern?.other_subject_name ?? '',

        // Concern details
        concern_type: concern?.concern_type ?? '',
        abuse_category: concern?.abuse_category ?? '',
        severity: concern?.severity ?? 'medium',
        description: concern?.description ?? '',
        occurred_at: concern?.occurred_at ? concern.occurred_at.slice(0, 16) : '',
        reported_at: concern?.reported_at ? concern.reported_at.slice(0, 16) : '',
        location: concern?.location ?? '',
        site_id: concern?.site_id ? String(concern.site_id) : '',
        witnesses: concern?.witnesses ?? '',

        // Alleged perpetrator
        alleged_perpetrator_type: concern?.alleged_perpetrator_type ?? '',
        alleged_perpetrator_id: concern?.alleged_perpetrator_id ? String(concern.alleged_perpetrator_id) : '',
        other_perpetrator_name: concern?.other_perpetrator_name ?? '',
        perpetrator_relationship: concern?.perpetrator_relationship ?? '',

        // Response
        immediate_action_taken: concern?.immediate_action_taken ?? false,
        immediate_action_description: concern?.immediate_action_description ?? '',
        police_notified: concern?.police_notified ?? false,
        police_reference: concern?.police_reference ?? '',
        requires_external_referral: concern?.requires_external_referral ?? false,
        suggested_referral_agencies: concern?.suggested_referral_agencies ?? '',

        // Mental Capacity
        subject_capacity_assessed: concern?.subject_capacity_assessed ?? false,
        subject_has_capacity: concern?.subject_has_capacity ?? '',
        capacity_assessment_notes: concern?.capacity_assessment_notes ?? '',

        // Subject informed
        subject_informed: concern?.subject_informed ?? false,
        subject_informed_at: concern?.subject_informed_at ? concern.subject_informed_at.slice(0, 16) : '',
        subject_response: concern?.subject_response ?? '',
        reason_not_informed: concern?.reason_not_informed ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (isEdit) {
            put(`/safeguarding/${concern.id}`);
        } else {
            post('/safeguarding');
        }
    };

    const selectedSubjectType = SUBJECT_TYPES.find((t) => t.value === data.subject_type);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Safeguarding', href: '/safeguarding' },
                { title: isEdit ? 'Edit Concern' : 'New Concern', href: isEdit ? `/safeguarding/${concern.id}/edit` : '/safeguarding/create' },
            ]}
        >
            <Head title={isEdit ? 'Edit Safeguarding Concern' : 'New Safeguarding Concern'} />

            <div className="mx-auto max-w-4xl space-y-6 pb-8">
                {/* Page header */}
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Shield className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight">
                                {isEdit ? 'Edit safeguarding concern' : 'Report safeguarding concern'}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {isEdit
                                    ? 'Update the details of this safeguarding concern.'
                                    : 'Record details of the safeguarding concern accurately and promptly.'
                                }
                            </p>
                        </div>
                    </div>
                    <Link href="/safeguarding" className="rounded-md border px-3 py-2 text-xs font-medium hover:bg-muted transition-colors">
                        Back
                    </Link>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    {/* Step 1: Subject */}
                    <Card>
                        <CardContent className="pt-5">
                            <div className="mb-4 flex items-center gap-2">
                                <div className="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">1</div>
                                <h2 className="font-semibold">Who is at risk?</h2>
                            </div>

                            <div className="space-y-4">
                                <div>
                                    <Label className="text-xs text-muted-foreground mb-2 block">Subject type *</Label>
                                    <div className="grid grid-cols-3 gap-3">
                                        {SUBJECT_TYPES.map((type) => {
                                            const Icon = type.icon;
                                            const isSelected = data.subject_type === type.value;
                                            return (
                                                <button
                                                    key={type.value}
                                                    type="button"
                                                    onClick={() => {
                                                        setData('subject_type', type.value);
                                                        setData('subject_id', '');
                                                        setData('other_subject_name', '');
                                                    }}
                                                    className={`flex flex-col items-center gap-2 rounded-lg border-2 p-4 text-sm font-medium transition-all ${
                                                        isSelected
                                                            ? `${type.color} ring-2 ring-offset-1`
                                                            : 'border-border bg-white text-muted-foreground hover:border-border hover:bg-muted'
                                                    }`}
                                                >
                                                    <Icon className="h-5 w-5" />
                                                    {type.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {errors.subject_type && <div className="mt-1 text-xs text-status-critical">{errors.subject_type}</div>}
                                </div>

                                {data.subject_type === 'client' && (
                                    <div className="max-w-md">
                                        <Label className="text-xs text-muted-foreground">Select client *</Label>
                                        <Select value={data.subject_id} onValueChange={(v) => setData('subject_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Choose a client" /></SelectTrigger>
                                            <SelectContent>
                                                {clients.map((c) => (
                                                    <SelectItem key={c.id} value={String(c.id)}>
                                                        {c.first_name} {c.last_name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.subject_id && <div className="mt-1 text-xs text-status-critical">{errors.subject_id}</div>}
                                    </div>
                                )}

                                {data.subject_type === 'staff' && (
                                    <div className="max-w-md">
                                        <Label className="text-xs text-muted-foreground">Select staff member *</Label>
                                        <Select value={data.subject_id} onValueChange={(v) => setData('subject_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Choose a staff member" /></SelectTrigger>
                                            <SelectContent>
                                                {staff.map((s) => {
                                                    const name = s.name ?? `${s.first_name ?? ''} ${s.last_name ?? ''}`.trim();
                                                    return (
                                                        <SelectItem key={s.id} value={String(s.id)}>
                                                            {name || `Staff #${s.id}`}
                                                        </SelectItem>
                                                    );
                                                })}
                                            </SelectContent>
                                        </Select>
                                        {errors.subject_id && <div className="mt-1 text-xs text-status-critical">{errors.subject_id}</div>}
                                    </div>
                                )}

                                {data.subject_type === 'other' && (
                                    <div className="max-w-md">
                                        <Label className="text-xs text-muted-foreground">Person name *</Label>
                                        <Input
                                            value={data.other_subject_name}
                                            onChange={(e) => setData('other_subject_name', e.target.value)}
                                            placeholder="Enter the person's full name"
                                        />
                                        {errors.other_subject_name && <div className="mt-1 text-xs text-status-critical">{errors.other_subject_name}</div>}
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Step 2: Concern Details */}
                    <Card>
                        <CardContent className="pt-5">
                            <div className="mb-4 flex items-center gap-2">
                                <div className="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">2</div>
                                <h2 className="font-semibold">What happened?</h2>
                            </div>

                            <div className="space-y-4">
                                {/* Concern type cards */}
                                <div>
                                    <Label className="text-xs text-muted-foreground mb-2 block">Concern type *</Label>
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                        {CONCERN_TYPES.map((type) => {
                                            const isSelected = data.concern_type === type.value;
                                            return (
                                                <button
                                                    key={type.value}
                                                    type="button"
                                                    onClick={() => setData('concern_type', type.value)}
                                                    className={`rounded-lg border-2 px-3 py-2.5 text-sm font-medium transition-all ${
                                                        isSelected
                                                            ? `${type.color} ring-2 ring-offset-1`
                                                            : 'border-border bg-white text-muted-foreground hover:border-border hover:bg-muted'
                                                    }`}
                                                >
                                                    {type.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {errors.concern_type && <div className="mt-1 text-xs text-status-critical">{errors.concern_type}</div>}
                                </div>

                                {/* Abuse category */}
                                <div className="max-w-md">
                                    <Label className="text-xs text-muted-foreground">Abuse category</Label>
                                    <Select value={data.abuse_category} onValueChange={(v) => setData('abuse_category', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select category" /></SelectTrigger>
                                        <SelectContent>
                                            {ABUSE_CATEGORIES.map((cat) => (
                                                <SelectItem key={cat.value} value={cat.value}>{cat.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.abuse_category && <div className="mt-1 text-xs text-status-critical">{errors.abuse_category}</div>}
                                </div>

                                {/* Severity buttons */}
                                <div>
                                    <Label className="text-xs text-muted-foreground mb-2 block">Severity *</Label>
                                    <div className="flex flex-wrap gap-3">
                                        {SEVERITY_OPTIONS.map((opt) => {
                                            const isSelected = data.severity === opt.value;
                                            return (
                                                <button
                                                    key={opt.value}
                                                    type="button"
                                                    onClick={() => setData('severity', opt.value)}
                                                    className={`flex items-center gap-2 rounded-lg border-2 px-4 py-2 text-sm font-medium transition-all ${
                                                        isSelected ? opt.selectedBg + ' ' + opt.color : opt.color
                                                    }`}
                                                >
                                                    <span className={`h-2.5 w-2.5 rounded-full ${opt.dot}`} />
                                                    {opt.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {errors.severity && <div className="mt-1 text-xs text-status-critical">{errors.severity}</div>}
                                </div>

                                {/* Description */}
                                <div>
                                    <Label className="text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1">
                                            <MessageSquare className="h-3 w-3" />
                                            Description of concern *
                                        </span>
                                    </Label>
                                    <Textarea
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        rows={6}
                                        placeholder="Provide a detailed description of the concern, including what happened, when, where, and who was involved..."
                                        className="mt-1"
                                    />
                                    {errors.description && <div className="mt-1 text-xs text-status-critical">{errors.description}</div>}
                                </div>

                                {/* Date/time and location */}
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            <span className="flex items-center gap-1">
                                                <Calendar className="h-3 w-3" />
                                                Occurred at
                                            </span>
                                        </Label>
                                        <Input
                                            type="datetime-local"
                                            value={data.occurred_at}
                                            onChange={(e) => setData('occurred_at', e.target.value)}
                                            className="mt-1"
                                        />
                                        {errors.occurred_at && <div className="mt-1 text-xs text-status-critical">{errors.occurred_at}</div>}
                                    </div>

                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            <span className="flex items-center gap-1">
                                                <Calendar className="h-3 w-3" />
                                                Reported at
                                            </span>
                                        </Label>
                                        <Input
                                            type="datetime-local"
                                            value={data.reported_at}
                                            onChange={(e) => setData('reported_at', e.target.value)}
                                            className="mt-1"
                                        />
                                        {errors.reported_at && <div className="mt-1 text-xs text-status-critical">{errors.reported_at}</div>}
                                    </div>

                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            <span className="flex items-center gap-1">
                                                <MapPin className="h-3 w-3" />
                                                Location / Site
                                            </span>
                                        </Label>
                                        <Select
                                            value={siteSelection}
                                            onValueChange={(v) => {
                                                setSiteSelection(v);
                                                if (v === 'other') {
                                                    setData('site_id', '');
                                                } else {
                                                    setData('site_id', v);
                                                    setData('location', '');
                                                }
                                            }}
                                        >
                                            <SelectTrigger className="mt-1"><SelectValue placeholder="Select site" /></SelectTrigger>
                                            <SelectContent>
                                                {sites.map((site) => (
                                                    <SelectItem key={site.id} value={String(site.id)}>
                                                        {site.name}
                                                    </SelectItem>
                                                ))}
                                                <SelectItem value="other">Other (not listed)</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {errors.site_id && <div className="mt-1 text-xs text-status-critical">{errors.site_id}</div>}
                                    </div>
                                </div>

                                {siteSelection === 'other' && (
                                    <div className="max-w-md">
                                        <Label className="text-xs text-muted-foreground">Other location *</Label>
                                        <Input
                                            value={data.location}
                                            onChange={(e) => setData('location', e.target.value)}
                                            placeholder="Enter location details"
                                        />
                                        {errors.location && <div className="mt-1 text-xs text-status-critical">{errors.location}</div>}
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Step 3: Alleged Perpetrator (expandable) */}
                    <Card>
                        <CardContent className="pt-5">
                            <button
                                type="button"
                                onClick={() => setPerpetratorOpen(!perpetratorOpen)}
                                className="flex w-full items-center justify-between"
                            >
                                <div className="flex items-center gap-2">
                                    <div className="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">3</div>
                                    <h2 className="font-semibold">Alleged Perpetrator</h2>
                                    <Badge variant="outline" className="text-[10px]">Optional</Badge>
                                </div>
                                {perpetratorOpen ? <ChevronUp className="h-4 w-4 text-muted-foreground" /> : <ChevronDown className="h-4 w-4 text-muted-foreground" />}
                            </button>

                            {perpetratorOpen && (
                                <div className="mt-4 space-y-4 border-t pt-4">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <Label className="text-xs text-muted-foreground">Perpetrator type</Label>
                                            <Select value={data.alleged_perpetrator_type} onValueChange={(v) => {
                                                setData('alleged_perpetrator_type', v);
                                                setData('alleged_perpetrator_id', '');
                                                setData('other_perpetrator_name', '');
                                            }}>
                                                <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                                <SelectContent>
                                                    {PERPETRATOR_TYPES.map((t) => (
                                                        <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        {data.alleged_perpetrator_type === 'client' && (
                                            <div>
                                                <Label className="text-xs text-muted-foreground">Select client</Label>
                                                <Select value={data.alleged_perpetrator_id} onValueChange={(v) => setData('alleged_perpetrator_id', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Choose a client" /></SelectTrigger>
                                                    <SelectContent>
                                                        {clients.map((c) => (
                                                            <SelectItem key={c.id} value={String(c.id)}>
                                                                {c.first_name} {c.last_name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}

                                        {data.alleged_perpetrator_type === 'staff' && (
                                            <div>
                                                <Label className="text-xs text-muted-foreground">Select staff member</Label>
                                                <Select value={data.alleged_perpetrator_id} onValueChange={(v) => setData('alleged_perpetrator_id', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Choose a staff member" /></SelectTrigger>
                                                    <SelectContent>
                                                        {staff.map((s) => {
                                                            const name = s.name ?? `${s.first_name ?? ''} ${s.last_name ?? ''}`.trim();
                                                            return (
                                                                <SelectItem key={s.id} value={String(s.id)}>
                                                                    {name || `Staff #${s.id}`}
                                                                </SelectItem>
                                                            );
                                                        })}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}

                                        {(data.alleged_perpetrator_type === 'family' || data.alleged_perpetrator_type === 'other') && (
                                            <div>
                                                <Label className="text-xs text-muted-foreground">Person name</Label>
                                                <Input
                                                    value={data.other_perpetrator_name}
                                                    onChange={(e) => setData('other_perpetrator_name', e.target.value)}
                                                    placeholder="Enter name"
                                                />
                                            </div>
                                        )}
                                    </div>

                                    <div className="max-w-md">
                                        <Label className="text-xs text-muted-foreground">Relationship to subject</Label>
                                        <Input
                                            value={data.perpetrator_relationship}
                                            onChange={(e) => setData('perpetrator_relationship', e.target.value)}
                                            placeholder="e.g. Co-resident, Support worker, Family member"
                                        />
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Step 4: Immediate Response */}
                    <Card>
                        <CardContent className="pt-5">
                            <div className="mb-4 flex items-center gap-2">
                                <div className="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">4</div>
                                <h2 className="font-semibold">Immediate Response</h2>
                            </div>

                            <div className="space-y-4">
                                {/* Immediate action */}
                                <div className="flex items-start space-x-3 rounded-lg border p-3 transition-colors hover:bg-muted">
                                    <Checkbox
                                        id="immediate_action"
                                        checked={data.immediate_action_taken}
                                        onCheckedChange={(checked) => setData('immediate_action_taken', !!checked)}
                                        className="mt-0.5"
                                    />
                                    <div className="flex-1">
                                        <Label htmlFor="immediate_action" className="cursor-pointer font-medium">Immediate action was taken</Label>
                                        <p className="text-xs text-muted-foreground">Was any immediate action taken to ensure safety?</p>
                                    </div>
                                </div>

                                {data.immediate_action_taken && (
                                    <div className="ml-8">
                                        <Label className="text-xs text-muted-foreground">Describe immediate action taken</Label>
                                        <Textarea
                                            value={data.immediate_action_description}
                                            onChange={(e) => setData('immediate_action_description', e.target.value)}
                                            rows={3}
                                            placeholder="What steps were taken to ensure the person's immediate safety?"
                                        />
                                    </div>
                                )}

                                {/* Police notified */}
                                <div className="flex items-start space-x-3 rounded-lg border p-3 transition-colors hover:bg-muted">
                                    <Checkbox
                                        id="police_notified"
                                        checked={data.police_notified}
                                        onCheckedChange={(checked) => setData('police_notified', !!checked)}
                                        className="mt-0.5"
                                    />
                                    <div className="flex-1">
                                        <Label htmlFor="police_notified" className="cursor-pointer font-medium">Police were notified</Label>
                                        <p className="text-xs text-muted-foreground">Were NZ Police contacted regarding this concern?</p>
                                    </div>
                                </div>

                                {data.police_notified && (
                                    <div className="ml-8 max-w-md">
                                        <Label className="text-xs text-muted-foreground">Police reference number</Label>
                                        <Input
                                            value={data.police_reference}
                                            onChange={(e) => setData('police_reference', e.target.value)}
                                            placeholder="Enter reference number"
                                        />
                                    </div>
                                )}

                                {/* External referral */}
                                <div className="flex items-start space-x-3 rounded-lg border p-3 transition-colors hover:bg-muted">
                                    <Checkbox
                                        id="requires_referral"
                                        checked={data.requires_external_referral}
                                        onCheckedChange={(checked) => setData('requires_external_referral', !!checked)}
                                        className="mt-0.5"
                                    />
                                    <div className="flex-1">
                                        <Label htmlFor="requires_referral" className="cursor-pointer font-medium">Requires external referral</Label>
                                        <p className="text-xs text-muted-foreground">Does this need to be referred to an external agency?</p>
                                    </div>
                                </div>

                                {data.requires_external_referral && (
                                    <div className="ml-8">
                                        <Label className="text-xs text-muted-foreground">Suggested referral agencies</Label>
                                        <Textarea
                                            value={data.suggested_referral_agencies}
                                            onChange={(e) => setData('suggested_referral_agencies', e.target.value)}
                                            rows={2}
                                            placeholder="Which agencies should this be referred to?"
                                        />
                                    </div>
                                )}

                                {/* Witnesses */}
                                <div>
                                    <Label className="text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1">
                                            <Users className="h-3 w-3" />
                                            Witnesses
                                        </span>
                                    </Label>
                                    <Textarea
                                        value={data.witnesses}
                                        onChange={(e) => setData('witnesses', e.target.value)}
                                        rows={2}
                                        placeholder="Names and details of any witnesses..."
                                        className="mt-1"
                                    />
                                    {errors.witnesses && <div className="mt-1 text-xs text-status-critical">{errors.witnesses}</div>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Footer actions */}
                    <div className="flex items-center justify-end gap-3 border-t pt-4">
                        <Button type="button" variant="outline" onClick={() => router.visit('/safeguarding')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing} className="bg-primary hover:bg-primary">
                            <Shield className="mr-1.5 h-4 w-4" />
                            {processing
                                ? (isEdit ? 'Saving...' : 'Reporting...')
                                : (isEdit ? 'Save Changes' : 'Report Concern')
                            }
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
