import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FileText, MapPin, Activity } from 'lucide-react';

type SdsRecord = {
    id: number;
    version: string;
    issue_date: string | null;
    supplier: string | null;
    status: string;
    file_name: string | null;
};

type StorageLocation = {
    id: number;
    site: { id: number; name: string } | null;
    location_description: string;
    current_quantity: number | null;
    max_quantity: number | null;
    is_labelled: boolean;
    segregation_compliant: boolean;
};

type ExposureRecord = {
    id: number;
    user: { id: number; name: string } | null;
    exposure_date: string;
    exposure_type: string;
    symptoms: string | null;
    medical_attention: boolean;
};

type Substance = {
    id: number;
    name: string;
    common_name: string | null;
    un_number: string | null;
    hsno_approval: string | null;
    hsno_classification: string | null;
    signal_word: string | null;
    hazard_statements: string | null;
    precautionary_statements: string | null;
    physical_form: string | null;
    first_aid_measures: string | null;
    firefighting_measures: string | null;
    spill_procedures: string | null;
    handling_precautions: string | null;
    storage_requirements: string | null;
    ppe_required: string | null;
    exposure_limit_type: string | null;
    exposure_limit_value: string | null;
    requires_tracking: boolean;
    is_controlled_substance: boolean;
    status: string;
    can_manage_entries: boolean;
    sds_records: SdsRecord[];
    storage_locations: StorageLocation[];
    exposure_records: ExposureRecord[];
};

type Props = {
    substance: Substance;
    sites: Array<{ id: number; name: string }>;
    staff: Array<{ id: number; name: string }>;
};

const statusColor = (status: string) => {
    switch (status) {
        case 'active':
        case 'current':
            return 'bg-green-100 text-green-800';
        case 'inactive':
        case 'superseded':
            return 'bg-slate-100 text-slate-800';
        case 'pending_review':
            return 'bg-amber-100 text-amber-800';
        case 'expired':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-slate-100 text-slate-800';
    }
};

