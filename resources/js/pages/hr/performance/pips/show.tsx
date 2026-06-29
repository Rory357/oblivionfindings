import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Clock, FileText, Paperclip, XCircle } from 'lucide-react';
import { useRef, useState } from 'react';

interface Milestone {
    id: number;
    title: string;
    description: string | null;
    due_date: string;
    status: string;
    outcome: string | null;
    reviewer_notes: string | null;
    reviewed_at: string | null;
    reviewer: { id: number; name: string } | null;
    evidence_path: string | null;
}

interface Pip {
    id: number;
    title: string;
    reason: string;
    expectations: string;
    support_offered: string | null;
    consequences: string | null;
    status: string;
    start_date: string;
    end_date: string;
    review_date: string | null;
    outcome: string | null;
    outcome_notes: string | null;
    completed_at: string | null;
    employee: { id: number; name: string };
    manager: { id: number; name: string };
    creator: { id: number; name: string } | null;
    milestones: Milestone[];
}

interface Props {
    pip: Pip;
    can: { manage: boolean };
}

const statusColors: Record<string, string> = {
    active: 'bg-status-info-bg text-status-info',
    in_progress: 'bg-status-warning-bg text-status-warning',
    completed: 'bg-status-success-bg text-status-success',
    cancelled: 'bg-muted text-foreground',
};

