import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    templates: Array<any>;
};

const INCIDENT_TYPES = [
    { value: 'injury', label: 'Injury' },
    { value: 'behaviour', label: 'Behaviour' },
    { value: 'medication', label: 'Medication' },
    { value: 'safeguarding', label: 'Safeguarding' },
    { value: 'near_miss', label: 'Near miss' },
    { value: 'other', label: 'Other' },
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

    return (
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }, { title: 'New', href: '/incidents/create' }]}>
            <Head title="New incident" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">New incident</h1>
                        <div className="mt-1 text-sm text-slate-500">Standalone incident (draft)</div>
                    </div>
                    <div className="flex items-center gap-2">
                        {can.templatesManage && (
                            <Link href="/incidents/templates" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                Templates
                            </Link>
                        )}
                        <Link href="/incidents" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back
                        </Link>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label>{clientSingular}</Label>
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

                            <div className="space-y-1">
                                <Label>Template (optional)</Label>
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

                            <div className="space-y-1">
                                <Label>Occurred at</Label>
                                <Input type="datetime-local" value={form.data.occurred_at} onChange={(e) => form.setData('occurred_at', e.target.value)} />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Type</Label>
                                <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {INCIDENT_TYPES.map((t) => (
                                            <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1">
                                <Label>Severity</Label>
                                <Select value={form.data.severity} onValueChange={(v) => form.setData('severity', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {['low','medium','high'].map((s) => (
                                            <SelectItem key={s} value={s}>{s}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex items-center gap-2 pt-6">
                                <Checkbox checked={!!form.data.requires_followup} onCheckedChange={(v) => form.setData('requires_followup', !!v)} />
                                <Label>Requires follow-up</Label>
                            </div>
                        </div>

                        {isNearMiss && (
                            <div className="rounded-md border border-amber-200 bg-amber-50 p-3 space-y-3">
                                <div className="text-sm font-medium text-amber-800">Near-miss details</div>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className="space-y-1">
                                        <Label>Potential severity</Label>
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
                                <div className="space-y-1">
                                    <Label>Potential consequence</Label>
                                    <Textarea
                                        value={form.data.potential_consequence}
                                        onChange={(e) => form.setData('potential_consequence', e.target.value)}
                                        placeholder="Describe what could have happened..."
                                    />
                                </div>
                            </div>
                        )}

                        <div className="space-y-1">
                            <Label>Description</Label>
                            <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </div>
                        <div className="space-y-1">
                            <Label>Immediate action taken</Label>
                            <Textarea value={form.data.immediate_action_taken} onChange={(e) => form.setData('immediate_action_taken', e.target.value)} />
                        </div>
                        <div className="space-y-1">
                            <Label>Witnesses</Label>
                            <Textarea value={form.data.witnesses} onChange={(e) => form.setData('witnesses', e.target.value)} />
                        </div>
                    </CardContent>
                </Card>

                <Collapsible open={injuryOpen} onOpenChange={setInjuryOpen}>
                    <Card>
                        <CardHeader>
                            <CollapsibleTrigger className="flex w-full items-center justify-between">
                                <CardTitle className="text-base">Injury details</CardTitle>
                                <span className="text-xs text-slate-500">{injuryOpen ? 'Collapse' : 'Expand'}</span>
                            </CollapsibleTrigger>
                        </CardHeader>
                        <CollapsibleContent>
                            <CardContent className="space-y-3">
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <div className="space-y-1">
                                        <Label>Injured person name</Label>
                                        <Input value={form.data.injured_person_name} onChange={(e) => form.setData('injured_person_name', e.target.value)} />
                                    </div>

                                    <div className="space-y-1">
                                        <Label>Role</Label>
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

                                    <div className="space-y-1">
                                        <Label>Age</Label>
                                        <Input type="number" value={form.data.injured_person_age} onChange={(e) => form.setData('injured_person_age', e.target.value)} />
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className="space-y-1">
                                        <Label>Body part</Label>
                                        <Input value={form.data.injury_body_part} onChange={(e) => form.setData('injury_body_part', e.target.value)} placeholder="e.g. Left arm, Head, Lower back" />
                                    </div>

                                    <div className="space-y-1">
                                        <Label>Nature of injury</Label>
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

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className="space-y-1">
                                        <Label>Injury classification</Label>
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

                                    <div className="space-y-1">
                                        <Label>Medical treatment</Label>
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
                        </CollapsibleContent>
                    </Card>
                </Collapsible>

                <Card>
                    <CardContent className="pt-6">
                        <div className="flex items-center gap-2">
                            <Checkbox checked={!!form.data.is_notifiable} onCheckedChange={(v) => form.setData('is_notifiable', !!v)} />
                            <div>
                                <Label>Notifiable event</Label>
                                <div className="text-xs text-slate-500">This incident must be reported to WorkSafe NZ</div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {Object.keys(form.errors).length > 0 && (
                    <div className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                        <p className="font-medium">Please fix the following errors:</p>
                        <ul className="mt-1 list-disc pl-5">
                            {Object.entries(form.errors).map(([field, message]) => (
                                <li key={field}>{message}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="flex items-center justify-end">
                    <Button
                        disabled={form.processing}
                        onClick={() => form.post('/incidents', { preserveScroll: true })}
                    >
                        Create draft
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
