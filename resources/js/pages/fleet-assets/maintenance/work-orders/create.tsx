import FleetHero from '@/components/fleet-hero';
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
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowDown, ArrowUp, ClipboardCheck, Clock, ImagePlus, Loader2, Save, Search, Zap } from 'lucide-react';
import { useMemo, useState } from 'react';

type ChecklistRun = {
    id: number;
    asset_name: string;
    template_name: string;
    run_at: string | null;
};

type Props = {
    assets: Array<{ id: number; name: string; asset_tag: string | null; category: string | null }>;
    users: Array<{ id: number; name: string }>;
    checklist_runs?: ChecklistRun[];
    prefill_asset_id?: string | null;
    prefill_checklist_run_id?: string | null;
};

const PRIORITY_OPTIONS = [
    { value: 'critical', label: 'Critical', icon: Zap, color: 'border-red-600 bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400 dark:border-red-500' },
    { value: 'high', label: 'High', icon: ArrowUp, color: 'border-orange-500 bg-orange-50 text-orange-700 dark:bg-orange-900/20 dark:text-orange-400 dark:border-orange-500' },
    { value: 'medium', label: 'Medium', icon: AlertTriangle, color: 'border-yellow-500 bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400 dark:border-yellow-500' },
    { value: 'low', label: 'Low', icon: ArrowDown, color: 'border-blue-500 bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400 dark:border-blue-500' },
];

