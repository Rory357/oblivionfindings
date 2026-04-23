import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Building2, Home, Warehouse, MapPin, AlertTriangle, Upload, FileText, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

type Site = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility' | 'residential';
    phone?: string;
    email?: string;
    manager_name?: string;
    manager_phone?: string;
    after_hours_phone?: string;
    emergency_plan_location?: string;
    medication_storage_location?: string;
    notes?: string;
    address_line_1?: string;
    address_line_2?: string;
    suburb?: string;
    city?: string;
    postcode?: string;
    country?: string;
    region?: string;
    latitude?: string;
    longitude?: string;
    access_instructions?: string;
    is_active: boolean;
    is_high_risk: boolean;
    is_high_needs: boolean;
    risk_notes?: string;
    risk_review_date?: string;
    primary_contact_user_id?: number;
};

type User = {
    id: number;
    name: string;
};

type SiteDocument = {
    id: number;
    title: string;
    category?: string;
    expiry_date?: string;
    notes?: string;
    original_name: string;
    size_bytes: number;
};

type PageProps = {
    site: Site;
    users: User[];
    documents: SiteDocument[];
    labels?: Record<string, string>;
};

const siteTypes = [
    { value: 'head_office', label: 'Head Office', icon: Building2, description: 'Administrative headquarters with meeting rooms' },
    { value: 'house', label: 'House', icon: Home, description: 'Residential home with client bedrooms' },
    { value: 'facility', label: 'Facilities', icon: Warehouse, description: 'Workshop, cafe, or day programme space' },
    { value: 'residential', label: 'Residential', icon: Home, description: 'Client home used for residential/home-support visits' },
];

