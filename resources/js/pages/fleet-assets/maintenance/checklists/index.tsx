import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { HeroActionButton } from '@/pages/fleet-assets/maintenance/components/hero-action-button';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    CheckCircle,
    ClipboardCheck,
    ClipboardList,
    Loader2,
    Plus,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { formatDate } from '@/lib/fleet-utils';


type Template = {
    id: number;
    name: string;
    type: string;
    is_active: boolean;
    items: Array<{ label: string; type: string; options?: string[] | null; required?: boolean }> | null;
    runs_count: number;
    created_at: string | null;
};

type ChecklistRun = {
    id: number;
    template: { id: number; name: string } | null;
    asset: { id: number; name: string; asset_tag: string | null } | null;
    user: { id: number; name: string } | null;
    passed: boolean;
    responses: Record<string, any> | null;
    completed_at: string | null;
    created_at: string | null;
};

type Props = {
    templates: Template[];
    recent_runs: ChecklistRun[];
    stats?: {
        templates: number;
        runs_30d: number;
        failed_30d: number;
    };
    can: {
        manage: boolean;
    };
};

export default function ChecklistsIndex({ templates, recent_runs, stats, can }: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const heroStats = stats ?? {
        templates: (templates ?? []).length,
        runs_30d: 0,
        failed_30d: 0,
    };
    const templateForm = useForm({
        name: '',
        items: [{ label: '', type: 'checkbox', options: null, required: true }] as Array<{ label: string; type: string; options: string[] | null; required: boolean }>,
    });

    const handleCreateTemplate = (e: React.FormEvent) => {
        e.preventDefault();
        templateForm.post('/fleet-assets/maintenance/checklists', {
            onSuccess: () => {
                templateForm.reset();
                setDialogOpen(false);
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Checklists', href: '/fleet-assets/maintenance/checklists' },
            ]}
        >
            <Head title="Checklists" />
            <PageShell>
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={ClipboardList} />
                        <div className="min-w-0">
                            <HeroStatusPill>
                                Maintenance · checklists
                            </HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Checklists
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Inspection and maintenance checklist templates and runs.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-3 gap-2 lg:ml-auto lg:max-w-xl">
                            <HeroClusterTile
                                label="Templates"
                                value={fmt(heroStats.templates)}
                                caption="ready to run"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                label="Runs 30d"
                                value={fmt(heroStats.runs_30d)}
                                caption="checks completed"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                label="Failed 30d"
                                value={fmt(heroStats.failed_30d)}
                                caption="need follow-up"
                                tone={heroStats.failed_30d > 0 ? 'critical' : 'success'}
                            />
                        </div>
                    </div>
                    {can.manage ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <FleetHeroAction
                                href="/fleet-assets/maintenance/checklists/run"
                                icon={ClipboardCheck}
                                emphasis
                            >
                                Run checklist
                            </FleetHeroAction>
                            <HeroActionButton
                                onClick={() => setDialogOpen(true)}
                                icon={Plus}
                            >
                                Create template
                            </HeroActionButton>
                        </div>
                    ) : null}
                </HeroShell>

                {can.manage ? (
                    <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                        <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Create Checklist Template</DialogTitle>
                                    </DialogHeader>
                                    <form onSubmit={handleCreateTemplate} className="grid gap-4">
                                        <div>
                                            <label className="text-sm font-medium">Name *</label>
                                            <Input
                                                value={templateForm.data.name}
                                                onChange={(e) => templateForm.setData('name', e.target.value)}
                                                placeholder="Template name"
                                            />
                                        </div>
                                        <div>
                                            <label className="text-sm font-medium">Items</label>
                                            {(templateForm.data.items ?? []).map((item, idx) => (
                                                <div key={idx} className="mt-2 flex items-center gap-2">
                                                    <Input
                                                        value={item.label}
                                                        onChange={(e) => {
                                                            const items = [...templateForm.data.items];
                                                            items[idx] = { ...items[idx], label: e.target.value };
                                                            templateForm.setData('items', items);
                                                        }}
                                                        placeholder="Item label"
                                                        className="flex-1"
                                                    />
                                                    <Select
                                                        value={item.type}
                                                        onValueChange={(v) => {
                                                            const items = [...templateForm.data.items];
                                                            items[idx] = { ...items[idx], type: v };
                                                            templateForm.setData('items', items);
                                                        }}
                                                    >
                                                        <SelectTrigger className="w-28"><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="checkbox">Checkbox</SelectItem>
                                                            <SelectItem value="text">Text</SelectItem>
                                                            <SelectItem value="number">Number</SelectItem>
                                                            <SelectItem value="select">Select</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            ))}
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="mt-2"
                                                onClick={() => templateForm.setData('items', [...templateForm.data.items, { label: '', type: 'checkbox', options: null, required: true }])}
                                            >
                                                <Plus className="mr-1 h-3 w-3" /> Add Item
                                            </Button>
                                        </div>
                                        {templateForm.errors.name && <p className="mt-1 text-xs text-destructive">{templateForm.errors.name}</p>}
                                        <Button type="submit" disabled={templateForm.processing}>
                                            {templateForm.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                                            Create Template
                                        </Button>
                                    </form>
                        </DialogContent>
                    </Dialog>
                ) : null}

                {/* Templates */}
                <Card>
                    <CardHeader>
                        <CardTitle>Templates</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {(templates ?? []).length > 0 ? (
                            <div className="space-y-2">
                                {templates.map((template) => (
                                    <div key={template.id} className="flex items-center justify-between rounded-md border p-3 text-sm">
                                        <div className="flex items-center gap-3">
                                            <ClipboardList className="h-5 w-5 text-muted-foreground" />
                                            <div>
                                                <div className="font-medium">{template.name}</div>
                                                <div className="flex gap-2 mt-1">
                                                    <Badge variant="outline">{template.type}</Badge>
                                                    <Badge variant={template.is_active ? 'default' : 'secondary'}>
                                                        {template.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="text-right text-xs text-muted-foreground">
                                            <div>{(template.items ?? []).length} items</div>
                                            <div>{template.runs_count ?? 0} runs</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-8 text-center">
                                <ClipboardList className="h-12 w-12 text-muted-foreground/50 mb-4" />
                                <h3 className="text-lg font-semibold">No checklist templates</h3>
                                <p className="text-sm text-muted-foreground mt-1 max-w-sm">
                                    Create a template for vehicle inspections or maintenance checks.
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Recent Runs */}
                <Card>
                    <CardHeader>
                        <CardTitle>Recent Runs</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {(recent_runs ?? []).length > 0 ? (
                            <div className="space-y-2">
                                {recent_runs.map((run) => (
                                    <div key={run.id} className="flex items-center justify-between rounded-md border p-3 text-sm">
                                        <div className="flex items-center gap-3">
                                            {run.passed ? (
                                                <CheckCircle className="h-5 w-5 text-status-success" />
                                            ) : (
                                                <XCircle className="h-5 w-5 text-status-critical" />
                                            )}
                                            <div>
                                                <div className="font-medium">{run.template?.name ?? 'Unknown Template'}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {run.asset ? (
                                                        <Link href={`/fleet-assets/assets/${run.asset.id}`} className="text-primary hover:underline">
                                                            {run.asset.name}
                                                        </Link>
                                                    ) : (
                                                        <span>Unknown Asset</span>
                                                    )}
                                                    {' '}&middot; {run.user?.name ?? 'Unknown'} &middot; {run.completed_at ? formatDate(run.completed_at) : '---'}
                                                </div>
                                            </div>
                                        </div>
                                        <Badge variant={run.passed ? 'default' : 'destructive'}>
                                            {run.passed ? 'Passed' : 'Failed'}
                                        </Badge>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">No checklist runs recorded.</p>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
