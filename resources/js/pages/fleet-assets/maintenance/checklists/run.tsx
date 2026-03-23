import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    CheckCircle,
    ClipboardList,
    Save,
} from 'lucide-react';
import { useState } from 'react';

type ChecklistItem = {
    label: string;
    type: 'checkbox' | 'text' | 'number' | 'select';
    options?: string[] | null;
    required: boolean;
};

type Template = {
    id: number;
    name: string;
    type: string;
    items: ChecklistItem[] | null;
};

type Props = {
    templates: Template[];
    assets: Array<{ id: number; name: string }>;
};

export default function ChecklistRun({ templates, assets }: Props) {
    const [selectedTemplateId, setSelectedTemplateId] = useState('');
    const selectedTemplate = (templates ?? []).find((t) => String(t.id) === selectedTemplateId);

    const form = useForm<{
        asset_id: string;
        results: Record<string, string | boolean>;
        notes: string;
    }>({
        asset_id: '',
        results: {},
        notes: '',
    });

    const handleTemplateChange = (templateId: string) => {
        setSelectedTemplateId(templateId);
        form.setData('results', {});
    };

    const handleResponseChange = (itemIndex: number, value: string | boolean) => {
        form.setData('results', {
            ...form.data.results,
            [String(itemIndex)]: value,
        });
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedTemplateId) return;
        form.post(`/fleet-assets/maintenance/checklists/${selectedTemplateId}/run`);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Checklists', href: '/fleet-assets/maintenance/checklists' },
                { title: 'Run', href: '#' },
            ]}
        >
            <Head title="Run Checklist" />
            <PageShell>
                <PageHeader
                    title="Run Checklist"
                    description="Complete a checklist inspection or maintenance run."
                    backHref="/fleet-assets/maintenance/checklists"
                    backLabel="Back to Checklists"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Select Template & Asset</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="text-sm font-medium">Template *</label>
                                <Select value={selectedTemplateId} onValueChange={handleTemplateChange}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select template" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(templates ?? []).map((t) => (
                                            <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <label className="text-sm font-medium">Asset *</label>
                                <Select value={form.data.asset_id} onValueChange={(v) => form.setData('asset_id', v)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select asset" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(assets ?? []).map((a) => (
                                            <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Checklist Items */}
                    {selectedTemplate && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <ClipboardList className="h-4 w-4" />
                                    {selectedTemplate.name}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {(selectedTemplate.items ?? []).length > 0 ? (
                                    <div className="space-y-4">
                                        {(selectedTemplate.items ?? []).map((item, idx) => (
                                            <div key={idx} className="rounded-md border p-3">
                                                <label className="flex items-start gap-3">
                                                    {item.type === 'checkbox' ? (
                                                        <>
                                                            <input
                                                                type="checkbox"
                                                                checked={!!form.data.results[String(idx)]}
                                                                onChange={(e) => handleResponseChange(idx, e.target.checked)}
                                                                className="mt-0.5 rounded border-gray-300"
                                                            />
                                                            <div>
                                                                <span className="text-sm font-medium">
                                                                    {item.label}
                                                                    {item.required && <span className="text-destructive"> *</span>}
                                                                </span>
                                                            </div>
                                                        </>
                                                    ) : item.type === 'number' ? (
                                                        <div className="flex-1">
                                                            <span className="text-sm font-medium">
                                                                {item.label}
                                                                {item.required && <span className="text-destructive"> *</span>}
                                                            </span>
                                                            <Input
                                                                type="number"
                                                                className="mt-1"
                                                                value={String(form.data.results[String(idx)] ?? '')}
                                                                onChange={(e) => handleResponseChange(idx, e.target.value)}
                                                                placeholder="Enter value..."
                                                            />
                                                        </div>
                                                    ) : item.type === 'select' ? (
                                                        <div className="flex-1">
                                                            <span className="text-sm font-medium">
                                                                {item.label}
                                                                {item.required && <span className="text-destructive"> *</span>}
                                                            </span>
                                                            <Select
                                                                value={String(form.data.results[String(idx)] ?? '')}
                                                                onValueChange={(v) => handleResponseChange(idx, v)}
                                                            >
                                                                <SelectTrigger className="mt-1"><SelectValue placeholder="Select..." /></SelectTrigger>
                                                                <SelectContent>
                                                                    {(item.options ?? []).map((opt) => (
                                                                        <SelectItem key={opt} value={opt}>{opt}</SelectItem>
                                                                    ))}
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                    ) : (
                                                        <div className="flex-1">
                                                            <span className="text-sm font-medium">
                                                                {item.label}
                                                                {item.required && <span className="text-destructive"> *</span>}
                                                            </span>
                                                            <Input
                                                                className="mt-1"
                                                                value={String(form.data.results[String(idx)] ?? '')}
                                                                onChange={(e) => handleResponseChange(idx, e.target.value)}
                                                                placeholder="Enter response..."
                                                            />
                                                        </div>
                                                    )}
                                                </label>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">No items in this template.</p>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {selectedTemplate && (
                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={form.processing}>
                                <Save className="mr-2 h-4 w-4" />
                                Submit Checklist
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/fleet-assets/maintenance/checklists">Cancel</Link>
                            </Button>
                        </div>
                    )}
                </form>
            </PageShell>
        </AppLayout>
    );
}
