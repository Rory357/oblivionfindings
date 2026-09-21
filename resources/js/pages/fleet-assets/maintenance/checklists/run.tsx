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
import { ConfiguredQuestions, applicableAnswers, type ConfiguredAnswer, type ConfiguredQuestion } from '@/components/fleet-assets/maintenance/configured-questions';
import { FleetCompactHero } from '@/pages/fleet-assets/components/fleet-compact-hero';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ClipboardList, Save } from 'lucide-react';
import { useState } from 'react';

type ChecklistItem = {
    id?: string;
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
    assets: Array<{ id: number; name: string; approved_template_id: number | null; rule_version_id: number | null;
        policy_questions: ConfiguredQuestion[] | null }>;
    work_orders: Array<{ id: number; asset_id: number; reference_number: string | null; title: string;
        attachments: Array<{ id: number; original_name: string }> }>;
    selected_template_id?: number | null;
    selected_asset_id?: number | null;
    selected_work_order_id?: number | null;
    can: {
        manage: boolean;
    };
};

export default function ChecklistRun({ templates, assets, work_orders, selected_template_id, selected_asset_id, selected_work_order_id, can }: Props) {
    const [selectedTemplateId, setSelectedTemplateId] = useState(selected_template_id ? String(selected_template_id) : '');
    const [selectedWorkId, setSelectedWorkId] = useState(selected_work_order_id ? String(selected_work_order_id) : '');
    const [configuredAnswers, setConfiguredAnswers] = useState<Record<string, ConfiguredAnswer>>({});
    const [configuredFiles, setConfiguredFiles] = useState<Record<string, File | null>>({});
    const [configuredKey, setConfiguredKey] = useState(crypto.randomUUID());
    const [configuredBusy, setConfiguredBusy] = useState(false);
    const [configuredErrors, setConfiguredErrors] = useState<Record<string, string>>({});
    const selectedTemplate = (templates ?? []).find(
        (t) => String(t.id) === selectedTemplateId,
    );

    const form = useForm<{
        asset_id: string;
        results: Record<string, string | boolean>;
        notes: string;
        request_key: string;
        rule_version_id: string;
    }>({
        asset_id: selected_asset_id ? String(selected_asset_id) : '',
        results: {},
        notes: '',
        request_key: crypto.randomUUID(),
        rule_version_id: String(assets.find((asset) => asset.id === selected_asset_id)?.rule_version_id ?? ''),
    });
    const selectedAsset = assets.find((asset) => String(asset.id) === form.data.asset_id);
    const configured = Boolean(selectedAsset?.rule_version_id && String(selectedAsset.approved_template_id) === selectedTemplateId
        && selectedAsset.policy_questions?.length);
    const selectedWork = work_orders.find((order) => String(order.id) === selectedWorkId && String(order.asset_id) === form.data.asset_id);

    const handleTemplateChange = (templateId: string) => {
        setSelectedTemplateId(templateId);
        setConfiguredAnswers({}); setConfiguredFiles({}); setConfiguredKey(crypto.randomUUID());
        const asset = assets.find((item) => String(item.id) === form.data.asset_id);
        form.setData((data) => ({ ...data, results: {},
            rule_version_id: String(asset?.approved_template_id) === templateId ? String(asset?.rule_version_id ?? '') : '' }));
    };

    const handleResponseChange = (
        itemIndex: string,
        value: string | boolean,
    ) => {
        form.setData('results', {
            ...form.data.results,
            [itemIndex]: value,
        });
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedTemplateId) return;
        if (configured) {
            if (!selectedAsset?.policy_questions) return;
            const answers = applicableAnswers(configuredAnswers, selectedAsset.policy_questions);
            const files: Record<string, File> = {};
            for (const [id, file] of Object.entries(configuredFiles)) if (file && answers[id]) files[id] = file;
            setConfiguredBusy(true); setConfiguredErrors({});
            router.post(`/fleet-assets/maintenance/checklists/${selectedTemplateId}/run`, {
                asset_id: selectedAsset.id, work_order_id: selectedWork?.id ?? null,
                rule_version_id: selectedAsset.rule_version_id, results: answers, files, request_key: configuredKey,
            }, { preserveScroll: true, forceFormData: true,
                onError: (errors) => setConfiguredErrors(errors), onFinish: () => setConfiguredBusy(false) });
            return;
        }
        form.post(
            `/fleet-assets/maintenance/checklists/${selectedTemplateId}/run`,
        );
    };
    if (!can.manage) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Fleet & Assets', href: '/fleet-assets' },
                    {
                        title: 'Checklists',
                        href: '/fleet-assets/maintenance/checklists',
                    },
                    { title: 'Run', href: '#' },
                ]}
            >
                <Head title="Run Checklist" />
                <PageShell>
                    <FleetCompactHero
                        pill="Maintenance checklist · view only"
                        title="Run Checklist"
                        backHref="/fleet-assets/maintenance/checklists"
                        backLabel="Checklists"
                    />
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                View-only
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                Running checklists requires fleet maintenance
                                manager access.
                            </p>
                        </CardContent>
                    </Card>
                </PageShell>
            </AppLayout>
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                {
                    title: 'Checklists',
                    href: '/fleet-assets/maintenance/checklists',
                },
                { title: 'Run', href: '#' },
            ]}
        >
            <Head title="Run Checklist" />
            <PageShell>
                <FleetCompactHero
                    pill="Maintenance checklist · new run"
                    title="Run Checklist"
                    backHref="/fleet-assets/maintenance/checklists"
                    backLabel="Checklists"
                />
                <p className="text-sm text-muted-foreground">
                    Complete a checklist inspection or maintenance run.
                </p>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Select Template & Asset</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="text-sm font-medium">
                                    Template *
                                </label>
                                <Select
                                    value={selectedTemplateId}
                                    onValueChange={handleTemplateChange}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select template" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(templates ?? []).map((t) => (
                                            <SelectItem
                                                key={t.id}
                                                value={String(t.id)}
                                            >
                                                {t.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <label className="text-sm font-medium">
                                    Asset *
                                </label>
                                <Select
                                    value={form.data.asset_id}
                                    onValueChange={(v) => {
                                        const asset = assets.find((item) => String(item.id) === v);
                                        setSelectedWorkId(''); setConfiguredAnswers({}); setConfiguredFiles({}); setConfiguredKey(crypto.randomUUID());
                                        form.setData((data) => ({ ...data, asset_id: v,
                                            rule_version_id: String(asset?.approved_template_id) === selectedTemplateId ? String(asset?.rule_version_id ?? '') : '' }));
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select asset" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(assets ?? []).map((a) => (
                                            <SelectItem
                                                key={a.id}
                                                value={String(a.id)}
                                            >
                                                {a.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>

                    {configured && selectedTemplate && <Card><CardHeader><CardTitle>Approved check and private evidence</CardTitle></CardHeader>
                        <CardContent className="space-y-4"><p className="text-sm text-muted-foreground">Record this check against the asset. Linking an existing work order is optional. A problem can be reported afterwards without changing this original check.</p>
                            <Select value={selectedWorkId || 'standalone'} onValueChange={(value) => { setSelectedWorkId(value === 'standalone' ? '' : value); setConfiguredAnswers({}); setConfiguredFiles({}); setConfiguredKey(crypto.randomUUID()); }}>
                                <SelectTrigger aria-label="Related work order (optional)"><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value="standalone">Standalone asset check</SelectItem>{work_orders.filter((order) => String(order.asset_id) === form.data.asset_id).map((order) =>
                                    <SelectItem key={order.id} value={String(order.id)}>{order.reference_number ?? `WO-${order.id}`} · {order.title}</SelectItem>)}</SelectContent>
                            </Select>
                            <ConfiguredQuestions items={selectedTemplate.items ?? []} questions={selectedAsset?.policy_questions ?? []}
                                answers={configuredAnswers} attachments={selectedWork?.attachments ?? []} onChange={setConfiguredAnswers}
                                stagedFiles={configuredFiles} onStage={(id, file) => setConfiguredFiles((current) => ({ ...current, [id]: file }))} busy={configuredBusy} />
                            {Object.keys(configuredErrors).length > 0 && <p role="alert" className="text-sm text-destructive">{Object.values(configuredErrors).join(' ')}</p>}
                        </CardContent></Card>}

                    {/* Checklist Items */}
                    {selectedTemplate && !configured && (
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
                                        {(selectedTemplate.items ?? []).map(
                                            (item, idx) => (
                                                <div
                                                    key={idx}
                                                    className="rounded-md border p-3"
                                                >
                                                    <label className="flex items-start gap-3">
                                                        {item.type ===
                                                        'checkbox' ? (
                                                            <>
                                                                <input
                                                                    type="checkbox"
                                                                    checked={
                                                                        !!form
                                                                            .data
                                                                            .results[
                                                                            String(item.id ?? idx)
                                                                        ]
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        handleResponseChange(
                                                                            String(item.id ?? idx),
                                                                            e
                                                                                .target
                                                                                .checked,
                                                                        )
                                                                    }
                                                                    className="mt-0.5 rounded border-border"
                                                                />
                                                                <div>
                                                                    <span className="text-sm font-medium">
                                                                        {
                                                                            item.label
                                                                        }
                                                                        {item.required && (
                                                                            <span className="text-destructive">
                                                                                {' '}
                                                                                *
                                                                            </span>
                                                                        )}
                                                                    </span>
                                                                </div>
                                                            </>
                                                        ) : item.type ===
                                                          'number' ? (
                                                            <div className="flex-1">
                                                                <span className="text-sm font-medium">
                                                                    {item.label}
                                                                    {item.required && (
                                                                        <span className="text-destructive">
                                                                            {' '}
                                                                            *
                                                                        </span>
                                                                    )}
                                                                </span>
                                                                <Input
                                                                    type="number"
                                                                    className="mt-1"
                                                                    value={String(
                                                                        form
                                                                            .data
                                                                            .results[
                                                                            String(item.id ?? idx)
                                                                        ] ?? '',
                                                                    )}
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        handleResponseChange(
                                                                            String(item.id ?? idx),
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    placeholder="Enter value..."
                                                                />
                                                            </div>
                                                        ) : item.type ===
                                                          'select' ? (
                                                            <div className="flex-1">
                                                                <span className="text-sm font-medium">
                                                                    {item.label}
                                                                    {item.required && (
                                                                        <span className="text-destructive">
                                                                            {' '}
                                                                            *
                                                                        </span>
                                                                    )}
                                                                </span>
                                                                <Select
                                                                    value={String(
                                                                        form
                                                                            .data
                                                                            .results[
                                                                            String(item.id ?? idx)
                                                                        ] ?? '',
                                                                    )}
                                                                    onValueChange={(
                                                                        v,
                                                                    ) =>
                                                                        handleResponseChange(
                                                                            String(item.id ?? idx),
                                                                            v,
                                                                        )
                                                                    }
                                                                >
                                                                    <SelectTrigger className="mt-1">
                                                                        <SelectValue placeholder="Select..." />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        {(
                                                                            item.options ??
                                                                            []
                                                                        ).map(
                                                                            (
                                                                                opt,
                                                                            ) => (
                                                                                <SelectItem
                                                                                    key={
                                                                                        opt
                                                                                    }
                                                                                    value={
                                                                                        opt
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        opt
                                                                                    }
                                                                                </SelectItem>
                                                                            ),
                                                                        )}
                                                                    </SelectContent>
                                                                </Select>
                                                            </div>
                                                        ) : (
                                                            <div className="flex-1">
                                                                <span className="text-sm font-medium">
                                                                    {item.label}
                                                                    {item.required && (
                                                                        <span className="text-destructive">
                                                                            {' '}
                                                                            *
                                                                        </span>
                                                                    )}
                                                                </span>
                                                                <Input
                                                                    className="mt-1"
                                                                    value={String(
                                                                        form
                                                                            .data
                                                                            .results[
                                                                            String(item.id ?? idx)
                                                                        ] ?? '',
                                                                    )}
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        handleResponseChange(
                                                                            String(item.id ?? idx),
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    placeholder="Enter response..."
                                                                />
                                                            </div>
                                                        )}
                                                    </label>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No items in this template.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {selectedTemplate && (
                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={form.processing || configuredBusy || !selectedAsset}>
                                <Save className="mr-2 h-4 w-4" />
                                Submit Checklist
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/fleet-assets/maintenance/checklists">
                                    Cancel
                                </Link>
                            </Button>
                        </div>
                    )}
                </form>
            </PageShell>
        </AppLayout>
    );
}
