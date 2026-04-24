import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import {
    ClipboardCheck,
    Camera,
    AlertTriangle,
    Save,
    CheckCircle2,
    ArrowLeft,
    ChevronDown,
    ChevronUp,
} from 'lucide-react';
import { useState, useMemo } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

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
    failure_creates_hazard?: boolean;
};

type Response = {
    id?: number;
    template_item_id: number;
    response_value: string;
    notes: string;
    photo_path?: string;
    is_failed: boolean;
    create_hazard?: boolean;
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
    const [signatureName, setSignatureName] = useState('');
    const [signatureConfirmed, setSignatureConfirmed] = useState(false);
    const [expandedNotes, setExpandedNotes] = useState<Set<number>>(new Set());

    const requiredItems = useMemo(() => {
        return items.filter(item => item.is_required);
    }, [items]);

    const completedCount = useMemo(() => {
        return requiredItems.filter(item => {
            const resp = currentResponses[item.id];
            return resp?.response_value !== undefined && resp.response_value !== '';
        }).length;
    }, [requiredItems, currentResponses]);

    const progressPercentage = useMemo(() => {
        if (requiredItems.length === 0) {
            return 0;
        }
        return Math.round((completedCount / requiredItems.length) * 100);
    }, [requiredItems, completedCount]);

    const failedItems = useMemo(() => {
        return items.filter(item => currentResponses[item.id]?.is_failed);
    }, [items, currentResponses]);

    const allRequiredAnswered = useMemo(() => {
        return requiredItems.every(item => {
            const resp = currentResponses[item.id];
            return resp?.response_value !== undefined && resp.response_value !== '';
        });
    }, [requiredItems, currentResponses]);

    const canComplete = allRequiredAnswered && signatureConfirmed && signatureName.trim() !== '';

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

    const toggleNotesExpanded = (itemId: number) => {
        const newSet = new Set(expandedNotes);
        if (newSet.has(itemId)) {
            newSet.delete(itemId);
        } else {
            newSet.add(itemId);
        }
        setExpandedNotes(newSet);
    };

    const form = useForm({
        responses: [] as Response[],
        overall_notes: '',
        signature_name: '',
    });

    const handleSave = () => {
        form.transform(() => ({
            responses: Object.values(currentResponses),
            overall_notes: overallNotes,
            signature_name: signatureName,
        }));
        form.post(`/checklists/runs/${run.id}/responses`, {
            preserveScroll: true,
        });
    };

    const handleComplete = () => {
        form.transform(() => ({
            responses: Object.values(currentResponses),
            overall_notes: overallNotes,
            signature_name: signatureName,
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
                                className={opt === 'fail' && value === opt ? 'bg-status-critical hover:bg-status-critical' : opt === 'pass' && value === opt ? 'bg-status-success hover:bg-status-success' : ''}
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
                    <div className="border-2 border-dashed border-border/30 rounded-lg p-6 text-center hover:border-border/50 transition">
                        <div className="flex flex-col items-center gap-2">
                            {value ? (
                                <>
                                    <Camera className="w-8 h-8 text-status-success" />
                                    <span className="text-sm text-status-success">Photo uploaded</span>
                                </>
                            ) : (
                                <>
                                    <Camera className="w-8 h-8 text-muted-foreground" />
                                    <span className="text-sm text-muted-foreground">Click to upload photo</span>
                                </>
                            )}
                            <input
                                type="file"
                                accept="image/*"
                                onChange={(e) => {
                                    const file = e.target.files?.[0];
                                    if (file) {
                                        updateResponse(item.id, { response_value: 'photo_uploaded' });
                                    }
                                }}
                                className="hidden"
                            />
                        </div>
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
                        <p className="text-sm text-muted-foreground">{site.name}</p>
                    </div>
                </div>

                {/* Progress Indicator */}
                <Card>
                    <CardContent className="p-4">
                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium">Progress</span>
                                <span className="text-sm font-bold text-status-info">{completedCount} of {requiredItems.length} items ({progressPercentage}%)</span>
                            </div>
                            <Progress value={progressPercentage} className="h-2" />
                        </div>
                    </CardContent>
                </Card>

                {/* Failed Items Warning */}
                {failedItems.length > 0 && (
                    <Card className="border-status-critical/30 bg-status-critical">
                        <CardContent className="flex items-center gap-3 py-4">
                            <AlertTriangle className="w-6 h-6 text-status-critical flex-shrink-0" />
                            <div>
                                <div className="font-medium text-status-critical">
                                    {failedItems.length} item(s) marked as failed
                                </div>
                                <div className="text-sm text-muted-foreground">
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
                        const isNotesExpanded = expandedNotes.has(item.id);

                        return (
                            <Card
                                key={item.id}
                                className={isFailed ? 'border-status-critical/30 bg-status-critical' : ''}
                            >
                                <CardContent className="p-4">
                                    <div className="flex items-start gap-3">
                                        <div className="flex-shrink-0 w-6 h-6 rounded-full bg-muted flex items-center justify-center text-sm font-medium text-muted-foreground">
                                            {index + 1}
                                        </div>
                                        <div className="flex-1 space-y-3">
                                            {/* Question Header */}
                                            <div>
                                                <div className="font-medium flex items-center gap-2">
                                                    <span>{item.question}</span>
                                                    {item.is_required && (
                                                        <span className="text-status-critical text-sm">*</span>
                                                    )}
                                                    {isFailed && (
                                                        <Badge variant="destructive" className="text-xs">Failed</Badge>
                                                    )}
                                                </div>
                                                {item.guidance && (
                                                    <div className="text-sm text-muted-foreground mt-1">
                                                        {item.guidance}
                                                    </div>
                                                )}
                                            </div>

                                            {/* Response Input */}
                                            {getResponseInput(item)}

                                            {/* Notes Collapsible */}
                                            <Collapsible>
                                                <CollapsibleTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-auto p-0 text-muted-foreground hover:text-foreground"
                                                    >
                                                        {isNotesExpanded ? (
                                                            <ChevronUp className="w-4 h-4 mr-1" />
                                                        ) : (
                                                            <ChevronDown className="w-4 h-4 mr-1" />
                                                        )}
                                                        Notes {response?.notes ? '(added)' : '(optional)'}
                                                    </Button>
                                                </CollapsibleTrigger>
                                                <CollapsibleContent className="mt-2">
                                                    <Textarea
                                                        placeholder="Add any notes for this item..."
                                                        value={response?.notes || ''}
                                                        onChange={(e) => updateResponse(item.id, { notes: e.target.value })}
                                                        rows={2}
                                                        className="text-sm"
                                                    />
                                                </CollapsibleContent>
                                            </Collapsible>

                                            {/* Create Hazard Option */}
                                            {isFailed && item.failure_creates_hazard && (
                                                <div className="flex items-center gap-2 p-2 rounded border border-status-warning/30 bg-status-warning">
                                                    <Checkbox
                                                        id={`hazard-${item.id}`}
                                                        checked={response?.create_hazard || false}
                                                        onCheckedChange={(checked) =>
                                                            updateResponse(item.id, { create_hazard: !!checked })
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={`hazard-${item.id}`}
                                                        className="flex-1 cursor-pointer text-sm text-status-warning"
                                                    >
                                                        Create hazard for this failure
                                                    </Label>
                                                </div>
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
                            placeholder="Add any overall observations or notes about the checklist..."
                            rows={4}
                        />
                    </CardContent>
                </Card>

                {/* Signature Section */}
                <Card className="border-border">
                    <CardHeader>
                        <CardTitle className="text-sm">Completion Confirmation</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div>
                            <Label htmlFor="signature-name" className="mb-2 block">
                                Signature / Name *
                            </Label>
                            <Input
                                id="signature-name"
                                type="text"
                                value={signatureName}
                                onChange={(e) => setSignatureName(e.target.value)}
                                placeholder="Enter your name or signature"
                                className="font-medium"
                            />
                        </div>
                        <div className="flex items-center gap-3 p-3 rounded border border-border bg-muted">
                            <Checkbox
                                id="confirm-accuracy"
                                checked={signatureConfirmed}
                                onCheckedChange={(checked) => setSignatureConfirmed(!!checked)}
                            />
                            <Label
                                htmlFor="confirm-accuracy"
                                className="flex-1 cursor-pointer text-sm"
                            >
                                I confirm this checklist has been completed accurately and honestly
                            </Label>
                        </div>
                    </CardContent>
                </Card>

                {/* Actions */}
                <div className="flex justify-between gap-2 pt-4">
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
                        disabled={form.processing || !canComplete}
                        className={!canComplete ? 'opacity-50 cursor-not-allowed' : ''}
                    >
                        <CheckCircle2 className="w-4 h-4 mr-1" />
                        Complete Checklist
                    </Button>
                </div>

                {/* Completion Requirements */}
                {!canComplete && (
                    <Card className="border-status-warning/30 bg-status-warning">
                        <CardContent className="p-3">
                            <div className="text-sm text-status-warning">
                                <p className="font-medium mb-2">To complete this checklist:</p>
                                <ul className="space-y-1 text-xs ml-4 list-disc">
                                    {!allRequiredAnswered && (
                                        <li>Answer all {requiredItems.length} required items ({completedCount} completed)</li>
                                    )}
                                    {!signatureName.trim() && (
                                        <li>Enter your name or signature</li>
                                    )}
                                    {!signatureConfirmed && (
                                        <li>Confirm the completion accuracy</li>
                                    )}
                                </ul>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
