import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import {
    ClipboardCheck,
    Camera,
    AlertTriangle,
    Save,
    CheckCircle2,
    ArrowLeft,
} from 'lucide-react';
import { useState, useMemo } from 'react';

type Run = {
    id: number;
    scheduled_date: string;
    status: 'scheduled' | 'in_progress' | 'completed';
    completion_percentage: number;
};

type Template = {
    id: number;
    name: string;
};

type Site = {
    id: number;
    name: string;
};

type Item = {
    id: number;
    question: string;
    response_type: 'yes_no' | 'yes_no_na' | 'pass_fail' | 'numeric' | 'text' | 'photo';
    response_config?: { min?: number; max?: number };
    is_required: boolean;
    guidance?: string;
    failure_creates_hazard?: string;
};

type Response = {
    id?: number;
    template_item_id: number;
    response_value: string;
    notes: string;
    photo_path?: string;
    is_failed: boolean;
};

type Props = {
    site: Site;
    template: Template;
    run: Run;
    items: Item[];
    responses: Response[];
};

export default function ChecklistRun({ site, template, run, items, responses }: Props) {
    const [currentResponses, setCurrentResponses] = useState<Record<number, Response>>(
        () => {
            const map: Record<number, Response> = {};
            responses.forEach(r => {
                map[r.template_item_id] = r;
            });
            return map;
        }
    );
    const [overallNotes, setOverallNotes] = useState('');

    const progress = useMemo(() => {
        if (items.length === 0) {
            return 0;
        }

        const completed = items.filter(item => {
            const resp = currentResponses[item.id];
            return resp?.response_value !== undefined && resp.response_value !== '';
        }).length;
        return Math.round((completed / items.length) * 100);
    }, [items, currentResponses]);

    const failedItems = useMemo(() => {
        return items.filter(item => currentResponses[item.id]?.is_failed);
    }, [items, currentResponses]);

    const updateResponse = (itemId: number, updates: Partial<Response>) => {
        setCurrentResponses(prev => ({
            ...prev,
            [itemId]: {
                ...prev[itemId],
                template_item_id: itemId,
                ...updates,
            },
        }));
    };

    const form = useForm({
        responses: [] as Response[],
        overall_notes: '',
    });

    const handleSave = () => {
        form.transform(() => ({
            responses: Object.values(currentResponses),
        }));
        form.post(`/checklists/runs/${run.id}/responses`, {
            preserveScroll: true,
        });
    };

    const handleComplete = () => {
        form.transform(() => ({
            overall_notes: overallNotes,
        }));
        form.post(`/checklists/runs/${run.id}/complete`);
    };

    const getResponseInput = (item: Item) => {
        const response = currentResponses[item.id];
        const value = response?.response_value || '';

        switch (item.response_type) {
            case 'yes_no':
                return (
                    <div className="flex gap-2">
                        {['yes', 'no'].map(opt => (
                            <Button
                                key={opt}
                                type="button"
                                variant={value === opt ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => updateResponse(item.id, {
                                    response_value: opt,
                                    is_failed: opt === 'no',
                                })}
                            >
                                {opt === 'yes' ? 'Yes' : 'No'}
                            </Button>
                        ))}
                    </div>
                );

            case 'yes_no_na':
                return (
                    <div className="flex gap-2">
                        {['yes', 'no', 'na'].map(opt => (
                            <Button
                                key={opt}
                                type="button"
                                variant={value === opt ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => updateResponse(item.id, {
                                    response_value: opt,
                                    is_failed: opt === 'no',
                                })}
                            >
                                {opt === 'yes' ? 'Yes' : opt === 'no' ? 'No' : 'N/A'}
                            </Button>
                        ))}
                    </div>
                );

            case 'pass_fail':
                return (
                    <div className="flex gap-2">
                        {['pass', 'fail'].map(opt => (
                            <Button
                                key={opt}
                                type="button"
                                variant={value === opt ? 'default' : 'outline'}
                                size="sm"
                                className={opt === 'fail' && value === opt ? 'bg-red-500 hover:bg-red-600' : ''}
                                onClick={() => updateResponse(item.id, {
                                    response_value: opt,
                                    is_failed: opt === 'fail',
                                })}
                            >
                                {opt === 'pass' ? 'Pass' : 'Fail'}
                            </Button>
                        ))}
                    </div>
                );

            case 'numeric':
                return (
                    <Input
                        type="number"
                        value={value}
                        min={item.response_config?.min}
                        max={item.response_config?.max}
                        onChange={(e) => updateResponse(item.id, { response_value: e.target.value })}
                        className="w-32"
                        placeholder={item.response_config ? `${item.response_config.min}-${item.response_config.max}` : ''}
                    />
                );

            case 'text':
                return (
                    <Textarea
                        value={value}
                        onChange={(e) => updateResponse(item.id, { response_value: e.target.value })}
                        placeholder="Enter response..."
                        rows={2}
                    />
                );

            case 'photo':
                return (
                    <div className="flex items-center gap-2">
                        <Input
                            type="file"
                            accept="image/*"
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                if (file) {
                                    updateResponse(item.id, { response_value: 'photo_uploaded' });
                                }
                            }}
                        />
                        <Camera className="w-5 h-5 text-slate-400" />
                    </div>
                );

            default:
                return null;
        }
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Sites', href: '/sites' },
            { title: site.name, href: `/sites/${site.id}` },
            { title: 'Checklists', href: `/sites/${site.id}/checklists` },
            { title: 'Run', href: '#' },
        ]}>
            <Head title={`${template.name} - Checklist Run`} />

            <div className="m-4 max-w-4xl mx-auto space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <Button asChild variant="ghost" size="sm" className="mb-2">
                            <Link href={`/sites/${site.id}/checklists`}>
                                <ArrowLeft className="w-4 h-4 mr-1" />
                                Back
                            </Link>
                        </Button>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <ClipboardCheck className="w-5 h-5" />
                            {template.name}
                        </h1>
                        <p className="text-sm text-slate-400">{site.name}</p>
                    </div>
                    <div className="text-right">
                        <div className="text-sm text-slate-400">Progress</div>
                        <div className="text-2xl font-bold">{progress}%</div>
                    </div>
                </div>

                {/* Progress Bar */}
                <Progress value={progress} className="h-2" />

                {/* Failed Items Warning */}
                {failedItems.length > 0 && (
                    <Card className="border-red-500/30 bg-red-500/5">
                        <CardContent className="flex items-center gap-3 py-4">
                            <AlertTriangle className="w-6 h-6 text-red-400" />
                            <div>
                                <div className="font-medium text-red-400">
                                    {failedItems.length} item(s) failed
                                </div>
                                <div className="text-sm text-slate-400">
                                    Failed items may require hazard creation
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Checklist Items */}
                <div className="space-y-3">
                    {items.map((item, index) => {
                        const response = currentResponses[item.id];
                        const isFailed = response?.is_failed;

                        return (
                            <Card
                                key={item.id}
                                className={isFailed ? 'border-red-500/30' : ''}
                            >
                                <CardContent className="p-4">
                                    <div className="flex items-start gap-3">
                                        <div className="flex-shrink-0 w-6 h-6 rounded-full bg-slate-800 flex items-center justify-center text-sm text-slate-400">
                                            {index + 1}
                                        </div>
                                        <div className="flex-1 space-y-3">
                                            <div>
                                                <div className="font-medium">
                                                    {item.question}
                                                    {item.is_required && (
                                                        <span className="text-red-400 ml-1">*</span>
                                                    )}
                                                </div>
                                                {item.guidance && (
                                                    <div className="text-sm text-slate-400 mt-1">
                                                        {item.guidance}
                                                    </div>
                                                )}
                                            </div>

                                            {getResponseInput(item)}

                                            <Textarea
                                                placeholder="Notes (optional)"
                                                value={response?.notes || ''}
                                                onChange={(e) => updateResponse(item.id, { notes: e.target.value })}
                                                rows={2}
                                                className="text-sm"
                                            />

                                            {isFailed && item.failure_creates_hazard && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="border-red-500/30 text-red-400"
                                                >
                                                    <AlertTriangle className="w-4 h-4 mr-1" />
                                                    Create Hazard
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Overall Notes */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">Overall Notes</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Textarea
                            value={overallNotes}
                            onChange={(e) => setOverallNotes(e.target.value)}
                            placeholder="Add any overall observations or notes..."
                            rows={4}
                        />
                    </CardContent>
                </Card>

                {/* Actions */}
                <div className="flex justify-between pt-4">
                    <Button
                        variant="outline"
                        onClick={handleSave}
                        disabled={form.processing}
                    >
                        <Save className="w-4 h-4 mr-1" />
                        Save Draft
                    </Button>
                    <Button
                        onClick={handleComplete}
                        disabled={form.processing || progress < 100}
                    >
                        <CheckCircle2 className="w-4 h-4 mr-1" />
                        Complete Checklist
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
