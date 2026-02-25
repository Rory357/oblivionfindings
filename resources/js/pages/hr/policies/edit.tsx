import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { ArrowLeft, FileText, Upload, FileCheck, X, Trash2, AlertTriangle } from 'lucide-react';
import { useState, useRef } from 'react';

type BreadcrumbItem = { title: string; href: string };

type PolicyVersion = {
    id: number;
    version_number: string;
    document_path: string | null;
    content_summary: string | null;
    effective_from: string;
    is_current: boolean;
};

type Policy = {
    id: number;
    title: string;
    category: string;
    is_active: boolean;
    requires_attestation: boolean;
    attestation_frequency_months: number | null;
    versions: PolicyVersion[];
};

type Option = {
    value: string;
    label: string;
};

type Props = {
    policy: Policy;
    existingCategories: string[];
    defaultCategories: Option[];
};

export default function EditPolicy({ policy, existingCategories, defaultCategories }: Props) {
    const [showCustomCategory, setShowCustomCategory] = useState(false);
    const [showNewVersionForm, setShowNewVersionForm] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const { data, setData, put, processing, errors } = useForm({
        title: policy.title,
        category: policy.category,
        custom_category: '',
        is_active: policy.is_active,
        requires_attestation: policy.requires_attestation,
        attestation_frequency_months: String(policy.attestation_frequency_months ?? 12),
    });

    const { data: versionData, setData: setVersionData, post: postVersion, processing: versionProcessing, errors: versionErrors } = useForm({
        document: null as File | null,
        content_summary: '',
        effective_from: new Date().toISOString().split('T')[0],
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/hr/policies/${policy.id}`);
    };

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0] || null;
        if (!file) return;

        if (file.type !== 'application/pdf') {
            alert('Please select a PDF file only');
            return;
        }

        const MAX_FILE_SIZE = 8 * 1024 * 1024;
        if (file.size > MAX_FILE_SIZE) {
            alert(`File is too large. Maximum allowed size is 8MB.`);
            return;
        }

        setVersionData('document', file);
    };

    const handleVersionSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        postVersion(`/hr/policies/${policy.id}/versions`, {
            forceFormData: true,
            onSuccess: () => {
                setShowNewVersionForm(false);
                setVersionData({
                    document: null,
                    content_summary: '',
                    effective_from: new Date().toISOString().split('T')[0],
                });
            },
        });
    };

    const handleDelete = () => {
        if (confirm('Are you sure you want to delete this policy? This action cannot be undone.')) {
            router.delete(`/hr/policies/${policy.id}`);
        }
    };

    const handleDeleteVersion = (versionId: number) => {
        if (confirm('Are you sure you want to delete this version?')) {
            router.delete(`/hr/policies/${policy.id}/versions/${versionId}`);
        }
    };

    const formatFileSize = (bytes: number) => {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    };

    const allCategories = [...new Set([...existingCategories, ...defaultCategories.map(c => c.value)])];
    const categoryOptions = allCategories.map(cat => {
        const defaultCat = defaultCategories.find(c => c.value === cat);
        return {
            value: cat,
            label: defaultCat ? defaultCat.label : cat.replace(/_/g, ' '),
        };
    }).sort((a, b) => a.label.localeCompare(b.label));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Policies', href: '/hr/policies' },
        { title: policy.title, href: `/hr/policies/${policy.id}` },
        { title: 'Edit', href: `/hr/policies/${policy.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit Policy - ${policy.title}`} />

            <div className="space-y-6 max-w-4xl">
                <div className="flex items-center gap-4">
                    <Link href={`/hr/policies/${policy.id}`}>
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back
                        </Button>
                    </Link>
                    <div className="flex items-center gap-3">
                        <FileText className="h-6 w-6 text-blue-500" />
                        <div>
                            <h1 className="text-2xl font-bold">Edit Policy</h1>
                            <p className="text-muted-foreground">Update policy details</p>
                        </div>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Policy Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="title">Policy Title</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    className={errors.title ? 'border-red-500' : ''}
                                />
                                {errors.title && <p className="text-sm text-red-500">{errors.title}</p>}
                            </div>

                            <div className="space-y-3">
                                <div className="flex items-center gap-4">
                                    <Label>Category</Label>
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id="use_existing"
                                            checked={!showCustomCategory}
                                            onCheckedChange={(checked) => setShowCustomCategory(!checked as boolean)}
                                        />
                                        <Label htmlFor="use_existing" className="text-sm font-normal">Use existing category</Label>
                                    </div>
                                </div>

                                {!showCustomCategory ? (
                                    <Select value={data.category} onValueChange={(value) => setData('category', value)}>
                                        <SelectTrigger className={errors.category ? 'border-red-500' : ''}>
                                            <SelectValue placeholder="Select a category" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categoryOptions.map((cat) => (
                                                <SelectItem key={cat.value} value={cat.value}>{cat.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <Input
                                        placeholder="Enter custom category"
                                        value={data.custom_category}
                                        onChange={(e) => setData('custom_category', e.target.value)}
                                        className={errors.category ? 'border-red-500' : ''}
                                    />
                                )}
                            </div>

                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="is_active"
                                    checked={data.is_active}
                                    onCheckedChange={(checked) => setData('is_active', checked as boolean)}
                                />
                                <Label htmlFor="is_active">Policy is active</Label>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Attestation Settings</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-start space-x-2">
                                <Checkbox
                                    id="requires_attestation"
                                    checked={data.requires_attestation}
                                    onCheckedChange={(checked) => setData('requires_attestation', checked as boolean)}
                                />
                                <div className="space-y-1">
                                    <Label htmlFor="requires_attestation">Requires staff attestation</Label>
                                    <p className="text-xs text-muted-foreground">Staff will be required to acknowledge they have read and understood this policy</p>
                                </div>
                            </div>

                            {data.requires_attestation && (
                                <div className="space-y-2 pl-6">
                                    <Label>Re-attestation Frequency (months)</Label>
                                    <Select
                                        value={data.attestation_frequency_months}
                                        onValueChange={(value) => setData('attestation_frequency_months', value)}
                                    >
                                        <SelectTrigger className="w-48">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="6">Every 6 months</SelectItem>
                                            <SelectItem value="12">Every 12 months</SelectItem>
                                            <SelectItem value="24">Every 24 months</SelectItem>
                                            <SelectItem value="36">Every 36 months</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-4">
                        <Link href={`/hr/policies/${policy.id}`}>
                            <Button type="button" variant="outline">Cancel</Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>

                {/* Document Versions */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>Document Versions</CardTitle>
                        <Button type="button" variant="outline" onClick={() => setShowNewVersionForm(!showNewVersionForm)}>
                            <Upload className="mr-2 h-4 w-4" />
                            Upload New Version
                        </Button>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {showNewVersionForm && (
                            <form onSubmit={handleVersionSubmit} className="rounded-lg border p-4 space-y-4">
                                <h4 className="font-medium">Upload New Version</h4>
                                
                                <div className="space-y-2">
                                    <Label>PDF Document</Label>
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        accept=".pdf,application/pdf"
                                        onChange={handleFileChange}
                                        className="hidden"
                                    />
                                    <div className="flex items-center gap-4">
                                        <Button type="button" variant="outline" onClick={() => fileInputRef.current?.click()}>
                                            <Upload className="mr-2 h-4 w-4" />
                                            Choose PDF
                                        </Button>
                                        {versionData.document ? (
                                            <div className="flex items-center gap-2 text-sm">
                                                <FileCheck className="h-4 w-4 text-green-500" />
                                                <span>{versionData.document.name}</span>
                                                <span className="text-muted-foreground">({formatFileSize(versionData.document.size)})</span>
                                            </div>
                                        ) : <span className="text-sm text-muted-foreground">No file selected</span>}
                                    </div>
                                    {versionErrors.document && <p className="text-sm text-red-500">{versionErrors.document}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label>Content Summary</Label>
                                    <Textarea
                                        placeholder="Brief summary of changes in this version..."
                                        value={versionData.content_summary}
                                        onChange={(e) => setVersionData('content_summary', e.target.value)}
                                        rows={3}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label>Effective From</Label>
                                    <Input
                                        type="date"
                                        value={versionData.effective_from}
                                        onChange={(e) => setVersionData('effective_from', e.target.value)}
                                    />
                                </div>

                                <div className="flex gap-2">
                                    <Button type="submit" disabled={versionProcessing || !versionData.document}>
                                        {versionProcessing ? 'Uploading...' : 'Upload Version'}
                                    </Button>
                                    <Button type="button" variant="ghost" onClick={() => setShowNewVersionForm(false)}>
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        )}

                        <div className="space-y-2">
                            {policy.versions.map((version) => (
                                <div key={version.id} className={`flex items-center justify-between rounded-lg border p-3 ${version.is_current ? 'border-blue-500 bg-blue-50/50' : ''}`}>
                                    <div className="flex items-center gap-3">
                                        <FileText className="h-5 w-5 text-slate-400" />
                                        <div>
                                            <div className="font-medium">
                                                Version {version.version_number}
                                                {version.is_current && <span className="ml-2 text-xs text-blue-600">(Current)</span>}
                                            </div>
                                            {version.document_path && (
                                                <div className="text-xs text-muted-foreground">{version.document_path.split('/').pop()}</div>
                                            )}
                                        </div>
                                    </div>
                                    {!version.is_current && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="text-red-500 hover:text-red-600"
                                            onClick={() => handleDeleteVersion(version.id)}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Delete Policy */}
                <Card className="border-red-200">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-red-600">
                            <AlertTriangle className="h-5 w-5" />
                            Danger Zone
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-sm text-muted-foreground mb-4">
                            Deleting this policy will permanently remove it and all associated versions and attestations. This action cannot be undone.
                        </p>
                        <Button type="button" variant="destructive" onClick={handleDelete}>
                            <Trash2 className="mr-2 h-4 w-4" />
                            Delete Policy
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
