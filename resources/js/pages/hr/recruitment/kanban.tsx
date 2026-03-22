import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Clock, List, LayoutGrid } from 'lucide-react';

type KanbanCard = {
    id: number;
    name: string;
    position: string;
    days_in_stage: number;
    source: string;
    created_at: string;
};

type Props = {
    columns: Record<string, KanbanCard[]>;
    stages: string[];
    can: { manage: boolean };
};

const stageLabels: Record<string, string> = {
    new: 'New',
    screening: 'Screening',
    interview_scheduled: 'Interview Scheduled',
    interview_completed: 'Interview Completed',
    reference_check: 'Reference Check',
    offer_pending: 'Offer Pending',
    offer_sent: 'Offer Sent',
    offer_accepted: 'Offer Accepted',
    hired: 'Hired',
    withdrawn: 'Withdrawn',
    rejected: 'Rejected',
};

const stageColors: Record<string, string> = {
    new: 'bg-blue-500/10 border-blue-500/30',
    screening: 'bg-indigo-500/10 border-indigo-500/30',
    interview_scheduled: 'bg-amber-500/10 border-amber-500/30',
    interview_completed: 'bg-orange-500/10 border-orange-500/30',
    reference_check: 'bg-purple-500/10 border-purple-500/30',
    offer_pending: 'bg-cyan-500/10 border-cyan-500/30',
    offer_sent: 'bg-teal-500/10 border-teal-500/30',
    offer_accepted: 'bg-emerald-500/10 border-emerald-500/30',
    hired: 'bg-green-500/10 border-green-500/30',
    withdrawn: 'bg-slate-500/10 border-slate-500/30',
    rejected: 'bg-red-500/10 border-red-500/30',
};

export default function RecruitmentKanban({ columns, stages }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
                { title: 'Kanban', href: '/hr/recruitment/kanban' },
            ]}
        >
            <Head title="Recruitment Kanban" />
            <PageShell>
                <PageHeader
                    title="Recruitment Pipeline"
                    description="Kanban view of your candidate pipeline."
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/hr/recruitment">
                                    <List className="mr-2 h-4 w-4" /> List View
                                </Link>
                            </Button>
                            <Button variant="secondary" size="sm" disabled>
                                <LayoutGrid className="mr-2 h-4 w-4" /> Kanban
                            </Button>
                        </div>
                    }
                />

                <div className="flex gap-4 overflow-x-auto pb-4">
                    {stages.map((stage) => {
                        const cards = columns[stage] ?? [];
                        return (
                            <div key={stage} className="flex-shrink-0 w-72">
                                <div className={`rounded-t-lg border-t-2 px-3 py-2 ${stageColors[stage] ?? 'bg-muted/50 border-muted'}`}>
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-semibold">{stageLabels[stage] ?? stage}</span>
                                        <Badge variant="secondary" className="text-xs">{cards.length}</Badge>
                                    </div>
                                </div>
                                <div className="space-y-2 rounded-b-lg border border-t-0 bg-muted/20 p-2 min-h-[200px]">
                                    {cards.length === 0 ? (
                                        <div className="text-center text-xs text-muted-foreground py-8">No candidates</div>
                                    ) : (
                                        cards.map((card) => (
                                            <Link key={card.id} href={`/hr/recruitment/candidates/${card.id}`} className="block">
                                                <Card className="hover:bg-muted/50 transition-colors cursor-pointer">
                                                    <CardContent className="p-3">
                                                        <div className="font-medium text-sm">{card.name}</div>
                                                        <div className="text-xs text-muted-foreground mt-1">{card.position}</div>
                                                        <div className="flex items-center justify-between mt-2">
                                                            <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                                                <Clock className="h-3 w-3" />
                                                                {card.days_in_stage}d in stage
                                                            </div>
                                                            <Badge variant="outline" className="text-xs">{card.source}</Badge>
                                                        </div>
                                                    </CardContent>
                                                </Card>
                                            </Link>
                                        ))
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </PageShell>
        </AppLayout>
    );
}
