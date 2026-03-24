import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
    Upload,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';

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

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

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
        total_budget: '',
        hourly_rate: '',
        daily_rate: '',
        terms: '',
        notes: '',
    });

    const [docFile, setDocFile] = useState<File | null>(null);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const payload: any = { ...data };
        if (docFile) payload.document = docFile;
        post('/operations/service-agreements', {
            forceFormData: !!docFile,
        });
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
                                                <SelectItem value="msd">MSD — Ministry of Social Development</SelectItem>
                                                <SelectItem value="dss">DSS — Disability Support Services</SelectItem>
                                                <SelectItem value="acc">ACC — Accident Compensation</SelectItem>
                                                <SelectItem value="dhb">Health NZ / Te Whatu Ora</SelectItem>
                                                <SelectItem value="oranga_tamariki">Oranga Tamariki</SelectItem>
                                                <SelectItem value="private">Private / Self-Funded</SelectItem>
                                                <SelectItem value="charitable">Charitable Trust / NGO</SelectItem>
                                                <SelectItem value="other">Other</SelectItem>
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

                    {/* Section 3: Period & Budget */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={DollarSign}
                                iconBg="bg-amber-100 text-amber-600"
                                title="Period & Budget"
                                description="Agreement dates and allocated funding in NZD."
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
                                                <SelectItem value="expired">Expired</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
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
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 4: Terms & Notes */}
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

                    {/* Section 5: Document Upload */}
                    <Card>
                        <CardContent className="p-5">
                            <SectionHeader
                                icon={Upload}
                                iconBg="bg-cyan-100 text-cyan-600"
                                title="Agreement Document"
                                description="Upload the signed agreement or contract document."
                            />
                            <label className="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-violet-300 bg-violet-50/50 p-8 transition-colors hover:bg-violet-50">
                                <Upload className="mb-2 h-8 w-8 text-violet-400" />
                                <p className="text-sm font-medium text-violet-700">{docFile ? docFile.name : 'Click to upload agreement document'}</p>
                                <p className="mt-1 text-xs text-violet-500">PDF, Word document, or scanned image</p>
                                <input type="file" className="hidden" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onChange={(e) => setDocFile(e.target.files?.[0] ?? null)} />
                            </label>
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex items-center justify-between rounded-xl border bg-slate-50 p-4">
                        <p className="text-sm text-muted-foreground">
                            {data.status === 'draft' ? 'This agreement will be saved as a draft.' : 'This agreement will be created as active.'}
                        </p>
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={() => router.get(initialClientId ? `/operations/clients/${initialClientId}` : '/operations/service-agreements')}>Cancel</Button>
                            <Button type="submit" disabled={processing} className="bg-violet-600 hover:bg-violet-700">Create Agreement</Button>
                        </div>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