const milestoneIcons: Record<string, React.ReactNode> = {
    pending: <Clock className="h-5 w-5 text-muted-foreground" />,
    met: <CheckCircle2 className="h-5 w-5 text-status-success" />,
    not_met: <XCircle className="h-5 w-5 text-status-critical" />,
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

export default function PipShow({ pip, can }: Props) {
    const [completing, setCompleting] = useState(false);
    const completeForm = useForm({ outcome: '', outcome_notes: '' });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Performance', href: '/hr/performance' },
        { title: 'PIPs', href: '/hr/performance/pips' },
        { title: pip.title, href: `/hr/performance/pips/${pip.id}` },
    ];

    const handleMilestoneUpdate = (milestoneId: number, status: string) => {
        router.put(
            `/hr/performance/pips/milestones/${milestoneId}`,
            { status },
            { preserveScroll: true },
        );
    };

    const handleComplete = (e: React.FormEvent) => {
        e.preventDefault();
        completeForm.post(`/hr/performance/pips/${pip.id}/complete`, {
            preserveScroll: true,
        });
    };

    const totalMilestones = pip.milestones.length;
    const metMilestones = pip.milestones.filter(
        (m) => m.status === 'met',
    ).length;
    const progressPct =
        totalMilestones > 0
            ? Math.round((metMilestones / totalMilestones) * 100)
            : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={pip.title} />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/performance/pips"
                        title={pip.title}
                        description={
                            <span className="flex items-center gap-2">
                                <span>{pip.employee?.name}</span>
                                <span>|</span>
                                <span>
                                    {formatDate(pip.start_date)} -{' '}
                                    {formatDate(pip.end_date)}
                                </span>
                                <Badge
                                    className={
                                        statusColors[pip.status] || 'bg-muted'
                                    }
                                    variant="outline"
                                >
                                    {pip.status.replace('_', ' ')}
                                </Badge>
                            </span>
                        }
                        actions={
                            can.manage && pip.status !== 'completed' ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setCompleting(!completing)}
                                >
                                    Complete PIP
                                </Button>
                            ) : undefined
                        }
                    />
                }
            >
                {/* Overview */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Plan Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div>
                                <span className="font-medium text-muted-foreground">
                                    Manager:
                                </span>{' '}
                                {pip.manager?.name ?? '-'}
                            </div>
                            <div>
                                <span className="font-medium text-muted-foreground">
                                    Review Date:
                                </span>{' '}
                                {formatDate(pip.review_date)}
                            </div>
                            <div>
                                <span className="font-medium text-muted-foreground">
                                    Reason:
                                </span>
                                <p className="mt-1 whitespace-pre-wrap text-foreground">
                                    {pip.reason}
                                </p>
                            </div>
                            <div>
                                <span className="font-medium text-muted-foreground">
                                    Expectations:
                                </span>
                                <p className="mt-1 whitespace-pre-wrap text-foreground">
                                    {pip.expectations}
                                </p>
                            </div>
                            {pip.support_offered && (
                                <div>
                                    <span className="font-medium text-muted-foreground">
                                        Support Offered:
                                    </span>
                                    <p className="mt-1 whitespace-pre-wrap text-foreground">
                                        {pip.support_offered}
                                    </p>
                                </div>
                            )}
                            {pip.consequences && (
                                <div>
                                    <span className="font-medium text-muted-foreground">
                                        Consequences:
                                    </span>
                                    <p className="mt-1 whitespace-pre-wrap text-foreground">
                                        {pip.consequences}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Progress
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-center">
                                <div className="text-3xl font-bold">
                                    {progressPct}%
                                </div>
                                <div className="text-sm text-muted-foreground">
                                    {metMilestones} of {totalMilestones}{' '}
                                    milestones met
                                </div>
                            </div>
                            <div className="h-3 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-status-success transition-all"
                                    style={{ width: `${progressPct}%` }}
                                />
                            </div>
                            {pip.outcome && (
                                <div className="mt-4 rounded-lg border p-3">
                                    <span className="text-sm font-medium text-muted-foreground">
                                        Outcome:
                                    </span>
                                    <Badge className="ml-2" variant="outline">
                                        {pip.outcome}
                                    </Badge>
                                    {pip.outcome_notes && (
                                        <p className="mt-2 text-sm text-foreground">
                                            {pip.outcome_notes}
                                        </p>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Complete PIP Form */}
                {completing && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Complete PIP
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={handleComplete}
                                className="space-y-3"
                            >
                                <div>
                                    <Label>Outcome</Label>
                                    <Select
                                        value={completeForm.data.outcome}
                                        onValueChange={(val) =>
                                            completeForm.setData('outcome', val)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select outcome..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="successful">
                                                Successful
                                            </SelectItem>
                                            <SelectItem value="unsuccessful">
                                                Unsuccessful
                                            </SelectItem>
                                            <SelectItem value="extended">
                                                Extended
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Notes</Label>
                                    <Textarea
                                        value={completeForm.data.outcome_notes}
                                        onChange={(e) =>
                                            completeForm.setData(
                                                'outcome_notes',
                                                e.target.value,
                                            )
                                        }
                                        rows={3}
                                    />
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        type="submit"
                                        disabled={completeForm.processing}
                                    >
                                        Save Outcome
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setCompleting(false)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Milestone Timeline */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Milestones</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {pip.milestones.length === 0 ? (
                            <p className="text-center text-sm text-muted-foreground">
                                No milestones defined
                            </p>
                        ) : (
                            <div className="space-y-4">
                                {pip.milestones.map((milestone, index) => (
                                    <div
                                        key={milestone.id}
                                        className="flex gap-4"
                                    >
                                        <div className="flex flex-col items-center">
                                            {milestoneIcons[milestone.status] ||
                                                milestoneIcons.pending}
                                            {index <
                                                pip.milestones.length - 1 && (
                                                <div className="mt-1 h-full w-0.5 bg-muted" />
                                            )}
                                        </div>
                                        <div className="flex-1 pb-4">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <div className="font-medium">
                                                        {milestone.title}
                                                    </div>
                                                    <div className="text-sm text-muted-foreground">
                                                        Due:{' '}
                                                        {formatDate(
                                                            milestone.due_date,
                                                        )}
                                                    </div>
                                                    {milestone.description && (
                                                        <p className="mt-1 text-sm text-muted-foreground">
                                                            {
                                                                milestone.description
                                                            }
                                                        </p>
                                                    )}
                                                    {milestone.reviewer_notes && (
                                                        <p className="mt-1 text-sm text-muted-foreground italic">
                                                            Review:{' '}
                                                            {
                                                                milestone.reviewer_notes
                                                            }
                                                            {milestone.reviewer && (
                                                                <>
                                                                    {' '}
                                                                    -{' '}
                                                                    {
                                                                        milestone
                                                                            .reviewer
                                                                            .name
                                                                    }
                                                                </>
                                                            )}
                                                        </p>
                                                    )}
                                                    <MilestoneEvidence
                                                        milestone={milestone}
                                                        canManage={
                                                            can.manage &&
                                                            pip.status !==
                                                                'completed'
                                                        }
                                                    />
                                                </div>
                                                {can.manage &&
                                                    pip.status !==
                                                        'completed' && (
                                                        <div className="flex gap-1">
                                                            {milestone.status !==
                                                                'met' && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    className="text-status-success"
                                                                    onClick={() =>
                                                                        handleMilestoneUpdate(
                                                                            milestone.id,
                                                                            'met',
                                                                        )
                                                                    }
                                                                >
                                                                    Met
                                                                </Button>
                                                            )}
                                                            {milestone.status !==
                                                                'not_met' && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    className="text-status-critical"
                                                                    onClick={() =>
                                                                        handleMilestoneUpdate(
                                                                            milestone.id,
                                                                            'not_met',
                                                                        )
                                                                    }
                                                                >
                                                                    Not Met
                                                                </Button>
                                                            )}
                                                        </div>
                                                    )}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}

function MilestoneEvidence({
    milestone,
    canManage,
}: {
    milestone: Milestone;
    canManage: boolean;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);

    const upload = (file: File) => {
        const fd = new FormData();
        fd.append('file', file);
        setUploading(true);
        router.post(
            `/hr/performance/pips/milestones/${milestone.id}/evidence`,
            fd,
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => setUploading(false),
            },
        );
    };

    return (
        <div className="mt-2 flex flex-wrap items-center gap-3 text-sm">
            {milestone.evidence_path ? (
                <a
                    href={`/hr/performance/pips/milestones/${milestone.id}/evidence`}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1.5 font-medium text-primary hover:underline"
                >
                    <FileText className="h-3.5 w-3.5" />
                    View evidence
                </a>
            ) : (
                <span className="text-muted-foreground">No evidence attached</span>
            )}
            {canManage && (
                <>
                    <button
                        type="button"
                        onClick={() => inputRef.current?.click()}
                        disabled={uploading}
                        className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-2.5 py-1 text-xs font-semibold disabled:opacity-50"
                    >
                        <Paperclip className="h-3.5 w-3.5" />
                        {uploading
                            ? 'Uploading…'
                            : milestone.evidence_path
                              ? 'Replace'
                              : 'Attach evidence'}
                    </button>
                    <input
                        ref={inputRef}
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                        className="hidden"
                        onChange={(e) => {
                            const f = e.target.files?.[0];
                            if (f) upload(f);
                            e.target.value = '';
                        }}
                    />
                </>
            )}
        </div>
    );
}
