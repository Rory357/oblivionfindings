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
import { Head, router, useForm } from '@inertiajs/react';
import {
    Building2,
    CalendarClock,
    ClipboardList,
    DollarSign,
    FileText,
    Info,
    Landmark,
    Mail,
    Paperclip,
    PenLine,
    Send,
    Upload,
    User,
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

/* ---------- Status helpers ---------- */

const STATUS_COLORS: Record<string, string> = {
    draft: 'bg-muted text-foreground border-border',
    pending_approval: 'bg-amber-50 text-amber-700 border-amber-200',
    active: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    under_review: 'bg-primary/10 text-primary border-primary',
    renewed: 'bg-blue-50 text-blue-700 border-blue-200',
    expired: 'bg-muted text-muted-foreground border-border',
    terminated: 'bg-red-50 text-red-700 border-red-200',
    suspended: 'bg-amber-50 text-amber-700 border-amber-200',
};

function statusBadge(status: string) {
    const cls = STATUS_COLORS[status] ?? 'bg-muted text-muted-foreground border-border';
    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize ${cls}`}>
            {status.replace(/_/g, ' ')}
        </span>
    );
}

/* ---------- Types ---------- */

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

type Props = {
    agreement: {
        id: number;
        client_id: number;
        title: string;
        reference_number: string | null;
        agreement_type: string;
        funding_body: string | null;
        funding_reference: string | null;
        status: string;
        starts_at: string | null;
        ends_at: string | null;
        nasc_assessment_date: string | null;
        funding_approved_date: string | null;
        signed_date: string | null;
        first_service_date: string | null;
        review_due_date: string | null;
        renewal_date: string | null;
        total_budget: number | null;
        hourly_rate: number | null;
        daily_rate: number | null;
        terms: string | null;
        notes: string | null;
        submitted_for_approval_at: string | null;
        submitted_for_approval_by: { id: number; name: string } | null;
        client: { id: number; first_name: string; last_name: string } | null;
        // NZ fields
        funding_type: string | null;
        service_level: string | null;
        allocated_hours_per_week: number | null;
        total_hours: number | null;
        gst_inclusive: boolean;
        whaikaha_reference: string | null;
        support_needs_level: string | null;
        nasc_assessor_name: string | null;
        nasc_support_package_ref: string | null;
        client_signatory: string | null;
        provider_signatory: string | null;
        funder_contact_name: string | null;
        funder_contact_email: string | null;
        funder_contact_phone: string | null;
    };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

/* ---------- Component ---------- */

export default function ServiceAgreementEdit({ agreement, clients }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        title: agreement.title,
        reference_number: agreement.reference_number ?? '',
        agreement_type: agreement.agreement_type,
        funding_body: agreement.funding_body ?? '',
        funding_reference: agreement.funding_reference ?? '',
        starts_at: agreement.starts_at ?? '',
        ends_at: agreement.ends_at ?? '',
        nasc_assessment_date: agreement.nasc_assessment_date ?? '',
        funding_approved_date: agreement.funding_approved_date ?? '',
        signed_date: agreement.signed_date ?? '',
        first_service_date: agreement.first_service_date ?? '',
        review_due_date: agreement.review_due_date ?? '',
        renewal_date: agreement.renewal_date ?? '',
        total_budget: agreement.total_budget != null ? String(agreement.total_budget) : '',
        hourly_rate: agreement.hourly_rate != null ? String(agreement.hourly_rate) : '',
        daily_rate: agreement.daily_rate != null ? String(agreement.daily_rate) : '',
        terms: agreement.terms ?? '',
        notes: agreement.notes ?? '',
        // NZ Funding Details
        funding_type: agreement.funding_type ?? '',
        service_level: agreement.service_level ?? '',
        allocated_hours_per_week: agreement.allocated_hours_per_week != null ? String(agreement.allocated_hours_per_week) : '',
        total_hours: agreement.total_hours != null ? String(agreement.total_hours) : '',
        gst_inclusive: agreement.gst_inclusive ?? true,
        whaikaha_reference: agreement.whaikaha_reference ?? '',
        support_needs_level: agreement.support_needs_level ?? '',
        // NASC Details
        nasc_assessor_name: agreement.nasc_assessor_name ?? '',
        nasc_support_package_ref: agreement.nasc_support_package_ref ?? '',
        // Signatories & Contacts
        client_signatory: agreement.client_signatory ?? '',
        provider_signatory: agreement.provider_signatory ?? '',
        funder_contact_name: agreement.funder_contact_name ?? '',
        funder_contact_email: agreement.funder_contact_email ?? '',
        funder_contact_phone: agreement.funder_contact_phone ?? '',
    });

    const [docFiles, setDocFiles] = useState<File[]>([]);
    const [dragOver, setDragOver] = useState(false);

    const clientName = agreement.client
        ? `${agreement.client.first_name} ${agreement.client.last_name}`
        : clients.find((c) => c.id === agreement.client_id)
            ? `${clients.find((c) => c.id === agreement.client_id)!.first_name} ${clients.find((c) => c.id === agreement.client_id)!.last_name}`
            : 'Unknown Client';

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/operations/service-agreements/${agreement.id}`);
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

    const handleSubmitForApproval = () => {
        router.post(`/operations/service-agreements/${agreement.id}/submit-for-approval`);
    };

    const showApprovalSection = agreement.status === 'draft' || agreement.status === 'pending_approval';

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

    return (
        <AppLayout>
            <Head title={`Edit: ${agreement.title}`} />
            <PageHeader
                title="Edit Agreement"
                description={`${clientName} — ${agreement.title}`}
                backHref={`/operations/service-agreements/${agreement.id}`}
            />
            <PageShell>
                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Section 1: Agreement Details */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={ClipboardList}
                                iconBg="bg-primary/10 text-primary"
                                title="Agreement Details"
                                description="Core information about this service agreement."
                            />
                            <div className="space-y-4">
                                {/* Client (read-only) */}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label className="flex items-center gap-1.5">
                                            <User className="h-3.5 w-3.5 text-muted-foreground" />
                                            Client
                                        </Label>
                                        <div className="flex h-9 items-center rounded-md border bg-muted/50 px-3 text-sm">
                                            {clientName}
                                        </div>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Status</Label>
                                        <div className="flex h-9 items-center gap-2 rounded-md border bg-muted/50 px-3">
                                            {statusBadge(agreement.status)}
                                        </div>
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label>Title *</Label>
                                        <Input
                                            value={data.title}
                                            onChange={(e) => setData('title', e.target.value)}
                                            placeholder="e.g. DSS Residential Support 2026"
                                        />
                                        {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Reference Number</Label>
                                        <Input
                                            value={data.reference_number}
                                            onChange={(e) => setData('reference_number', e.target.value)}
                                            placeholder="e.g. SA-2026-001"
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
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
                                    <Input
                                        value={data.funding_body}
                                        onChange={(e) => setData('funding_body', e.target.value)}
                                        placeholder="e.g. Whaikaha, ACC, Health NZ"
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Funding Reference / Contract ID</Label>
                                    <Input
                                        value={data.funding_reference}
                                        onChange={(e) => setData('funding_reference', e.target.value)}
                                        placeholder="e.g. NASCRef-2026-1234"
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 3: Dates & Milestones */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={CalendarClock}
                                iconBg="bg-primary/10 text-primary"
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
                                        <Label>NASC Assessment Date</Label>
                                        <Input type="date" value={data.nasc_assessment_date} onChange={(e) => setData('nasc_assessment_date', e.target.value)} />
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-1.5">
                                        <Label>Funding Approved Date</Label>
                                        <Input type="date" value={data.funding_approved_date} onChange={(e) => setData('funding_approved_date', e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Signed Date</Label>
                                        <Input type="date" value={data.signed_date} onChange={(e) => setData('signed_date', e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>First Service Date</Label>
                                        <Input type="date" value={data.first_service_date} onChange={(e) => setData('first_service_date', e.target.value)} />
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
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
                                        <Input
                                            type="number"
                                            step="0.01"
                                            className="pl-7"
                                            value={data.total_budget}
                                            onChange={(e) => setData('total_budget', e.target.value)}
                                            placeholder="0.00"
                                        />
                                    </div>
                                    {errors.total_budget && <p className="text-xs text-destructive">{errors.total_budget}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Hourly Rate (NZD)</Label>
                                    <div className="relative">
                                        <span className="absolute left-3 top-2.5 text-sm text-muted-foreground">$</span>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            className="pl-7"
                                            value={data.hourly_rate}
                                            onChange={(e) => setData('hourly_rate', e.target.value)}
                                            placeholder="0.00"
                                        />
                                    </div>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Daily Rate (NZD)</Label>
                                    <div className="relative">
                                        <span className="absolute left-3 top-2.5 text-sm text-muted-foreground">$</span>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            className="pl-7"
                                            value={data.daily_rate}
                                            onChange={(e) => setData('daily_rate', e.target.value)}
                                            placeholder="0.00"
                                        />
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
                                iconBg="bg-primary/10 text-primary"
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
                                            id="gst_inclusive_edit"
                                            checked={data.gst_inclusive}
                                            onCheckedChange={(v) => setData('gst_inclusive', v === true)}
                                        />
                                        <Label htmlFor="gst_inclusive_edit" className="cursor-pointer">GST Inclusive (15%)</Label>
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
                                iconBg="bg-primary/10 text-primary"
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
                                    <Textarea
                                        className="min-h-[100px] bg-muted/50"
                                        value={data.terms}
                                        onChange={(e) => setData('terms', e.target.value)}
                                        placeholder="Enter the terms and conditions of this agreement..."
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Notes</Label>
                                    <Textarea
                                        className="min-h-[80px] bg-muted/50"
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        placeholder="Any additional notes or context..."
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 6: Documents */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={Paperclip}
                                iconBg="bg-cyan-100 text-cyan-600"
                                title="Documents"
                                description="Upload signed agreement, addendums, or supporting documents."
                            />
                            <div
                                className={`flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-8 transition-colors ${
                                    dragOver
                                        ? 'border-primary bg-primary/10/50'
                                        : 'border-primary bg-primary/10/50 hover:bg-primary/10'
                                }`}
                                onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                                onDragLeave={() => setDragOver(false)}
                                onDrop={handleFileDrop}
                                onClick={() => document.getElementById('doc-upload')?.click()}
                            >
                                <Upload className="mb-2 h-8 w-8 text-primary" />
                                <p className="text-sm font-medium text-primary">
                                    {dragOver ? 'Drop files here' : 'Click or drag files to upload'}
                                </p>
                                <p className="mt-1 text-xs text-primary">PDF, Word document, or scanned image</p>
                                <input
                                    id="doc-upload"
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
                                                <Paperclip className="h-3.5 w-3.5 text-muted-foreground" />
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

                    {/* Section 7: Approval Workflow (conditional) */}
                    {showApprovalSection && (
                        <Card className="border-amber-200 bg-amber-50/30">
                            <CardContent className="p-5">
                                <SectionHeader
                                    icon={Send}
                                    iconBg="bg-amber-100 text-amber-600"
                                    title="Approval Workflow"
                                    description="Submit this agreement for manager review and approval."
                                />
                                {agreement.status === 'draft' && (
                                    <div className="space-y-4">
                                        <div className="flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-3">
                                            <Info className="mt-0.5 h-4 w-4 shrink-0 text-blue-500" />
                                            <div className="text-xs text-blue-700">
                                                <p className="font-medium">How the approval workflow works:</p>
                                                <ol className="mt-1.5 list-inside list-decimal space-y-0.5">
                                                    <li>Save your changes first using the button below</li>
                                                    <li>Submit the agreement for approval</li>
                                                    <li>A manager will review and approve or return it</li>
                                                    <li>Once approved, the agreement becomes active</li>
                                                </ol>
                                            </div>
                                        </div>
                                        <Button
                                            type="button"
                                            onClick={handleSubmitForApproval}
                                            className="bg-amber-600 hover:bg-amber-700"
                                        >
                                            <Send className="mr-1.5 h-3.5 w-3.5" />
                                            Submit for Approval
                                        </Button>
                                    </div>
                                )}
                                {agreement.status === 'pending_approval' && (
                                    <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
                                        <Info className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                                        <div className="text-xs text-amber-700">
                                            <p className="font-medium">Pending Approval</p>
                                            {agreement.submitted_for_approval_by && (
                                                <p className="mt-1">
                                                    Submitted by <strong>{agreement.submitted_for_approval_by.name}</strong>
                                                    {agreement.submitted_for_approval_at && (
                                                        <> on {new Date(agreement.submitted_for_approval_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</>
                                                    )}
                                                </p>
                                            )}
                                            <p className="mt-1">Waiting for a manager to review and approve this agreement.</p>
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* Submit Row */}
                    <div className="flex items-center justify-between rounded-xl border bg-muted p-4">
                        <p className="text-sm text-muted-foreground">
                            Changes will be saved to this agreement.
                        </p>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.get(`/operations/service-agreements/${agreement.id}`)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing} className="bg-primary hover:bg-primary">
                                {processing ? 'Saving...' : 'Save Changes'}
                            </Button>
                        </div>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
