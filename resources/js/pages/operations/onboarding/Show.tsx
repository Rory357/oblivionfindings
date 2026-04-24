import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, Circle, Clock3 } from 'lucide-react';

type Step = {
    id: number;
    step_name: string;
    status: string;
    due_date?: string | null;
    notes?: string | null;
};

type Props = {
    workflow: {
        id: number;
        status: string;
        started_at?: string | null;
        completed_at?: string | null;
        client?: { id: number; first_name: string; last_name: string } | null;
        steps: Step[];
    };
};

function formatDate(value?: string | null): string {
    if (!value) return '-';

    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function OnboardingShow({ workflow }: Props) {
    return (
        <AppLayout>
            <Head title="Onboarding Workflow" />
            <PageHeader
                title={workflow.client
                    ? `${workflow.client.first_name} ${workflow.client.last_name} Onboarding`
                    : `Onboarding Workflow #${workflow.id}`}
                description="Track checklist progress, due steps, and completion for this onboarding workflow."
                backHref="/operations/onboarding"
                actions={
                    workflow.status === 'in_progress' ? (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(`/operations/onboarding/${workflow.id}/complete`)
                            }
                        >
                            Complete Workflow
                        </Button>
                    ) : undefined
                }
            />
            <PageShell>
                <div className="mb-4 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <Badge variant="outline" className="h-5 px-2 text-[10px] capitalize">
                        {workflow.status}
                    </Badge>
                    <span>Started {formatDate(workflow.started_at)}</span>
                    <span>Completed {formatDate(workflow.completed_at)}</span>
                </div>

                <div className="space-y-2">
                    {workflow.steps.map((step) => (
                        <Card key={step.id}>
                            <CardContent className="flex items-start gap-3 p-4">
                                <div className="mt-0.5">
                                    {step.status === 'completed' ? (
                                        <CheckCircle2 className="h-5 w-5 text-status-success" />
                                    ) : (
                                        <Circle className="h-5 w-5 text-muted-foreground" />
                                    )}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <p className="text-sm font-semibold">{step.step_name}</p>
                                        <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">
                                            {step.status}
                                        </Badge>
                                    </div>
                                    <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                        <span className="inline-flex items-center gap-1">
                                            <Clock3 className="h-3 w-3" />
                                            Due {formatDate(step.due_date)}
                                        </span>
                                        {step.notes && <span>{step.notes}</span>}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </PageShell>
        </AppLayout>
    );
}
