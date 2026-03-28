import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlertTriangle,
    ShieldAlert,
    Clock,
    User,
    FileText,
    ChevronDown,
    ChevronUp,
    Activity,
    Pill,
    Shield,
    Eye,
    HelpCircle,
    Zap,
} from 'lucide-react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    templates: Array<any>;
};

const INCIDENT_TYPES = [
    { value: 'injury', label: 'Injury', icon: Activity, color: 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100' },
    { value: 'behaviour', label: 'Behaviour', icon: User, color: 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100' },
    { value: 'medication', label: 'Medication', icon: Pill, color: 'border-purple-200 bg-purple-50 text-purple-700 hover:bg-purple-100' },
    { value: 'safeguarding', label: 'Safeguarding', icon: Shield, color: 'border-orange-200 bg-orange-50 text-orange-700 hover:bg-orange-100' },
    { value: 'near_miss', label: 'Near miss', icon: Eye, color: 'border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100' },
    { value: 'other', label: 'Other', icon: HelpCircle, color: 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100' },
];

const SEVERITY_OPTIONS = [
    { value: 'low', label: 'Low', color: 'border-emerald-300 bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500', selectedBg: 'bg-emerald-100 ring-2 ring-emerald-500' },
    { value: 'medium', label: 'Medium', color: 'border-amber-300 bg-amber-50 text-amber-700', dot: 'bg-amber-500', selectedBg: 'bg-amber-100 ring-2 ring-amber-500' },
    { value: 'high', label: 'High', color: 'border-red-300 bg-red-50 text-red-700', dot: 'bg-red-500', selectedBg: 'bg-red-100 ring-2 ring-red-500' },
];

const INJURED_PERSON_ROLES = [
    { value: 'staff', label: 'Staff' },
    { value: 'client', label: 'Client' },
    { value: 'visitor', label: 'Visitor' },
    { value: 'contractor', label: 'Contractor' },
];

const INJURY_NATURES = [
    { value: 'fracture', label: 'Fracture' },
    { value: 'burn', label: 'Burn' },
    { value: 'laceration', label: 'Laceration' },
    { value: 'sprain', label: 'Sprain' },
    { value: 'bruising', label: 'Bruising' },
    { value: 'concussion', label: 'Concussion' },
    { value: 'poisoning', label: 'Poisoning' },
    { value: 'other', label: 'Other' },
];

const INJURY_CLASSIFICATIONS = [
    { value: 'minor', label: 'Minor' },
    { value: 'moderate', label: 'Moderate' },
    { value: 'serious', label: 'Serious' },
    { value: 'notifiable', label: 'Notifiable' },
];

const MEDICAL_TREATMENT_TYPES = [
    { value: 'none', label: 'None' },
    { value: 'first_aid', label: 'First aid' },
    { value: 'medical_centre', label: 'Medical centre' },
    { value: 'hospital', label: 'Hospital' },
    { value: 'ambulance', label: 'Ambulance' },
];

export default function IncidentCreate({ clients, templates }: Props) {
    const { auth, labels } = usePage().props as any;
    const can = auth?.can?.incidents ?? {};
    const clientSingular = labels?.['client.singular'] ?? 'Client';

    const [injuryOpen, setInjuryOpen] = useState(false);

    const form = useForm({
        client_id: '',
        template_id: '',
        type: 'injury',
        severity: 'low',
        occurred_at: '',
        description: '',
        requires_followup: false,
        immediate_action_taken: '',
        witnesses: '',
        // Near-miss fields
        potential_severity: '',
        potential_consequence: '',
        // Injury details
        injured_person_name: '',
        injured_person_role: '',
        injured_person_age: '',
        injury_body_part: '',
        injury_nature: '',
        injury_classification: '',
        medical_treatment_type: '',
        // WorkSafe
        is_notifiable: false,
    });

    const applyTemplate = (templateId: string) => {
        form.setData('template_id', templateId);
        const t = templates.find((x) => String(x.id) === String(templateId));
        if (!t) return;

        if (t.type) form.setData('type', t.type);
        if (t.severity) form.setData('severity', t.severity);
        if (t.default_description && !form.data.description) form.setData('description', t.default_description);
    };

    const isNearMiss = form.data.type === 'near_miss';
    const selectedType = INCIDENT_TYPES.find((t) => t.value === form.data.type);

    return (
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }, { title: 'New', href: '/incidents/create' }]}>
            <Head title="New incident" />

            <div className="mx-auto max-w-4xl space-y-6 pb-8">
                {/* Page header */}
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-red-100 text-red-600">
                                <AlertTriangle className="h-5 w-5" />
                            </div>
                            <div>
                                <h1 className="text-xl font-semibold tracking-tight">Report new incident</h1>
                                <p className="text-sm text-muted-foreground">This will be saved as a draft until submitted for review.</p>
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        {can.templatesManage && (
                            <Link href="/incidents/templates" className="rounded-md border px-3 py-2 text-xs font-medium hover:bg-muted transition-colors">
                                Templates
                            </Link>
                        )}
                        <Link href="/incidents" className="rounded-md border px-3 py-2 text-xs font-medium hover:bg-muted transition-colors">
                            Back
                        </Link>
                    </div>
                </div>

                {/* Section 1: Basic info */}
                <Card className="overflow-hidden">
                    <CardHeader className="border-b bg-muted/30 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">1</div>
                            <div>
                                <CardTitle className="text-base">Basic information</CardTitle>
                                <p className="text-xs text-muted-foreground mt-0.5">Link to a {clientSingular.toLowerCase()} and set the time of occurrence</p>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5 space-y-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label className="text-sm font-medium">{clientSingular}</Label>
                                <Select value={form.data.client_id || '__none__'} onValueChange={(v) => form.setData('client_id', v === '__none__' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder={`Select a ${clientSingular.toLowerCase()}`} /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">Select...</SelectItem>
                                        {clients.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-sm font-medium">Template (optional)</Label>
                                <Select value={form.data.template_id || '__none__'} onValueChange={(v) => applyTemplate(v === '__none__' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="Pick a template" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">None</SelectItem>
                                        {templates.map((t) => (
                                            <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="flex items-center gap-1.5 text-sm font-medium">
                                    <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                                    Occurred at
                                </Label>
                                <Input type="datetime-local" value={form.data.occurred_at} onChange={(e) => form.setData('occurred_at', e.target.value)} />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Section 2: Type & Severity */}
                <Card className="overflow-hidden">
                    <CardHeader className="border-b bg-muted/30 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">2</div>
                            <div>
                                <CardTitle className="text-base">Classification</CardTitle>
                                <p className="text-xs text-muted-foreground mt-0.5">Select the type and severity of this incident</p>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5 space-y-5">
                        {/* Type selector cards */}
                        <div className="space-y-2">
                            <Label className="text-sm font-medium">Incident type</Label>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                                {INCIDENT_TYPES.map((t) => {
                                    const Icon = t.icon;
                                    const isSelected = form.data.type === t.value;
                                    return (
                                        <button
                                            key={t.value}
                                            type="button"
                                            onClick={() => form.setData('type', t.value)}
                                            className={`flex flex-col items-center gap-1.5 rounded-lg border-2 p-3 text-center transition-all ${
                                                isSelected
                                                    ? `${t.color} ring-1 ring-current shadow-sm`
                                                    : 'border-border bg-background text-muted-foreground hover:border-border/80 hover:bg-muted/50'
                                            }`}
                                        >
                                            <Icon className="h-5 w-5" />
                                            <span className="text-xs font-medium">{t.label}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Severity selector cards */}
                        <div className="space-y-2">
                            <Label className="text-sm font-medium">Severity</Label>
                            <div className="grid grid-cols-3 gap-3">
                                {SEVERITY_OPTIONS.map((s) => {
                                    const isSelected = form.data.severity === s.value;
                                    return (
                                        <button
                                            key={s.value}
                                            type="button"
                                            onClick={() => form.setData('severity', s.value)}
                                            className={`flex items-center justify-center gap-2 rounded-lg border-2 px-4 py-3 font-medium transition-all ${
                                                isSelected
                                                    ? `${s.selectedBg} ${s.color}`
                                                    : `${s.color} opacity-60 hover:opacity-80`
                                            }`}
                                        >
                                            <span className={`h-2.5 w-2.5 rounded-full ${s.dot}`} />
                                            <span className="text-sm">{s.label}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                            <Checkbox id="requires_followup" checked={!!form.data.requires_followup} onCheckedChange={(v) => form.setData('requires_followup', !!v)} />
                            <div>
                                <Label htmlFor="requires_followup" className="text-sm font-medium cursor-pointer">Requires follow-up</Label>
                                <p className="text-xs text-muted-foreground">Flag this incident for mandatory follow-up actions</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Near-miss section */}
                {isNearMiss && (
                    <Card className="overflow-hidden border-amber-200">
                        <CardHeader className="border-b border-amber-200 bg-amber-50 pb-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-amber-500 text-xs font-bold text-white">
                                    <AlertTriangle className="h-4 w-4" />
                                </div>
                                <div>
                                    <CardTitle className="text-base text-amber-900">Near-miss details</CardTitle>
                                    <p className="text-xs text-amber-700 mt-0.5">Record what could have happened to help prevent future incidents</p>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="bg-amber-50/30 pt-5 space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Potential severity</Label>
                                    <Select value={form.data.potential_severity || '__none__'} onValueChange={(v) => form.setData('potential_severity', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {['low','medium','high','critical'].map((s) => (
                                                <SelectItem key={s} value={s}>{s}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-sm font-medium">Potential consequence</Label>
                                <Textarea
                                    value={form.data.potential_consequence}
                                    onChange={(e) => form.setData('potential_consequence', e.target.value)}
                                    placeholder="Describe what could have happened..."
                                    className="bg-white"
                                />
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Section 3: Description */}
                <Card className="overflow-hidden">
                    <CardHeader className="border-b bg-muted/30 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">3</div>
                            <div>
                                <CardTitle className="text-base">What happened</CardTitle>
                                <p className="text-xs text-muted-foreground mt-0.5">Provide a detailed account of the incident</p>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5 space-y-4">
                        <div className="space-y-1.5">
                            <Label className="flex items-center gap-1.5 text-sm font-medium">
                                <FileText className="h-3.5 w-3.5 text-muted-foreground" />
                                Description
                            </Label>
                            <Textarea
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Describe what happened in detail..."
                                rows={4}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label className="flex items-center gap-1.5 text-sm font-medium">
                                <Zap className="h-3.5 w-3.5 text-muted-foreground" />
                                Immediate action taken
                            </Label>
                            <Textarea
                                value={form.data.immediate_action_taken}
                                onChange={(e) => form.setData('immediate_action_taken', e.target.value)}
                                placeholder="What actions were taken immediately after the incident?"
                                rows={3}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label className="flex items-center gap-1.5 text-sm font-medium">
                                <User className="h-3.5 w-3.5 text-muted-foreground" />
                                Witnesses
                            </Label>
                            <Textarea
                                value={form.data.witnesses}
                                onChange={(e) => form.setData('witnesses', e.target.value)}
                                placeholder="Names and contact details of any witnesses..."
                                rows={2}
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* Section 4: Injury details (expandable) */}
                <Card className="overflow-hidden">
                    <CardHeader
                        className="cursor-pointer select-none border-b bg-muted/30 pb-4 hover:bg-muted/50 transition-colors"
                        onClick={() => setInjuryOpen(!injuryOpen)}
                    >
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">4</div>
                                <div>
                                    <CardTitle className="text-base">Injury details</CardTitle>
                                    <p className="text-xs text-muted-foreground mt-0.5">If someone was injured, record the details here</p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2 text-muted-foreground">
                                <span className="text-xs font-medium">{injuryOpen ? 'Collapse' : 'Expand'}</span>
                                {injuryOpen ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
                            </div>
                        </div>
                    </CardHeader>
                    {injuryOpen && (
                        <CardContent className="pt-5 space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Injured person name</Label>
                                    <Input value={form.data.injured_person_name} onChange={(e) => form.setData('injured_person_name', e.target.value)} />
                                </div>

                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Role</Label>
                                    <Select value={form.data.injured_person_role || '__none__'} onValueChange={(v) => form.setData('injured_person_role', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {INJURED_PERSON_ROLES.map((r) => (
                                                <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Age</Label>
                                    <Input type="number" value={form.data.injured_person_age} onChange={(e) => form.setData('injured_person_age', e.target.value)} />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Body part</Label>
                                    <Input value={form.data.injury_body_part} onChange={(e) => form.setData('injury_body_part', e.target.value)} placeholder="e.g. Left arm, Head, Lower back" />
                                </div>

                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Nature of injury</Label>
                                    <Select value={form.data.injury_nature || '__none__'} onValueChange={(v) => form.setData('injury_nature', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {INJURY_NATURES.map((n) => (
                                                <SelectItem key={n.value} value={n.value}>{n.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Injury classification</Label>
                                    <Select value={form.data.injury_classification || '__none__'} onValueChange={(v) => form.setData('injury_classification', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {INJURY_CLASSIFICATIONS.map((c) => (
                                                <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Medical treatment</Label>
                                    <Select value={form.data.medical_treatment_type || '__none__'} onValueChange={(v) => form.setData('medical_treatment_type', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {MEDICAL_TREATMENT_TYPES.map((m) => (
                                                <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </CardContent>
                    )}
                </Card>

                {/* Section 5: WorkSafe notifiable */}
                <Card className={`overflow-hidden transition-colors ${form.data.is_notifiable ? 'border-red-300 bg-red-50/30' : ''}`}>
                    <CardContent className="pt-6">
                        <div className="flex items-start gap-4">
                            <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${form.data.is_notifiable ? 'bg-red-100 text-red-600' : 'bg-muted text-muted-foreground'}`}>
                                <ShieldAlert className="h-5 w-5" />
                            </div>
                            <div className="flex-1">
                                <div className="flex items-center gap-3">
                                    <Checkbox
                                        id="is_notifiable"
                                        checked={!!form.data.is_notifiable}
                                        onCheckedChange={(v) => form.setData('is_notifiable', !!v)}
                                    />
                                    <div>
                                        <Label htmlFor="is_notifiable" className="text-sm font-semibold cursor-pointer">
                                            WorkSafe NZ notifiable event
                                        </Label>
                                        <p className="text-xs text-muted-foreground mt-0.5">
                                            Check this box if this incident must be reported to WorkSafe New Zealand under the Health and Safety at Work Act 2015.
                                        </p>
                                    </div>
                                </div>
                                {form.data.is_notifiable && (
                                    <div className="mt-3 rounded-md border border-red-200 bg-red-50 p-3">
                                        <p className="text-xs font-medium text-red-800">
                                            Important: You are required to notify WorkSafe NZ as soon as possible. The scene must be preserved unless doing so would cause further harm.
                                        </p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Errors */}
                {Object.keys(form.errors).length > 0 && (
                    <div className="rounded-lg border border-red-300 bg-red-50 p-4">
                        <div className="flex items-center gap-2">
                            <AlertTriangle className="h-4 w-4 text-red-600" />
                            <p className="text-sm font-semibold text-red-800">Please fix the following errors:</p>
                        </div>
                        <ul className="mt-2 list-disc pl-6 text-sm text-red-700 space-y-0.5">
                            {Object.entries(form.errors).map(([field, message]) => (
                                <li key={field}>{message}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Submit */}
                <div className="flex items-center justify-end gap-3 border-t pt-6">
                    <Link href="/incidents" className="rounded-md border px-4 py-2 text-sm font-medium hover:bg-muted transition-colors">
                        Cancel
                    </Link>
                    <Button
                        size="lg"
                        disabled={form.processing}
                        onClick={() => form.post('/incidents', { preserveScroll: true })}
                        className="min-w-[140px]"
                    >
                        {form.processing ? 'Saving...' : 'Create draft'}
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
