import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarClock,
    ClipboardList,
    DollarSign,
    FileText,
    Landmark,
    Mail,
    PenLine,
    Upload,
    UserCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';

/* ---------- SectionHeader ---------- */

function SectionHeader({ icon: Icon, iconBg, title, description }: { icon: LucideIcon; iconBg: string; title: string; description: string }) {
    return (
        <div className="mb-4 flex items-center gap-2.5">
            <div className={`flex h-8 w-8 items-center justify-center rounded-lg ${iconBg}`}>
                <Icon className="h-4 w-4" />
            </div>
            <div>
                <h3 className="text-sm font-semibold">{title}</h3>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
        </div>
    );
}

/* ---------- Types ---------- */

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

/* ---------- Agreement Types ---------- */

const AGREEMENT_TYPES: Record<string, string> = {
    ndis: 'NDIS',
    msd: 'MSD — Ministry of Social Development',
    dss: 'DSS — Disability Support Services',
    acc: 'ACC — Accident Compensation',
    dhb: 'Health NZ / Te Whatu Ora',
    oranga_tamariki: 'Oranga Tamariki',
    private: 'Private / Self-Funded',
    charitable: 'Charitable Trust / NGO',
    other: 'Other',
};

const FUNDING_TYPES: Record<string, string> = {
    if: 'Individualised Funding (IF)',
    eif: 'Enhanced IF (EIF)',
    flexible_disability: 'Flexible Disability Support',
    residential: 'Residential Support',
    community_participation: 'Community Participation',
    respite: 'Respite',
    day_services: 'Day Services',
    vocational: 'Vocational',
    other: 'Other',
};

const SERVICE_LEVELS: Record<string, string> = {
    level_1: 'Level 1',
    level_2: 'Level 2',
    level_3: 'Level 3',
    level_4: 'Level 4',
    community: 'Community',
    flexible: 'Flexible',
};

const SUPPORT_NEEDS_LEVELS: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    very_high: 'Very High',
    complex: 'Complex',
};

/* ---------- Component ---------- */

