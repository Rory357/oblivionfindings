import { PageHeader, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import AppLayout from '@/layouts/app-layout';
import { store as storeResolution } from '@/routes/governance/resolutions';
import { PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    DollarSign,
    FileText,
    Gavel,
    Plus,
    Scale,
    Trash2,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface MeetingOption {
    id: number;
    title: string;
    scheduled_at: string;
}

interface CommitteeOption {
    id: number;
    name: string;
}

interface Props extends PageProps {
    meetings: MeetingOption[];
    committees?: CommitteeOption[];
    selectedMeetingId?: number | string | null;
}

interface OptionItem {
    label: string;
    description: string;
    benefits: string;
    drawbacks: string;
}

interface ActionItem {
    title: string;
    assignee_name: string;
    due_date: string;
}

export default function CreateResolution({
    auth,
    meetings = [],
    committees = [],
    selectedMeetingId,
}: Props) {
    const [step, setStep] = useState<number>(1);
    const [hasFinancialCost, setHasFinancialCost] = useState<boolean>(false);
    const [costAmount, setCostAmount] = useState<string>('');
    const [costCurrency, setCostCurrency] = useState<string>('NZD');
    const [costSource, setCostSource] = useState<string>('');

    const { data, setData, transform, post, processing, errors } = useForm({
        title: '',
        exact_motion: '',
        purpose: 'decision',
        decision_type: 'strategic',
        context: '',
        options: [
            {
                label: 'Option 1: Proposed action',
                description: '',
                benefits: '',
                drawbacks: '',
            },
            {
                label: 'Option 2: Status quo / Alternative',
                description: '',
                benefits: '',
                drawbacks: '',
            },
        ] as OptionItem[],
        single_option_reason: '',
        recommendation: '',
        service_user_implications: '',
        risk_equity_implications: '',
        type: 'ordinary',
        voting_deadline: '',
        meeting_id: selectedMeetingId ? String(selectedMeetingId) : 'none',
        board_committee_id: 'none',
        follow_up_actions: [] as ActionItem[],
        publish_now: false,
    });

    const addOption = () => {
        setData('options', [
            ...data.options,
            {
                label: `Option ${data.options.length + 1}`,
                description: '',
                benefits: '',
                drawbacks: '',
            },
        ]);
    };

    const removeOption = (index: number) => {
        if (data.options.length <= 1) return;
        setData(
            'options',
            data.options.filter((_, idx) => idx !== index)
        );
    };

    const updateOption = (index: number, field: keyof OptionItem, value: string) => {
        const updated = [...data.options];
        updated[index] = { ...updated[index], [field]: value };
        setData('options', updated);
    };

    const addAction = () => {
        setData('follow_up_actions', [
            ...data.follow_up_actions,
            { title: '', assignee_name: '', due_date: '' },
        ]);
    };

    const removeAction = (index: number) => {
        setData(
            'follow_up_actions',
            data.follow_up_actions.filter((_, idx) => idx !== index)
        );
    };

    const updateAction = (index: number, field: keyof ActionItem, value: string) => {
        const updated = [...data.follow_up_actions];
        updated[index] = { ...updated[index], [field]: value };
        setData('follow_up_actions', updated);
    };

    // Publication readiness check (client side)
    const publicationValidationErrors = useMemo(() => {
        const missing: string[] = [];
        if (!data.title.trim()) missing.push('Title is required.');
        if (!data.exact_motion.trim()) missing.push('Exact motion wording is required.');
        if (!data.context.trim()) missing.push('Background context / rationale is required.');

        if (data.purpose === 'decision') {
            const validOptions = data.options.filter((o) => o.label.trim().length > 0);
            if (validOptions.length < 2 && !data.single_option_reason.trim()) {
                missing.push(
                    'At least 2 evaluated options are required (or specify why only one option is presented).'
                );
            }
            if (!data.recommendation.trim()) {
                missing.push('Management recommendation and rationale is required.');
            }
            if (hasFinancialCost && !costAmount.trim()) {
                missing.push('Financial cost amount must be specified.');
            }
            if (!data.service_user_implications.trim()) {
                missing.push('Service-user & safety implications must be addressed.');
            }
            if (!data.risk_equity_implications.trim()) {
                missing.push('Risk & equity implications must be addressed.');
            }
        }
        return missing;
    }, [data, hasFinancialCost, costAmount]);

    const isPublishReady = publicationValidationErrors.length === 0;

    const handleSubmit = (publishNow: boolean) => {
        const costImpact = hasFinancialCost
            ? {
                  has_cost: true,
                  amount: costAmount,
                  currency: costCurrency,
                  budget_source: costSource,
              }
            : {
                  has_cost: false,
                  note: 'Explicitly confirmed: No direct financial implications.',
              };

        transform((current) => ({
            ...current,
            meeting_id: current.meeting_id === 'none' ? null : current.meeting_id,
            board_committee_id:
                current.board_committee_id === 'none' ? null : current.board_committee_id,
            cost_impact: costImpact,
            publish_now: publishNow,
        }));

        post(storeResolution.url());
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Resolutions', href: '/governance/resolutions' },
                { title: 'Author Decision Paper', href: '/governance/resolutions/create' },
            ]}
        >
            <Head title="Author Decision Paper" />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/resolutions"
                        icon={Gavel}
                        title="Author Board Paper & Resolution"
                        subline="Structured decision paper authoring ensuring informed, accountable governance."
                    />
                }
            >
                {/* Wizard Step Progress */}
                <div className="mb-8">
                    <div className="grid grid-cols-5 gap-2 border-b pb-4">
                        {[
                            { stepNum: 1, label: 'Motion & Purpose', icon: FileText },
                            { stepNum: 2, label: 'Options & Recommendation', icon: Scale },
                            { stepNum: 3, label: 'Implications & Evidence', icon: Users },
                            { stepNum: 4, label: 'Voting & Actions', icon: Gavel },
                            { stepNum: 5, label: 'Review & Publish', icon: CheckCircle2 },
                        ].map((item) => {
                            const Icon = item.icon;
                            const isActive = step === item.stepNum;
                            const isCompleted = step > item.stepNum;

                            return (
                                <button
                                    key={item.stepNum}
                                    type="button"
                                    onClick={() => setStep(item.stepNum)}
                                    className={`flex items-center gap-2 rounded-lg p-2 text-left transition-all ${
                                        isActive
                                            ? 'border border-primary bg-primary/10 font-medium text-primary'
                                            : isCompleted
                                              ? 'text-status-success hover:bg-muted'
                                              : 'text-muted-foreground hover:bg-muted'
                                    }`}
                                >
                                    <Icon className="h-4 w-4 shrink-0" />
                                    <span className="hidden text-xs sm:inline">
                                        {item.stepNum}. {item.label}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* Step 1: Motion & Purpose */}
                {step === 1 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>1. Exact Motion & Governance Purpose</CardTitle>
                            <CardDescription>
                                Clearly articulate what the board is being asked to resolve and why now.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label htmlFor="title">Paper / Resolution Title *</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    placeholder="e.g., Approval of Regional Service Expansion & Capital Allocation"
                                />
                                {errors.title && (
                                    <p className="mt-1 text-sm text-status-critical">{errors.title}</p>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="purpose">Paper Purpose *</Label>
                                    <Select
                                        value={data.purpose}
                                        onValueChange={(v) => setData('purpose', v)}
                                    >
                                        <SelectTrigger id="purpose">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="decision">For Decision (Formal vote required)</SelectItem>
                                            <SelectItem value="discussion">For Discussion (Strategic steering)</SelectItem>
                                            <SelectItem value="information">For Information / Noting only</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <Label htmlFor="decision_type">Classification</Label>
                                    <Select
                                        value={data.decision_type}
                                        onValueChange={(v) => setData('decision_type', v)}
                                    >
                                        <SelectTrigger id="decision_type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="strategic">Strategic Direction</SelectItem>
                                            <SelectItem value="financial">Financial / Budgetary</SelectItem>
                                            <SelectItem value="policy">Policy & Compliance</SelectItem>
                                            <SelectItem value="operational">Operational Risk & Safety</SelectItem>
                                            <SelectItem value="statutory">Statutory / Regulatory</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="exact_motion">Exact Motion (The Proposal) *</Label>
                                <Textarea
                                    id="exact_motion"
                                    value={data.exact_motion}
                                    onChange={(e) => setData('exact_motion', e.target.value)}
                                    placeholder="That the Board resolves to: (1) Approve... (2) Authorise the CEO to execute..."
                                    rows={3}
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    The precise wording that board members will vote on.
                                </p>
                                {errors.exact_motion && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.exact_motion}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="context">Context & Background (Why now?) *</Label>
                                <Textarea
                                    id="context"
                                    value={data.context}
                                    onChange={(e) => setData('context', e.target.value)}
                                    placeholder="Provide background, historical context, drivers for change, and why this requires board determination."
                                    rows={5}
                                />
                                {errors.context && (
                                    <p className="mt-1 text-sm text-status-critical">{errors.context}</p>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="meeting_id">Link to Scheduled Meeting</Label>
                                    <Select
                                        value={data.meeting_id}
                                        onValueChange={(v) => setData('meeting_id', v)}
                                    >
                                        <SelectTrigger id="meeting_id">
                                            <SelectValue placeholder="Select meeting" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">Standalone paper (no meeting)</SelectItem>
                                            {meetings.map((meeting) => (
                                                <SelectItem key={meeting.id} value={String(meeting.id)}>
                                                    {meeting.title} (
                                                    {new Date(meeting.scheduled_at).toLocaleDateString()})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <Label htmlFor="committee_id">Board Committee (Optional)</Label>
                                    <Select
                                        value={data.board_committee_id}
                                        onValueChange={(v) => setData('board_committee_id', v)}
                                    >
                                        <SelectTrigger id="committee_id">
                                            <SelectValue placeholder="Full Board" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">Full Board of Directors</SelectItem>
                                            {committees.map((committee) => (
                                                <SelectItem key={committee.id} value={String(committee.id)}>
                                                    {committee.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Step 2: Options & Recommendation */}
                {step === 2 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>2. Alternatives Evaluated & Management Recommendation</CardTitle>
                            <CardDescription>
                                Consequential decisions must present substantive alternatives with benefits and drawbacks, or explain why only one path exists.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <h4 className="font-semibold text-sm">Options Evaluated</h4>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addOption}
                                        className="gap-1"
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        Add Option
                                    </Button>
                                </div>

                                {data.options.map((option, index) => (
                                    <div
                                        key={index}
                                        className="rounded-lg border p-4 space-y-3 bg-card"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <Input
                                                value={option.label}
                                                onChange={(e) =>
                                                    updateOption(index, 'label', e.target.value)
                                                }
                                                placeholder={`Option ${index + 1} Name`}
                                                className="font-medium"
                                            />
                                            {data.options.length > 1 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() => removeOption(index)}
                                                    className="text-status-critical hover:bg-status-critical-bg"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            )}
                                        </div>

                                        <Textarea
                                            value={option.description}
                                            onChange={(e) =>
                                                updateOption(index, 'description', e.target.value)
                                            }
                                            placeholder="Description of this option / course of action..."
                                            rows={2}
                                        />

                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label className="text-xs text-status-success">
                                                    Key Benefits & Opportunities
                                                </Label>
                                                <Textarea
                                                    value={option.benefits}
                                                    onChange={(e) =>
                                                        updateOption(index, 'benefits', e.target.value)
                                                    }
                                                    placeholder="Pros, cost efficiencies, strategic gains..."
                                                    rows={2}
                                                />
                                            </div>
                                            <div>
                                                <Label className="text-xs text-status-critical">
                                                    Key Drawbacks, Costs & Risks
                                                </Label>
                                                <Textarea
                                                    value={option.drawbacks}
                                                    onChange={(e) =>
                                                        updateOption(index, 'drawbacks', e.target.value)
                                                    }
                                                    placeholder="Cons, implementation risks, dependencies..."
                                                    rows={2}
                                                />
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {data.options.length < 2 && (
                                <div className="rounded-lg border border-status-warning/40 bg-status-warning-bg p-4 space-y-2">
                                    <div className="flex items-center gap-2 text-status-warning font-medium text-sm">
                                        <AlertCircle className="h-4 w-4" />
                                        Single Option Justification Required
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Good governance mandates evaluating alternatives. If only one option is presented, document why no alternatives were feasible.
                                    </p>
                                    <Textarea
                                        value={data.single_option_reason}
                                        onChange={(e) =>
                                            setData('single_option_reason', e.target.value)
                                        }
                                        placeholder="Explain why only a single option is feasible (e.g., sole regulatory compliance mandate, urgent breach mitigation)..."
                                        rows={2}
                                    />
                                </div>
                            )}

                            <div>
                                <Label htmlFor="recommendation">
                                    Management Recommendation & Rationale *
                                </Label>
                                <Textarea
                                    id="recommendation"
                                    value={data.recommendation}
                                    onChange={(e) => setData('recommendation', e.target.value)}
                                    placeholder="Explicitly state which option is recommended and the strategic or operational rationale supporting it."
                                    rows={3}
                                />
                                {errors.recommendation && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.recommendation}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Step 3: Implications & Evidence */}
                {step === 3 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>3. Implications & Impact Assessment</CardTitle>
                            <CardDescription>
                                Consequential decision papers must honestly account for financial, safety, service-user, and risk implications.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Financial Implications */}
                            <div className="rounded-lg border p-4 space-y-4">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2 font-medium text-sm">
                                        <DollarSign className="h-4 w-4 text-primary" />
                                        Financial Cost & Budget Impact
                                    </div>
                                    <div className="flex items-center gap-4 text-sm">
                                        <label className="flex items-center gap-1.5 cursor-pointer">
                                            <input
                                                type="radio"
                                                name="financial_cost_toggle"
                                                checked={!hasFinancialCost}
                                                onChange={() => setHasFinancialCost(false)}
                                            />
                                            <span>No Financial Cost</span>
                                        </label>
                                        <label className="flex items-center gap-1.5 cursor-pointer">
                                            <input
                                                type="radio"
                                                name="financial_cost_toggle"
                                                checked={hasFinancialCost}
                                                onChange={() => setHasFinancialCost(true)}
                                            />
                                            <span>Has Budget Impact</span>
                                        </label>
                                    </div>
                                </div>

                                {hasFinancialCost ? (
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3 pt-2 border-t">
                                        <div>
                                            <Label htmlFor="costAmount">Estimated Amount *</Label>
                                            <Input
                                                id="costAmount"
                                                value={costAmount}
                                                onChange={(e) => setCostAmount(e.target.value)}
                                                placeholder="e.g., 85,000"
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="costCurrency">Currency</Label>
                                            <Select
                                                value={costCurrency}
                                                onValueChange={setCostCurrency}
                                            >
                                                <SelectTrigger id="costCurrency">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="NZD">NZD ($)</SelectItem>
                                                    <SelectItem value="AUD">AUD ($)</SelectItem>
                                                    <SelectItem value="USD">USD ($)</SelectItem>
                                                    <SelectItem value="GBP">GBP (£)</SelectItem>
                                                    <SelectItem value="EUR">EUR (€)</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div>
                                            <Label htmlFor="costSource">Budget Source / Fund</Label>
                                            <Input
                                                id="costSource"
                                                value={costSource}
                                                onChange={(e) => setCostSource(e.target.value)}
                                                placeholder="e.g., OPEX FY26 Regional Services"
                                            />
                                        </div>
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted-foreground pt-1">
                                        Explicitly confirmed: This resolution carries no direct capital or operating expenditure.
                                    </p>
                                )}
                            </div>

                            {/* Service-User & Safety Implications */}
                            <div>
                                <Label htmlFor="service_user_implications">
                                    Service-User & Safety Implications *
                                </Label>
                                <Textarea
                                    id="service_user_implications"
                                    value={data.service_user_implications}
                                    onChange={(e) =>
                                        setData('service_user_implications', e.target.value)
                                    }
                                    placeholder="Explain direct and indirect impacts on patients, clients, service users, and front-line safety."
                                    rows={3}
                                />
                                {errors.service_user_implications && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.service_user_implications}
                                    </p>
                                )}
                            </div>

                            {/* Risk & Equity Implications */}
                            <div>
                                <Label htmlFor="risk_equity_implications">
                                    Risk Assessment & Equity Implications *
                                </Label>
                                <Textarea
                                    id="risk_equity_implications"
                                    value={data.risk_equity_implications}
                                    onChange={(e) =>
                                        setData('risk_equity_implications', e.target.value)
                                    }
                                    placeholder="Detail key strategic/operational risks, equity/diversity impacts, and mitigating controls."
                                    rows={3}
                                />
                                {errors.risk_equity_implications && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.risk_equity_implications}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Step 4: Voting & Follow-up */}
                {step === 4 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>4. Voting Parameters & Implementation Commitments</CardTitle>
                            <CardDescription>
                                Set voting thresholds and define accountable follow-through commitments.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="threshold">Voting Threshold</Label>
                                    <Select
                                        value={data.type}
                                        onValueChange={(v) => setData('type', v)}
                                    >
                                        <SelectTrigger id="threshold">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="ordinary">
                                                Ordinary Resolution (Simple Majority &gt; 50%)
                                            </SelectItem>
                                            <SelectItem value="special">
                                                Special Resolution (Two-Thirds Supermajority 66.7%)
                                            </SelectItem>
                                            <SelectItem value="unanimous">
                                                Unanimous Resolution (100% of participating votes)
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <Label htmlFor="voting_deadline">Voting Deadline (Optional)</Label>
                                    <Input
                                        id="voting_deadline"
                                        type="datetime-local"
                                        value={data.voting_deadline}
                                        onChange={(e) => setData('voting_deadline', e.target.value)}
                                    />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Leave blank if voting will be concluded during the scheduled meeting.
                                    </p>
                                </div>
                            </div>

                            {/* Accountable Follow-Up Actions */}
                            <div className="space-y-3 pt-4 border-t">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <h4 className="font-semibold text-sm">Accountable Follow-Through Actions</h4>
                                        <p className="text-xs text-muted-foreground">
                                            Decisions carry binding obligations. Assign owners and target dates upon adoption.
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addAction}
                                        className="gap-1"
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        Add Action
                                    </Button>
                                </div>

                                {data.follow_up_actions.length === 0 ? (
                                    <div className="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground">
                                        No implementation actions defined yet. Click &quot;Add Action&quot; to assign implementation responsibilities.
                                    </div>
                                ) : (
                                    data.follow_up_actions.map((action, index) => (
                                        <div
                                            key={index}
                                            className="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-12 items-center"
                                        >
                                            <div className="sm:col-span-6">
                                                <Input
                                                    placeholder="Action item description..."
                                                    value={action.title}
                                                    onChange={(e) =>
                                                        updateAction(index, 'title', e.target.value)
                                                    }
                                                />
                                            </div>
                                            <div className="sm:col-span-3">
                                                <Input
                                                    placeholder="Responsible person / title"
                                                    value={action.assignee_name}
                                                    onChange={(e) =>
                                                        updateAction(
                                                            index,
                                                            'assignee_name',
                                                            e.target.value
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div className="sm:col-span-2">
                                                <Input
                                                    type="date"
                                                    value={action.due_date}
                                                    onChange={(e) =>
                                                        updateAction(index, 'due_date', e.target.value)
                                                    }
                                                />
                                            </div>
                                            <div className="sm:col-span-1 flex justify-end">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() => removeAction(index)}
                                                    className="text-status-critical"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Step 5: Review & Actions */}
                {step === 5 && (
                    <div className="space-y-6">
                        {/* Publication Readiness Status Banner */}
                        <div
                            className={`rounded-lg border p-4 ${
                                isPublishReady
                                    ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                    : 'border-status-warning/40 bg-status-warning-bg text-status-warning'
                            }`}
                        >
                            <div className="flex items-start gap-3">
                                {isPublishReady ? (
                                    <CheckCircle2 className="h-5 w-5 shrink-0" />
                                ) : (
                                    <AlertCircle className="h-5 w-5 shrink-0" />
                                )}
                                <div>
                                    <h4 className="font-semibold text-sm">
                                        {isPublishReady
                                            ? 'Paper is Publication-Ready'
                                            : 'Paper is Incomplete (Draft mode only)'}
                                    </h4>
                                    <p className="text-xs text-foreground/80 mt-1">
                                        {isPublishReady
                                            ? 'All required governance paper criteria have been met. You may save as Draft or immediately Publish & Open for Voting.'
                                            : 'You can save this paper as a draft at any time. Before opening voting, the following required sections must be completed:'}
                                    </p>
                                    {!isPublishReady && (
                                        <ul className="mt-2 list-disc list-inside text-xs space-y-1 text-status-critical font-medium">
                                            {publicationValidationErrors.map((err, i) => (
                                                <li key={i}>{err}</li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Paper Preview Card */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <Badge variant="outline" className="mb-2 uppercase">
                                            {data.purpose} Paper
                                        </Badge>
                                        <CardTitle>{data.title || 'Untitled Decision Paper'}</CardTitle>
                                    </div>
                                    <Badge>v1 Draft</Badge>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div>
                                    <h5 className="font-semibold text-xs text-muted-foreground uppercase tracking-wider">
                                        Exact Motion
                                    </h5>
                                    <blockquote className="mt-1.5 border-l-4 border-primary pl-4 italic text-sm text-foreground bg-primary/5 py-2 rounded-r">
                                        {data.exact_motion || 'No motion wording provided.'}
                                    </blockquote>
                                </div>

                                <div>
                                    <h5 className="font-semibold text-xs text-muted-foreground uppercase tracking-wider">
                                        Context & Background
                                    </h5>
                                    <p className="mt-1 text-sm whitespace-pre-wrap">
                                        {data.context || 'No background context provided.'}
                                    </p>
                                </div>

                                <div>
                                    <h5 className="font-semibold text-xs text-muted-foreground uppercase tracking-wider">
                                        Options Evaluated ({data.options.length})
                                    </h5>
                                    <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        {data.options.map((opt, idx) => (
                                            <div key={idx} className="rounded border p-3 text-xs space-y-1.5">
                                                <p className="font-semibold text-foreground">{opt.label}</p>
                                                {opt.description && (
                                                    <p className="text-muted-foreground">{opt.description}</p>
                                                )}
                                                {opt.benefits && (
                                                    <p className="text-status-success">
                                                        <span className="font-medium">Benefits:</span> {opt.benefits}
                                                    </p>
                                                )}
                                                {opt.drawbacks && (
                                                    <p className="text-status-critical">
                                                        <span className="font-medium">Drawbacks:</span> {opt.drawbacks}
                                                    </p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                {data.recommendation && (
                                    <div className="rounded-lg border border-status-info/30 bg-status-info-bg p-3 text-xs space-y-1">
                                        <p className="font-semibold text-status-info">
                                            Management Recommendation & Rationale
                                        </p>
                                        <p className="text-foreground">{data.recommendation}</p>
                                    </div>
                                )}

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 text-xs">
                                    <div className="rounded border p-3">
                                        <p className="font-semibold text-muted-foreground">Service-User & Safety</p>
                                        <p className="mt-1 text-foreground">
                                            {data.service_user_implications || 'Not specified'}
                                        </p>
                                    </div>
                                    <div className="rounded border p-3">
                                        <p className="font-semibold text-muted-foreground">Risk & Equity</p>
                                        <p className="mt-1 text-foreground">
                                            {data.risk_equity_implications || 'Not specified'}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Navigation and Action Bar */}
                <div className="mt-6 flex flex-wrap items-center justify-between gap-3 pt-4 border-t">
                    <div className="flex items-center gap-2">
                        {step > 1 && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setStep(step - 1)}
                                className="gap-1"
                            >
                                <ChevronLeft className="h-4 w-4" />
                                Previous
                            </Button>
                        )}
                        {step < 5 && (
                            <Button
                                type="button"
                                onClick={() => setStep(step + 1)}
                                className="gap-1"
                            >
                                Next Step
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => handleSubmit(false)}
                            disabled={processing}
                        >
                            Save Draft
                        </Button>

                        <Button
                            type="button"
                            onClick={() => handleSubmit(true)}
                            disabled={processing || !isPublishReady}
                            className="bg-status-success hover:bg-status-success/90 text-white"
                        >
                            Publish & Open for Voting
                        </Button>
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}