export default function SubstanceShow({ substance, sites, staff }: Props) {
    const [sdsOpen, setSdsOpen] = useState(false);
    const [storageOpen, setStorageOpen] = useState(false);
    const [exposureOpen, setExposureOpen] = useState(false);

    const sdsForm = useForm<{
        version: string;
        issue_date: string;
        supplier: string;
        file: File | null;
    }>({
        version: '',
        issue_date: '',
        supplier: '',
        file: null,
    });

    const storageForm = useForm({
        site_id: '',
        location_description: '',
        current_quantity: '',
        max_quantity: '',
        is_labelled: true,
        segregation_compliant: true,
    });

    const exposureForm = useForm({
        user_id: '',
        exposure_date: '',
        exposure_type: 'inhalation',
        symptoms: '',
        medical_attention: false,
    });

    const infoRow = (label: string, value: string | null | undefined) =>
        value ? (
            <div>
                <div className="text-xs text-slate-500">{label}</div>
                <div className="mt-0.5 text-sm whitespace-pre-wrap">{value}</div>
            </div>
        ) : null;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Chemical Register', href: '/health-safety/substances' },
                { title: substance.name, href: `/health-safety/substances/${substance.id}` },
            ]}
        >
            <Head title={substance.name} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{substance.name}</h1>
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                            {substance.common_name && <span>{substance.common_name}</span>}
                            <Badge className={statusColor(substance.status)}>{substance.status}</Badge>
                            {substance.is_controlled_substance && (
                                <Badge variant="destructive">Controlled</Badge>
                            )}
                        </div>
                    </div>
                    <Link href="/health-safety/substances" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back
                    </Link>
                </div>

                {/* Basic Info */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Basic Information</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            {infoRow('UN Number', substance.un_number)}
                            {infoRow('HSNO Approval', substance.hsno_approval)}
                            {infoRow('HSNO Classification', substance.hsno_classification)}
                            {infoRow('Physical Form', substance.physical_form)}
                            {infoRow('Signal Word', substance.signal_word)}
                        </div>
                    </CardContent>
                </Card>

                {/* Hazard Info */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Hazard Information</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {infoRow('Hazard Statements', substance.hazard_statements)}
                        {infoRow('Precautionary Statements', substance.precautionary_statements)}
                    </CardContent>
                </Card>

                {/* Safety Info */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Safety Information</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {infoRow('First Aid Measures', substance.first_aid_measures)}
                        {infoRow('Firefighting Measures', substance.firefighting_measures)}
                        {infoRow('Spill Procedures', substance.spill_procedures)}
                        {infoRow('Handling Precautions', substance.handling_precautions)}
                        {infoRow('Storage Requirements', substance.storage_requirements)}
                    </CardContent>
                </Card>

                {/* PPE & Exposure */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">PPE & Exposure Limits</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {infoRow('PPE Required', substance.ppe_required)}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {infoRow('Exposure Limit Type', substance.exposure_limit_type)}
                            {infoRow('Exposure Limit Value', substance.exposure_limit_value)}
                        </div>
                    </CardContent>
                </Card>

                {/* SDS Records */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="h-4 w-4" />
                                Safety Data Sheets
                            </CardTitle>
                            {substance.can_manage_entries && (
                                <Button size="sm" onClick={() => setSdsOpen(true)}>
                                    Upload SDS
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-slate-500">
                                        <th className="pb-2 pr-4 font-medium">Version</th>
                                        <th className="pb-2 pr-4 font-medium">Issue Date</th>
                                        <th className="pb-2 pr-4 font-medium">Supplier</th>
                                        <th className="pb-2 pr-4 font-medium">Status</th>
                                        <th className="pb-2 font-medium">File</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {substance.sds_records.map((sds) => (
                                        <tr key={sds.id} className="border-b last:border-0">
                                            <td className="py-2 pr-4 font-medium">{sds.version}</td>
                                            <td className="py-2 pr-4">
                                                {sds.issue_date
                                                    ? new Date(sds.issue_date).toLocaleDateString('en-GB')
                                                    : '-'}
                                            </td>
                                            <td className="py-2 pr-4">{sds.supplier ?? '-'}</td>
                                            <td className="py-2 pr-4">
                                                <Badge className={statusColor(sds.status)}>{sds.status}</Badge>
                                            </td>
                                            <td className="py-2">
                                                {sds.file_name ? (
                                                    <Link
                                                        href={`/health-safety/substances/${substance.id}/sds/${sds.id}/download`}
                                                        className="text-xs text-blue-600 underline"
                                                    >
                                                        {sds.file_name}
                                                    </Link>
                                                ) : (
                                                    '-'
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!substance.sds_records.length && (
                                <div className="py-4 text-center text-sm text-slate-500">
                                    No SDS records found.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Storage Locations */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <MapPin className="h-4 w-4" />
                                Storage Locations
                            </CardTitle>
                            {substance.can_manage_entries && (
                                <Button size="sm" onClick={() => setStorageOpen(true)}>
                                    Add Location
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-slate-500">
                                        <th className="pb-2 pr-4 font-medium">Site</th>
                                        <th className="pb-2 pr-4 font-medium">Location</th>
                                        <th className="pb-2 pr-4 font-medium">Quantity</th>
                                        <th className="pb-2 pr-4 font-medium">Max Quantity</th>
                                        <th className="pb-2 pr-4 font-medium">Labelled</th>
                                        <th className="pb-2 font-medium">Segregation Compliant</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {substance.storage_locations.map((loc) => (
                                        <tr key={loc.id} className="border-b last:border-0">
                                            <td className="py-2 pr-4">{loc.site?.name ?? '-'}</td>
                                            <td className="py-2 pr-4">{loc.location_description}</td>
                                            <td className="py-2 pr-4">{loc.current_quantity ?? '-'}</td>
                                            <td className="py-2 pr-4">{loc.max_quantity ?? '-'}</td>
                                            <td className="py-2 pr-4">
                                                <Badge className={loc.is_labelled ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}>
                                                    {loc.is_labelled ? 'Yes' : 'No'}
                                                </Badge>
                                            </td>
                                            <td className="py-2">
                                                <Badge className={loc.segregation_compliant ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}>
                                                    {loc.segregation_compliant ? 'Yes' : 'No'}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!substance.storage_locations.length && (
                                <div className="py-4 text-center text-sm text-slate-500">
                                    No storage locations recorded.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Exposure Records */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Activity className="h-4 w-4" />
                                Exposure Records
                            </CardTitle>
                            {substance.can_manage_entries && (
                                <Button size="sm" onClick={() => setExposureOpen(true)}>
                                    Record Exposure
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-slate-500">
                                        <th className="pb-2 pr-4 font-medium">Worker</th>
                                        <th className="pb-2 pr-4 font-medium">Date</th>
                                        <th className="pb-2 pr-4 font-medium">Exposure Type</th>
                                        <th className="pb-2 pr-4 font-medium">Symptoms</th>
                                        <th className="pb-2 font-medium">Medical Attention</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {substance.exposure_records.map((exp) => (
                                        <tr key={exp.id} className="border-b last:border-0">
                                            <td className="py-2 pr-4 font-medium">{exp.user?.name ?? 'Unknown'}</td>
                                            <td className="py-2 pr-4">
                                                {new Date(exp.exposure_date).toLocaleDateString('en-GB')}
                                            </td>
                                            <td className="py-2 pr-4 capitalize">{exp.exposure_type}</td>
                                            <td className="py-2 pr-4">{exp.symptoms ?? '-'}</td>
                                            <td className="py-2">
                                                <Badge className={exp.medical_attention ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'}>
                                                    {exp.medical_attention ? 'Yes' : 'No'}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!substance.exposure_records.length && (
                                <div className="py-4 text-center text-sm text-slate-500">
                                    No exposure records found.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Upload SDS Dialog */}
            <Dialog open={sdsOpen} onOpenChange={setSdsOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Upload Safety Data Sheet</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label>Version</Label>
                            <Input
                                value={sdsForm.data.version}
                                onChange={(e) => sdsForm.setData('version', e.target.value)}
                                placeholder="e.g. 3.1"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Issue Date</Label>
                            <Input
                                type="date"
                                value={sdsForm.data.issue_date}
                                onChange={(e) => sdsForm.setData('issue_date', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Supplier</Label>
                            <Input
                                value={sdsForm.data.supplier}
                                onChange={(e) => sdsForm.setData('supplier', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>SDS File</Label>
                            <Input
                                type="file"
                                accept=".pdf,.doc,.docx"
                                onChange={(e) => sdsForm.setData('file', e.target.files?.[0] ?? null)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setSdsOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={sdsForm.processing}
                            onClick={() =>
                                sdsForm.post(`/health-safety/substances/${substance.id}/sds`, {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setSdsOpen(false);
                                        sdsForm.reset();
                                    },
                                })
                            }
                        >
                            Upload
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Add Storage Location Dialog */}
            <Dialog open={storageOpen} onOpenChange={setStorageOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Storage Location</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label>Site</Label>
                            <Select
                                value={storageForm.data.site_id || '__none__'}
                                onValueChange={(v) => storageForm.setData('site_id', v === '__none__' ? '' : v)}
                            >
                                <SelectTrigger><SelectValue placeholder="Select site" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">Select...</SelectItem>
                                    {sites.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label>Location Description</Label>
                            <Input
                                value={storageForm.data.location_description}
                                onChange={(e) => storageForm.setData('location_description', e.target.value)}
                                placeholder="e.g. Chemical store, Shelf B3"
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Current Quantity</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    value={storageForm.data.current_quantity}
                                    onChange={(e) => storageForm.setData('current_quantity', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>Max Quantity</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    value={storageForm.data.max_quantity}
                                    onChange={(e) => storageForm.setData('max_quantity', e.target.value)}
                                />
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={!!storageForm.data.is_labelled}
                                onCheckedChange={(v) => storageForm.setData('is_labelled', !!v)}
                            />
                            <Label>Properly labelled</Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={!!storageForm.data.segregation_compliant}
                                onCheckedChange={(v) => storageForm.setData('segregation_compliant', !!v)}
                            />
                            <Label>Segregation compliant</Label>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setStorageOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={storageForm.processing}
                            onClick={() =>
                                storageForm.post(`/health-safety/substances/${substance.id}/storage-locations`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setStorageOpen(false);
                                        storageForm.reset();
                                    },
                                })
                            }
                        >
                            Add Location
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Record Exposure Dialog */}
            <Dialog open={exposureOpen} onOpenChange={setExposureOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Record Exposure</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label>Worker</Label>
                            <Select
                                value={exposureForm.data.user_id || '__none__'}
                                onValueChange={(v) => exposureForm.setData('user_id', v === '__none__' ? '' : v)}
                            >
                                <SelectTrigger><SelectValue placeholder="Select worker" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">Select...</SelectItem>
                                    {staff.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label>Date</Label>
                            <Input
                                type="date"
                                value={exposureForm.data.exposure_date}
                                onChange={(e) => exposureForm.setData('exposure_date', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Exposure Type</Label>
                            <Select
                                value={exposureForm.data.exposure_type}
                                onValueChange={(v) => exposureForm.setData('exposure_type', v)}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="inhalation">Inhalation</SelectItem>
                                    <SelectItem value="skin_contact">Skin Contact</SelectItem>
                                    <SelectItem value="eye_contact">Eye Contact</SelectItem>
                                    <SelectItem value="ingestion">Ingestion</SelectItem>
                                    <SelectItem value="injection">Injection</SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label>Symptoms</Label>
                            <Textarea
                                value={exposureForm.data.symptoms}
                                onChange={(e) => exposureForm.setData('symptoms', e.target.value)}
                            />
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={!!exposureForm.data.medical_attention}
                                onCheckedChange={(v) => exposureForm.setData('medical_attention', !!v)}
                            />
                            <Label>Medical attention required</Label>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setExposureOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={exposureForm.processing}
                            onClick={() =>
                                exposureForm.post(`/health-safety/substances/${substance.id}/exposures`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setExposureOpen(false);
                                        exposureForm.reset();
                                    },
                                })
                            }
                        >
                            Record Exposure
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