export default function WorkOrderCreate({ assets, users, checklist_runs, prefill_asset_id, prefill_checklist_run_id }: Props) {
    const safeAssets = assets ?? [];
    const safeUsers = users ?? [];
    const safeChecklistRuns = checklist_runs ?? [];

    const [assetSearch, setAssetSearch] = useState('');

    const filteredAssets = useMemo(() => {
        if (!assetSearch.trim()) return safeAssets;
        const q = assetSearch.toLowerCase();
        return safeAssets.filter(
            (a) =>
                a.name.toLowerCase().includes(q) ||
                (a.asset_tag ?? '').toLowerCase().includes(q) ||
                (a.category ?? '').toLowerCase().includes(q)
        );
    }, [safeAssets, assetSearch]);

    const form = useForm({
        asset_id: prefill_asset_id ?? '',
        title: '',
        description: '',
        priority: 'medium',
        assigned_to_user_id: '',
        due_at: '',
        estimated_cost: '',
        estimated_hours: '',
        checklist_run_id: prefill_checklist_run_id ?? '',
        notes: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/fleet-assets/maintenance/work-orders');
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Work Orders', href: '/fleet-assets/maintenance/work-orders' },
                { title: 'Create', href: '#' },
            ]}
        >
            <Head title="Create Work Order" />
            <PageShell>
                <FleetHero
                    title="Create Work Order"
                    description="Create a new maintenance work order."
                    backHref="/fleet-assets/maintenance/work-orders"
                    backLabel="Back to Work Orders"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Priority Selector - 4 colored cards */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Priority</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-4 gap-3">
                                {PRIORITY_OPTIONS.map((opt) => {
                                    const IconComp = opt.icon;
                                    return (
                                        <button
                                            key={opt.value}
                                            type="button"
                                            onClick={() => form.setData('priority', opt.value)}
                                            className={cn(
                                                "flex flex-col items-center gap-2 rounded-xl border-2 px-4 py-5 text-sm font-semibold transition-all",
                                                form.data.priority === opt.value
                                                    ? `${opt.color} shadow-md`
                                                    : 'border-transparent bg-muted text-muted-foreground hover:bg-muted/80'
                                            )}
                                        >
                                            <IconComp className="h-6 w-6" />
                                            {opt.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>

                    {/* 2-column form layout */}
                    <div className="grid gap-6 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Work Order Details</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4">
                                <div>
                                    <label className="text-sm font-medium">Asset *</label>
                                    <div className="space-y-2">
                                        <div className="relative">
                                            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                            <Input
                                                value={assetSearch}
                                                onChange={(e) => setAssetSearch(e.target.value)}
                                                placeholder="Search assets..."
                                                className="pl-8"
                                            />
                                        </div>
                                        <Select value={form.data.asset_id} onValueChange={(v) => form.setData('asset_id', v)}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select asset" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {filteredAssets.map((a) => (
                                                    <SelectItem key={a.id} value={String(a.id)}>
                                                        {a.name}
                                                        {a.asset_tag ? ` (${a.asset_tag})` : ''}
                                                        {a.category ? ` - ${a.category}` : ''}
                                                    </SelectItem>
                                                ))}
                                                {filteredAssets.length === 0 && (
                                                    <div className="px-2 py-3 text-center text-xs text-muted-foreground">
                                                        No assets match your search.
                                                    </div>
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    {form.errors.asset_id && <p className="mt-1 text-xs text-destructive">{form.errors.asset_id}</p>}
                                </div>
                                <div>
                                    <label className="text-sm font-medium">Title *</label>
                                    <Input
                                        value={form.data.title}
                                        onChange={(e) => form.setData('title', e.target.value)}
                                        placeholder="Work order title"
                                    />
                                    {form.errors.title && <p className="mt-1 text-xs text-destructive">{form.errors.title}</p>}
                                </div>
                                <div>
                                    <label className="text-sm font-medium">Description</label>
                                    <textarea
                                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        rows={3}
                                        value={form.data.description}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                        placeholder="Describe the work needed..."
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Assignment & Scheduling</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4">
                                <div>
                                    <label className="text-sm font-medium">Assigned To</label>
                                    <Select value={form.data.assigned_to_user_id} onValueChange={(v) => form.setData('assigned_to_user_id', v)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select assignee" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {safeUsers.map((u) => (
                                                <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <label className="text-sm font-medium">Due Date</label>
                                    <Input
                                        type="date"
                                        value={form.data.due_at}
                                        onChange={(e) => form.setData('due_at', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <label className="text-sm font-medium">Estimated Cost ($)</label>
                                    <Input
                                        type="number"
                                        step="0.01"
                                        value={form.data.estimated_cost}
                                        onChange={(e) => form.setData('estimated_cost', e.target.value)}
                                        placeholder="0.00"
                                    />
                                </div>
                                <div>
                                    <label className="flex items-center gap-1.5 text-sm font-medium">
                                        <Clock className="h-3.5 w-3.5" />
                                        Estimated Hours
                                    </label>
                                    <Input
                                        type="number"
                                        step="0.5"
                                        min="0"
                                        value={form.data.estimated_hours}
                                        onChange={(e) => form.setData('estimated_hours', e.target.value)}
                                        placeholder="0"
                                    />
                                    {form.errors.estimated_hours && <p className="mt-1 text-xs text-destructive">{form.errors.estimated_hours}</p>}
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Notes */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <textarea
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Additional notes..."
                            />
                        </CardContent>
                    </Card>

                    {/* Related Checklist Run */}
                    {safeChecklistRuns.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <ClipboardCheck className="h-4 w-4" />
                                    Related Failed Inspection
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="mb-3 text-xs text-muted-foreground">
                                    Link this work order to a failed checklist inspection.
                                </p>
                                <Select
                                    value={form.data.checklist_run_id}
                                    onValueChange={(v) => form.setData('checklist_run_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select failed inspection (optional)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {safeChecklistRuns.map((run) => (
                                            <SelectItem key={run.id} value={String(run.id)}>
                                                {run.template_name} - {run.asset_name}
                                                {run.run_at ? ` (${new Date(run.run_at).toLocaleDateString()})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.checklist_run_id && <p className="mt-1 text-xs text-destructive">{form.errors.checklist_run_id}</p>}
                            </CardContent>
                        </Card>
                    )}

                    {/* Attachments Placeholder */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <ImagePlus className="h-4 w-4" />
                                Attachments
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-muted-foreground/25 py-8">
                                <ImagePlus className="mb-2 h-8 w-8 text-muted-foreground/40" />
                                <p className="text-sm text-muted-foreground">
                                    Drag and drop photos or files here
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground/60">
                                    File uploads coming soon
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                            Create Work Order
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/fleet-assets/maintenance/work-orders">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