export default function EditSite() {
    const { site, users, documents: initialDocuments, labels } = usePage<PageProps>().props;
    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const sitePlural = labels?.['site.plural'] ?? 'Sites';

    const [uploadedDocs, setUploadedDocs] = useState<SiteDocument[]>(initialDocuments ?? []);
    const [uploading, setUploading] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [pendingDoc, setPendingDoc] = useState({ title: '', category: '', expiry_date: '', notes: '' });

    const csrfToken = () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

    const handleUploadDocument = async () => {
        const file = fileInputRef.current?.files?.[0];
        if (!file || !pendingDoc.title.trim()) return;

        setUploading(true);
        try {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('title', pendingDoc.title);
            if (pendingDoc.category) formData.append('category', pendingDoc.category);
            if (pendingDoc.expiry_date) formData.append('expiry_date', pendingDoc.expiry_date);
            if (pendingDoc.notes) formData.append('notes', pendingDoc.notes);

            const res = await fetch(`/sites/${site.id}/documents`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken() },
                body: formData,
            });

            if (res.ok) {
                const json = await res.json();
                setUploadedDocs((prev) => [json.document, ...prev]);
                setPendingDoc({ title: '', category: '', expiry_date: '', notes: '' });
                if (fileInputRef.current) fileInputRef.current.value = '';
            } else {
                alert('Failed to upload document. Please try again.');
            }
        } catch {
            alert('Upload failed. Please check your connection and try again.');
        } finally {
            setUploading(false);
        }
    };

    const handleDeleteDocument = async (docId: number) => {
        if (!confirm('Are you sure you want to delete this document?')) return;
        try {
            const res = await fetch(`/sites/${site.id}/documents/${docId}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken() },
            });
            if (res.ok) {
                setUploadedDocs((prev) => prev.filter((d) => d.id !== docId));
            } else {
                alert('Failed to delete document.');
            }
        } catch {
            alert('Delete failed. Please check your connection and try again.');
        }
    };

    const formatFileSize = (bytes: number) => {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    };

    const { data, setData, put, processing, errors } = useForm({
        name: site.name,
        type: site.type,
        phone: site.phone ?? '',
        email: site.email ?? '',
        manager_name: site.manager_name ?? '',
        manager_phone: site.manager_phone ?? '',
        after_hours_phone: site.after_hours_phone ?? '',
        emergency_plan_location: site.emergency_plan_location ?? '',
        medication_storage_location: site.medication_storage_location ?? '',
        notes: site.notes ?? '',
        address_line_1: site.address_line_1 ?? '',
        address_line_2: site.address_line_2 ?? '',
        suburb: site.suburb ?? '',
        city: site.city ?? '',
        postcode: site.postcode ?? '',
        country: site.country ?? 'New Zealand',
        region: site.region ?? '',
        latitude: site.latitude ?? '',
        longitude: site.longitude ?? '',
        access_instructions: site.access_instructions ?? '',
        is_active: site.is_active,
        is_high_risk: site.is_high_risk,
        is_high_needs: site.is_high_needs,
        risk_notes: site.risk_notes ?? '',
        risk_review_date: site.risk_review_date ?? '',
        primary_contact_user_id: site.primary_contact_user_id?.toString() ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/sites/${site.id}`);
    }

    const selectedType = siteTypes.find(t => t.value === data.type);
    const TypeIcon = selectedType?.icon || Home;

    return (
        <AppLayout
            breadcrumbs={[
                { title: sitePlural, href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Edit', href: `/sites/${site.id}/edit` },
            ]}
        >
            <Head title={`Edit ${siteSingular}`} />

            <div className="m-4 max-w-4xl">
                <form onSubmit={submit} className="space-y-6">
                    {/* Type Selection - Prominent */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <TypeIcon className="w-5 h-5" />
                                Site Type
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-3 gap-4">
                                {siteTypes.map((type) => {
                                    const Icon = type.icon;
                                    const isSelected = data.type === type.value;
                                    return (
                                        <div
                                            key={type.value}
                                            onClick={() => setData('type', type.value as any)}
                                            className={`cursor-pointer rounded-lg border p-4 transition-colors ${
                                                isSelected
                                                    ? 'border-primary bg-primary/10'
                                                    : 'border hover:border-primary/50'
                                            }`}
                                        >
                                            <div className="flex items-center gap-2 mb-2">
                                                <Icon className={`w-5 h-5 ${isSelected ? 'text-primary' : 'text-muted-foreground'}`} />
                                                <span className={`font-medium ${isSelected ? 'text-primary/70' : ''}`}>
                                                    {type.label}
                                                </span>
                                            </div>
                                            <p className="text-xs text-muted-foreground">{type.description}</p>
                                        </div>
                                    );
                                })}
                            </div>
                            <input type="hidden" name="type" value={data.type} />
                            {errors.type && <div className="mt-2 text-sm text-red-400">{errors.type}</div>}
                        </CardContent>
                    </Card>

                    {/* Basic Information */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Basic Information</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label htmlFor="name">Site Name *</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="mt-1"
                                />
                                {errors.name && <div className="mt-1 text-sm text-red-400">{errors.name}</div>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="phone">Primary Phone</Label>
                                    <Input
                                        id="phone"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="primary_contact_user_id">Site Lead / Manager</Label>
                                <Select
                                    value={data.primary_contact_user_id || undefined}
                                    onValueChange={(v) => setData('primary_contact_user_id', v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Select manager..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {users.map((u) => (
                                            <SelectItem key={u.id} value={u.id.toString()}>{u.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="manager_name">Manager Name</Label>
                                    <Input
                                        id="manager_name"
                                        value={data.manager_name}
                                        onChange={(e) => setData('manager_name', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="manager_phone">Manager Phone</Label>
                                    <Input
                                        id="manager_phone"
                                        value={data.manager_phone}
                                        onChange={(e) => setData('manager_phone', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="after_hours_phone">After-hours Phone</Label>
                                <Input
                                    id="after_hours_phone"
                                    value={data.after_hours_phone}
                                    onChange={(e) => setData('after_hours_phone', e.target.value)}
                                    className="mt-1"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Address */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <MapPin className="w-5 h-5" />
                                Address & Location
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="address_line_1">Address Line 1</Label>
                                    <Input
                                        id="address_line_1"
                                        value={data.address_line_1}
                                        onChange={(e) => setData('address_line_1', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="address_line_2">Address Line 2</Label>
                                    <Input
                                        id="address_line_2"
                                        value={data.address_line_2}
                                        onChange={(e) => setData('address_line_2', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <Label htmlFor="suburb">Suburb</Label>
                                    <Input
                                        id="suburb"
                                        value={data.suburb}
                                        onChange={(e) => setData('suburb', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="city">City</Label>
                                    <Input
                                        id="city"
                                        value={data.city}
                                        onChange={(e) => setData('city', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="postcode">Postcode</Label>
                                    <Input
                                        id="postcode"
                                        value={data.postcode}
                                        onChange={(e) => setData('postcode', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="country">Country</Label>
                                    <Input
                                        id="country"
                                        value={data.country}
                                        onChange={(e) => setData('country', e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="region">Region</Label>
                                    <Input
                                        id="region"
                                        value={data.region}
                                        onChange={(e) => setData('region', e.target.value)}
                                        className="mt-1"
                                        placeholder="e.g., North Island, Auckland"
                                    />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="latitude">Latitude (GPS)</Label>
                                    <Input
                                        id="latitude"
                                        type="number"
                                        step="any"
                                        value={data.latitude}
                                        onChange={(e) => setData('latitude', e.target.value)}
                                        className="mt-1"
                                        placeholder="-36.8485"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="longitude">Longitude (GPS)</Label>
                                    <Input
                                        id="longitude"
                                        type="number"
                                        step="any"
                                        value={data.longitude}
                                        onChange={(e) => setData('longitude', e.target.value)}
                                        className="mt-1"
                                        placeholder="174.7633"
                                    />
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="access_instructions">Access Instructions</Label>
                                <Textarea
                                    id="access_instructions"
                                    value={data.access_instructions}
                                    onChange={(e) => setData('access_instructions', e.target.value)}
                                    className="mt-1"
                                    rows={3}
                                    placeholder="Gate codes, key locations, parking instructions..."
                                />
                                <p className="text-xs text-muted-foreground mt-1">
                                    This information is permission-protected
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Risk Flags */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-amber-400">
                                <AlertTriangle className="w-5 h-5" />
                                Risk Assessment
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex flex-wrap gap-4">
                                <div className="flex items-center space-x-2">
                                    <Checkbox
                                        id="is_high_risk"
                                        checked={data.is_high_risk}
                                        onCheckedChange={(checked) => setData('is_high_risk', checked as boolean)}
                                    />
                                    <Label htmlFor="is_high_risk" className="font-normal cursor-pointer">
                                        High Risk Site
                                    </Label>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <Checkbox
                                        id="is_high_needs"
                                        checked={data.is_high_needs}
                                        onCheckedChange={(checked) => setData('is_high_needs', checked as boolean)}
                                    />
                                    <Label htmlFor="is_high_needs" className="font-normal cursor-pointer">
                                        High Needs Site
                                    </Label>
                                </div>
                            </div>

                            {(data.is_high_risk || data.is_high_needs) && (
                                <>
                                    <div>
                                        <Label htmlFor="risk_notes">Risk Notes / Reason</Label>
                                        <Textarea
                                            id="risk_notes"
                                            value={data.risk_notes}
                                            onChange={(e) => setData('risk_notes', e.target.value)}
                                            className="mt-1"
                                            rows={3}
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="risk_review_date">Risk Review Date</Label>
                                        <Input
                                            id="risk_review_date"
                                            type="date"
                                            value={data.risk_review_date}
                                            onChange={(e) => setData('risk_review_date', e.target.value)}
                                            className="mt-1"
                                        />
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>

                    {/* Safety & Operations */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Safety & Operations</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label htmlFor="emergency_plan_location">Emergency Plan Location</Label>
                                <Input
                                    id="emergency_plan_location"
                                    value={data.emergency_plan_location}
                                    onChange={(e) => setData('emergency_plan_location', e.target.value)}
                                    className="mt-1"
                                    placeholder="e.g., Kitchen drawer, Office filing cabinet"
                                />
                            </div>
                            <div>
                                <Label htmlFor="medication_storage_location">Medication Storage Location</Label>
                                <Input
                                    id="medication_storage_location"
                                    value={data.medication_storage_location}
                                    onChange={(e) => setData('medication_storage_location', e.target.value)}
                                    className="mt-1"
                                    placeholder="e.g., Locked cabinet in office"
                                />
                            </div>
                            <div>
                                <Label htmlFor="notes">General Notes</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    className="mt-1"
                                    rows={4}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Documents */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileText className="w-5 h-5" />
                                Documents
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Upload form */}
                            <div className="p-4 rounded-lg border space-y-3">
                                <h4 className="text-sm font-medium flex items-center gap-2">
                                    <Upload className="w-4 h-4" />
                                    Upload Document
                                </h4>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Title *</Label>
                                        <Input
                                            value={pendingDoc.title}
                                            onChange={(e) => setPendingDoc({ ...pendingDoc, title: e.target.value })}
                                            placeholder="e.g. Fire Evacuation Plan"
                                        />
                                    </div>
                                    <div>
                                        <Label>Category</Label>
                                        <Select
                                            value={pendingDoc.category || undefined}
                                            onValueChange={(v) => setPendingDoc({ ...pendingDoc, category: v })}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select category" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="evacuation_plan">Evacuation Plan</SelectItem>
                                                <SelectItem value="compliance_cert">Compliance Certificate</SelectItem>
                                                <SelectItem value="insurance">Insurance</SelectItem>
                                                <SelectItem value="lease">Lease / Tenancy</SelectItem>
                                                <SelectItem value="safety">Health & Safety</SelectItem>
                                                <SelectItem value="policy">Policy</SelectItem>
                                                <SelectItem value="other">Other</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Expiry Date</Label>
                                        <Input
                                            type="date"
                                            value={pendingDoc.expiry_date}
                                            onChange={(e) => setPendingDoc({ ...pendingDoc, expiry_date: e.target.value })}
                                        />
                                    </div>
                                </div>
                                <div>
                                    <Label>Comments</Label>
                                    <Textarea
                                        value={pendingDoc.notes}
                                        onChange={(e) => setPendingDoc({ ...pendingDoc, notes: e.target.value })}
                                        placeholder="Add any comments or notes about this document..."
                                        rows={3}
                                    />
                                </div>
                                <div>
                                    <Label>File *</Label>
                                    <Input
                                        ref={fileInputRef}
                                        type="file"
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp"
                                        className="cursor-pointer"
                                    />
                                    <p className="text-xs text-muted-foreground mt-1">PDF, Word, Excel, or images. Max 20MB.</p>
                                </div>
                                <Button
                                    type="button"
                                    onClick={handleUploadDocument}
                                    disabled={uploading || !pendingDoc.title.trim()}
                                >
                                    <Upload className="w-4 h-4 mr-2" />
                                    {uploading ? 'Uploading...' : 'Upload Document'}
                                </Button>
                            </div>

                            {/* Uploaded documents list */}
                            {uploadedDocs.length > 0 && (
                                <div className="space-y-2">
                                    <h4 className="text-sm font-medium">Uploaded Documents ({uploadedDocs.length})</h4>
                                    {uploadedDocs.map((doc) => (
                                        <div key={doc.id} className="flex items-center justify-between p-3 rounded-lg border">
                                            <div className="flex items-center gap-3 min-w-0">
                                                <FileText className="w-5 h-5 text-primary flex-shrink-0" />
                                                <div className="min-w-0">
                                                    <p className="text-sm font-medium truncate">{doc.title}</p>
                                                    <p className="text-xs text-muted-foreground truncate">
                                                        {doc.original_name}
                                                        {doc.size_bytes > 0 && ` · ${formatFileSize(doc.size_bytes)}`}
                                                        {doc.category && ` · ${doc.category}`}
                                                    </p>
                                                </div>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="text-red-400 hover:text-red-300 flex-shrink-0"
                                                onClick={() => handleDeleteDocument(doc.id)}
                                            >
                                                <Trash2 className="w-4 h-4" />
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Status */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="is_active"
                                    checked={data.is_active}
                                    onCheckedChange={(checked) => setData('is_active', checked as boolean)}
                                />
                                <Label htmlFor="is_active" className="font-normal cursor-pointer">
                                    Site is active and operational
                                </Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
