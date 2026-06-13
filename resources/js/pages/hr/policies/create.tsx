import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FileCheck, FileText, Upload, X } from 'lucide-react';
import { useRef, useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type Option = {
    value: string;
    label: string;
};

type Props = {
    existingCategories: string[];
    defaultCategories: Option[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Policies', href: '/hr/policies' },
    { title: 'Create Policy', href: '/hr/policies/create' },
];

export default function CreatePolicy({
    existingCategories,
    defaultCategories,
}: Props) {
    const [showCustomCategory, setShowCustomCategory] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const { data, setData, transform, post, processing, errors, progress } =
        useForm({
            title: '',
            category: '',
            custom_category: '',
            requires_attestation: false,
            attestation_frequency_months: '12',
            content_summary: '',
            effective_from: new Date().toISOString().split('T')[0],
            content_mode: 'pdf_only' as 'pdf_only' | 'pdf_and_summary',
            document: null as File | null,
        });

    const MAX_FILE_SIZE = 8 * 1024 * 1024; // 8MB in bytes

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0] || null;
        if (!file) return;

        // Check file type
        if (file.type !== 'application/pdf') {
            alert('Please select a PDF file only');
            clearFile();
            return;
        }

        // Check file size (8MB limit matching PHP config)
        if (file.size > MAX_FILE_SIZE) {
            alert(
                `File is too large (${formatFileSize(file.size)}). Maximum allowed size is 8MB.`,
            );
            clearFile();
            return;
        }

        setData('document', file);
    };

    const clearFile = () => {
        setData('document', null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        transform((current) => ({
            ...current,
            category: showCustomCategory
                ? current.custom_category
                : current.category,
            content_summary:
                current.content_mode === 'pdf_and_summary'
                    ? current.content_summary
                    : '',
        }));
        post('/hr/policies', {
            forceFormData: true,
        });
    };

    // Combine existing and default categories
    const allCategories = [
        ...new Set([
            ...existingCategories,
            ...defaultCategories.map((c) => c.value),
        ]),
    ];
    const categoryOptions = allCategories
        .map((cat) => {
            const defaultCat = defaultCategories.find((c) => c.value === cat);
            return {
                value: cat,
                label: defaultCat ? defaultCat.label : cat.replace(/_/g, ' '),
            };
        })
        .sort((a, b) => a.label.localeCompare(b.label));

    const formatFileSize = (bytes: number) => {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return (
            Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Policy" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/policies"
                        title="Create New Policy"
                        description="Add a new policy to the library."
                    />
                }
            >
                <div className="max-w-4xl space-y-6">

                <form
                    onSubmit={handleSubmit}
                    className="space-y-6"
                    encType="multipart/form-data"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle>Policy Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="title">
                                    Policy Title{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Input
                                    id="title"
                                    placeholder="e.g., Staff Code of Conduct"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                    className={
                                        errors.title
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.title && (
                                    <p className="text-sm text-status-critical">
                                        {errors.title}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-3">
                                <div className="flex items-center gap-4">
                                    <Label>Category</Label>
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id="use_existing"
                                            checked={!showCustomCategory}
                                            onCheckedChange={(checked) =>
                                                setShowCustomCategory(
                                                    !checked as boolean,
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor="use_existing"
                                            className="text-sm font-normal"
                                        >
                                            Use existing category
                                        </Label>
                                    </div>
                                </div>

                                {!showCustomCategory ? (
                                    <div className="space-y-2">
                                        <Select
                                            value={data.category}
                                            onValueChange={(value) =>
                                                setData('category', value)
                                            }
                                        >
                                            <SelectTrigger
                                                className={
                                                    errors.category
                                                        ? 'border-status-critical/30'
                                                        : ''
                                                }
                                            >
                                                <SelectValue placeholder="Select a category" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {categoryOptions.map((cat) => (
                                                    <SelectItem
                                                        key={cat.value}
                                                        value={cat.value}
                                                    >
                                                        {cat.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.category && (
                                            <p className="text-sm text-status-critical">
                                                {errors.category}
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        <Input
                                            placeholder="Enter custom category"
                                            value={data.custom_category}
                                            onChange={(e) =>
                                                setData(
                                                    'custom_category',
                                                    e.target.value,
                                                )
                                            }
                                            className={
                                                errors.category
                                                    ? 'border-status-critical/30'
                                                    : ''
                                            }
                                        />
                                        {errors.category && (
                                            <p className="text-sm text-status-critical">
                                                {errors.category}
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="effective_from">
                                        Effective From
                                    </Label>
                                    <Input
                                        id="effective_from"
                                        type="date"
                                        value={data.effective_from}
                                        onChange={(e) =>
                                            setData(
                                                'effective_from',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.effective_from
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.effective_from && (
                                        <p className="text-sm text-status-critical">
                                            {errors.effective_from}
                                        </p>
                                    )}
                                </div>
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
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'requires_attestation',
                                            checked as boolean,
                                        )
                                    }
                                />
                                <div className="space-y-1">
                                    <Label
                                        htmlFor="requires_attestation"
                                        className="text-sm font-medium"
                                    >
                                        Requires staff attestation
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Staff will be required to acknowledge
                                        they have read and understood this
                                        policy
                                    </p>
                                </div>
                            </div>

                            {data.requires_attestation && (
                                <div className="space-y-2 pl-6">
                                    <Label htmlFor="attestation_frequency_months">
                                        Re-attestation Frequency (months)
                                    </Label>
                                    <Select
                                        value={
                                            data.attestation_frequency_months
                                        }
                                        onValueChange={(value) =>
                                            setData(
                                                'attestation_frequency_months',
                                                value,
                                            )
                                        }
                                    >
                                        <SelectTrigger className="w-48">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="6">
                                                Every 6 months
                                            </SelectItem>
                                            <SelectItem value="12">
                                                Every 12 months
                                            </SelectItem>
                                            <SelectItem value="24">
                                                Every 24 months
                                            </SelectItem>
                                            <SelectItem value="36">
                                                Every 36 months
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        How often staff must re-attest to this
                                        policy
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Policy Content</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Content Mode Selection */}
                            <div className="space-y-3">
                                <Label>Content Format</Label>
                                <RadioGroup
                                    value={data.content_mode}
                                    onValueChange={(value) =>
                                        setData(
                                            'content_mode',
                                            value as
                                                | 'pdf_only'
                                                | 'pdf_and_summary',
                                        )
                                    }
                                    className="grid grid-cols-1 gap-4 md:grid-cols-2"
                                >
                                    <label className="flex cursor-pointer items-start gap-3 rounded-md border p-3 transition-colors hover:bg-muted [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5">
                                        <RadioGroupItem
                                            value="pdf_only"
                                            className="mt-0.5"
                                        />
                                        <div className="space-y-1">
                                            <div className="text-sm font-medium">
                                                PDF Document Only
                                            </div>
                                            <p className="text-xs font-normal text-muted-foreground">
                                                Upload a PDF document as the
                                                complete policy
                                            </p>
                                        </div>
                                    </label>
                                    <label className="flex cursor-pointer items-start gap-3 rounded-md border p-3 transition-colors hover:bg-muted [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5">
                                        <RadioGroupItem
                                            value="pdf_and_summary"
                                            className="mt-0.5"
                                        />
                                        <div className="space-y-1">
                                            <div className="text-sm font-medium">
                                                PDF + Content Summary
                                            </div>
                                            <p className="text-xs font-normal text-muted-foreground">
                                                Upload PDF and provide a text
                                                summary for quick reference
                                            </p>
                                        </div>
                                    </label>
                                </RadioGroup>
                            </div>

                            {/* File Upload */}
                            <div className="space-y-2">
                                <Label htmlFor="document">
                                    PDF Document{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <div className="flex items-center gap-4">
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        id="document"
                                        accept=".pdf,application/pdf"
                                        onChange={handleFileChange}
                                        className="hidden"
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            fileInputRef.current?.click()
                                        }
                                        className="gap-2"
                                    >
                                        <Upload className="h-4 w-4" />
                                        Choose PDF
                                    </Button>
                                    {data.document ? (
                                        <div className="flex items-center gap-2 text-sm">
                                            <FileCheck className="h-4 w-4 text-status-success" />
                                            <span className="font-medium">
                                                {data.document.name}
                                            </span>
                                            <span className="text-muted-foreground">
                                                (
                                                {formatFileSize(
                                                    data.document.size,
                                                )}
                                                )
                                            </span>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={clearFile}
                                                className="h-auto p-1 text-status-critical hover:text-status-critical"
                                            >
                                                <X className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    ) : (
                                        <span className="text-sm text-muted-foreground">
                                            No file selected
                                        </span>
                                    )}
                                </div>
                                {errors.document && (
                                    <p className="text-sm text-status-critical">
                                        {errors.document}
                                    </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    Accepted format: PDF only. Maximum file
                                    size: 8MB.
                                </p>
                            </div>

                            {/* Content Summary (conditional) */}
                            {data.content_mode === 'pdf_and_summary' && (
                                <div className="space-y-2">
                                    <Label htmlFor="content_summary">
                                        Content Summary{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Textarea
                                        id="content_summary"
                                        placeholder="Brief summary of the policy content for quick reference..."
                                        rows={6}
                                        value={data.content_summary}
                                        onChange={(e) =>
                                            setData(
                                                'content_summary',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.content_summary
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.content_summary && (
                                        <p className="text-sm text-status-critical">
                                            {errors.content_summary}
                                        </p>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        This summary will be displayed alongside
                                        the PDF for quick reference.
                                    </p>
                                </div>
                            )}

                            {/* Progress Bar */}
                            {progress && (
                                <div className="space-y-2">
                                    <div className="h-2 w-full rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full bg-status-info transition-all"
                                            style={{
                                                width: `${progress.percentage}%`,
                                            }}
                                        />
                                    </div>
                                    <p className="text-center text-xs text-muted-foreground">
                                        Uploading... {progress.percentage}%
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-4">
                        <Link href="/hr/policies">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Policy'}
                        </Button>
                    </div>
                </form>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