export default function ServiceAgreementCreate({ clients }: Props) {
    const { labels } = usePage().props as any;
    const clientLabel = labels?.['client.singular'] ?? 'Client';

    const urlParams = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : new URLSearchParams();
    const initialClientId = urlParams.get('client_id') ?? '';

    const { data, setData, post, processing, errors } = useForm({
        client_id: initialClientId,
        title: '',
        reference_number: '',
        agreement_type: 'msd',
        funding_body: '',
        funding_reference: '',
        status: 'draft',
        starts_at: '',
        ends_at: '',
        nasc_assessment_date: '',
        funding_approved_date: '',
        signed_date: '',
        first_service_date: '',
        review_due_date: '',
        renewal_date: '',
        total_budget: '',
        hourly_rate: '',
        daily_rate: '',
        terms: '',
        notes: '',
        // NZ Funding Details
        funding_type: '',
        service_level: '',
        allocated_hours_per_week: '',
        total_hours: '',
        gst_inclusive: true,
        whaikaha_reference: '',
        support_needs_level: '',
        // NASC Details
        nasc_assessor_name: '',
        nasc_support_package_ref: '',
        // Signatories & Contacts
        client_signatory: '',
        provider_signatory: '',
        funder_contact_name: '',
        funder_contact_email: '',
        funder_contact_phone: '',
    });

    const [docFiles, setDocFiles] = useState<File[]>([]);
    const [dragOver, setDragOver] = useState(false);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const payload: any = { ...data };
        if (docFiles.length > 0) payload.documents = docFiles;
        post('/operations/service-agreements', {
            forceFormData: docFiles.length > 0,
        });
    };

    const handleFileDrop = (e: React.DragEvent) => {
        e.preventDefault();
        setDragOver(false);
        const files = Array.from(e.dataTransfer.files);
        setDocFiles((prev) => [...prev, ...files]);
    };

    const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(e.target.files ?? []);
        setDocFiles((prev) => [...prev, ...files]);
    };

    const removeFile = (index: number) => {
        setDocFiles((prev) => prev.filter((_, i) => i !== index));
    };

    return (
        <AppLayout>
            <Head title="Create Service Agreement" />
            <PageHeader
                title="Create Service Agreement"
                description="Set up a new funding agreement for a client."
                backHref={initialClientId ? `/operations/clients/${initialClientId}` : '/operations/service-agreements'}
            />
            <PageShell>
                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Section 1: Agreement Details */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={ClipboardList}
                                iconBg="bg-violet-100 text-violet-600"
                                title="Agreement Details"
                                description="Basic information about this service agreement."
                            />
                            <div className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label>{clientLabel} *</Label>
                                        <Select value={data.client_id} onValueChange={(v) => setData('client_id', v)}>
                                            <SelectTrigger><SelectValue placeholder={`Select ${clientLabel.toLowerCase()}`} /></SelectTrigger>
                                            <SelectContent>
                                                {(clients ?? []).map((c) => (
                                                    <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.client_id && <p className="text-xs text-destructive">{errors.client_id}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Agreement Type *</Label>
                                        <Select value={data.agreement_type} onValueChange={(v) => setData('agreement_type', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(AGREEMENT_TYPES).map(([value, label]) => (
                                                    <SelectItem key={value} value={value}>{label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label>Title *</Label>
                                        <Input value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="e.g. DSS Residential Support 2026" />
                                        {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Reference Number</Label>
                                        <Input value={data.reference_number} onChange={(e) => setData('reference_number', e.target.value)} placeholder="e.g. SA-2026-001" />
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 2: Funding Source */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={Building2}
                                iconBg="bg-emerald-100 text-emerald-600"
                                title="Funding Source"
                                description="Who is funding this agreement and their reference details."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>Funding Body</Label>
                                    <Input value={data.funding_body} onChange={(e) => setData('funding_body', e.target.value)} placeholder="e.g. Whaikaha, ACC, Health NZ" />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Funding Reference / Contract ID</Label>
                                    <Input value={data.funding_reference} onChange={(e) => setData('funding_reference', e.target.value)} placeholder="e.g. NASCRef-2026-1234" />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 3: Dates & Milestones */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={CalendarClock}
                                iconBg="bg-indigo-100 text-indigo-600"
                                title="Dates & Milestones"
                                description="Key dates throughout the agreement lifecycle."
                            />
                            <div className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-1.5">
                                        <Label>Start Date</Label>
                                        <Input type="date" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>End Date</Label>
                                        <Input type="date" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Status</Label>
                                        <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="draft">Draft</SelectItem>
                                                <SelectItem value="active">Active</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-1.5">
                                        <Label>NASC Assessment Date</Label>
                                        <Input type="date" value={data.nasc_assessment_date} onChange={(e) => setData('nasc_assessment_date', e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Funding Approved Date</Label>
                                        <Input type="date" value={data.funding_approved_date} onChange={(e) => setData('funding_approved_date', e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Signed Date</Label>
                                        <Input type="date" value={data.signed_date} onChange={(e) => setData('signed_date', e.target.value)} />
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-1.5">
                                        <Label>First Service Date</Label>
                                        <Input type="date" value={data.first_service_date} onChange={(e) => setData('first_service_date', e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Review Due Date</Label>
                                        <Input type="date" value={data.review_due_date} onChange={(e) => setData('review_due_date', e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Renewal Date</Label>
                                        <Input type="date" value={data.renewal_date} onChange={(e) => setData('renewal_date', e.target.value)} />
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 4: Budget & Rates */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={DollarSign}
                                iconBg="bg-amber-100 text-amber-600"
                                title="Budget & Rates"
                                description="Allocated funding and service rates in NZD."
                            />
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label>Total Budget (NZD)</Label>
                                    <div className="relative">
                                        <span className="absolute left-3 top-2.5 text-sm text-muted-foreground">$</span>
                                        <Input type="number" step="0.01" className="pl-7" value={data.total_budget} onChange={(e) => setData('total_budget', e.target.value)} placeholder="0.00" />
                                    </div>
                                    {errors.total_budget && <p className="text-xs text-destructive">{errors.total_budget}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Hourly Rate (NZD)</Label>
                                    <div className="relative">
                                        <span className="absolute left-3 top-2.5 text-sm text-muted-foreground">$</span>
                                        <Input type="number" step="0.01" className="pl-7" value={data.hourly_rate} onChange={(e) => setData('hourly_rate', e.target.value)} placeholder="0.00" />
                                    </div>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Daily Rate (NZD)</Label>
                                    <div className="relative">
                                        <span className="absolute left-3 top-2.5 text-sm text-muted-foreground">$</span>
                                        <Input type="number" step="0.01" className="pl-7" value={data.daily_rate} onChange={(e) => setData('daily_rate', e.target.value)} placeholder="0.00" />
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 5: NZ Funding Details */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={Landmark}
                                iconBg="bg-indigo-100 text-indigo-600"
                                title="NZ Funding Details"
                                description="Whaikaha / DSS funding type and service level details."
                            />
                            <div className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label>Funding Type</Label>
                                        <Select value={data.funding_type} onValueChange={(v) => setData('funding_type', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select funding type" /></SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(FUNDING_TYPES).map(([value, label]) => (
                                                    <SelectItem key={value} value={value}>{label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Service Level</Label>
                                        <Select value={data.service_level} onValueChange={(v) => setData('service_level', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select service level" /></SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(SERVICE_LEVELS).map(([value, label]) => (
                                                    <SelectItem key={value} value={value}>{label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-1.5">
                                        <Label>Allocated Hours / Week</Label>
                                        <Input type="number" step="0.5" min="0" value={data.allocated_hours_per_week} onChange={(e) => setData('allocated_hours_per_week', e.target.value)} placeholder="0" />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Total Hours (Agreement Period)</Label>
                                        <Input type="number" step="0.5" min="0" value={data.total_hours} onChange={(e) => setData('total_hours', e.target.value)} placeholder="0" />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Support Needs Level</Label>
                                        <Select value={data.support_needs_level} onValueChange={(v) => setData('support_needs_level', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select level" /></SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(SUPPORT_NEEDS_LEVELS).map(([value, label]) => (
                                                    <SelectItem key={value} value={value}>{label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label>Whaikaha Reference</Label>
                                        <Input value={data.whaikaha_reference} onChange={(e) => setData('whaikaha_reference', e.target.value)} placeholder="e.g. WHK-2026-0001" />
                                    </div>
                                    <div className="flex items-center gap-3 pt-6">
                                        <Checkbox
                                            id="gst_inclusive"
                                            checked={data.gst_inclusive}
                                            onCheckedChange={(v) => setData('gst_inclusive', v === true)}
                                        />
                                        <Label htmlFor="gst_inclusive" className="cursor-pointer">GST Inclusive (15%)</Label>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 6: NASC Details */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={UserCheck}
                                iconBg="bg-teal-100 text-teal-600"
                                title="NASC Details"
                                description="Needs Assessment and Service Coordination information."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>NASC Assessor Name</Label>
                                    <Input value={data.nasc_assessor_name} onChange={(e) => setData('nasc_assessor_name', e.target.value)} placeholder="e.g. Jane Smith" />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>NASC Support Package Ref</Label>
                                    <Input value={data.nasc_support_package_ref} onChange={(e) => setData('nasc_support_package_ref', e.target.value)} placeholder="e.g. SP-2026-0001" />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 7: Signatories & Contacts */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={PenLine}
                                iconBg="bg-violet-100 text-violet-600"
                                title="Signatories & Contacts"
                                description="Agreement signatories and funder contact details."
                            />
                            <div className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label>Client Signatory</Label>
                                        <Input value={data.client_signatory} onChange={(e) => setData('client_signatory', e.target.value)} placeholder="Person signing for the client (e.g. welfare guardian)" />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Provider Signatory</Label>
                                        <Input value={data.provider_signatory} onChange={(e) => setData('provider_signatory', e.target.value)} placeholder="Person signing for your organisation" />
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-1.5">
                                        <Label>Funder Contact Name</Label>
                                        <Input value={data.funder_contact_name} onChange={(e) => setData('funder_contact_name', e.target.value)} placeholder="e.g. John Doe" />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label className="flex items-center gap-1.5"><Mail className="h-3.5 w-3.5 text-muted-foreground" />Funder Contact Email</Label>
                                        <Input type="email" value={data.funder_contact_email} onChange={(e) => setData('funder_contact_email', e.target.value)} placeholder="funder@example.co.nz" />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Funder Contact Phone</Label>
                                        <Input type="tel" value={data.funder_contact_phone} onChange={(e) => setData('funder_contact_phone', e.target.value)} placeholder="e.g. 04 123 4567" />
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 8: Terms & Notes */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={FileText}
                                iconBg="bg-blue-100 text-blue-600"
                                title="Terms & Notes"
                                description="Agreement terms, conditions, and any additional notes."
                            />
                            <div className="space-y-4">
                                <div className="space-y-1.5">
                                    <Label>Terms & Conditions</Label>
                                    <Textarea className="min-h-[100px] bg-slate-50/50" value={data.terms} onChange={(e) => setData('terms', e.target.value)} placeholder="Enter the terms and conditions of this agreement..." />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Notes</Label>
                                    <Textarea className="min-h-[80px] bg-slate-50/50" value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Any additional notes or context..." />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 9: Document Upload */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={Upload}
                                iconBg="bg-cyan-100 text-cyan-600"
                                title="Documents"
                                description="Upload signed agreement, addendums, or supporting documents."
                            />
                            <div
                                className={`flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-8 transition-colors ${
                                    dragOver
                                        ? 'border-violet-500 bg-violet-100/50'
                                        : 'border-violet-300 bg-violet-50/50 hover:bg-violet-50'
                                }`}
                                onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                                onDragLeave={() => setDragOver(false)}
                                onDrop={handleFileDrop}
                                onClick={() => document.getElementById('doc-upload-create')?.click()}
                            >
                                <Upload className="mb-2 h-8 w-8 text-violet-400" />
                                <p className="text-sm font-medium text-violet-700">
                                    {dragOver ? 'Drop files here' : 'Click or drag files to upload'}
                                </p>
                                <p className="mt-1 text-xs text-violet-500">PDF, Word document, or scanned image</p>
                                <input
                                    id="doc-upload-create"
                                    type="file"
                                    className="hidden"
                                    multiple
                                    accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                    onChange={handleFileSelect}
                                />
                            </div>
                            {docFiles.length > 0 && (
                                <div className="mt-3 space-y-2">
                                    {docFiles.map((file, index) => (
                                        <div key={index} className="flex items-center justify-between rounded-lg border bg-muted/30 px-3 py-2">
                                            <div className="flex items-center gap-2">
                                                <Upload className="h-3.5 w-3.5 text-muted-foreground" />
                                                <span className="text-sm">{file.name}</span>
                                                <span className="text-xs text-muted-foreground">
                                                    ({(file.size / 1024).toFixed(1)} KB)
                                                </span>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-6 px-2 text-xs text-red-500 hover:text-red-700"
                                                onClick={(e) => { e.stopPropagation(); removeFile(index); }}
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex items-center justify-between rounded-xl border bg-slate-50 p-4">
                        <p className="text-sm text-muted-foreground">
                            {data.status === 'draft' ? 'This agreement will be saved as a draft.' : 'This agreement will be created as active.'}
                        </p>
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={() => router.get(initialClientId ? `/operations/clients/${initialClientId}` : '/operations/service-agreements')}>Cancel</Button>
                            <Button type="submit" disabled={processing} className="bg-violet-600 hover:bg-violet-700">
                                {processing ? 'Creating...' : 'Create Agreement'}
                            </Button>
                        </div>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
