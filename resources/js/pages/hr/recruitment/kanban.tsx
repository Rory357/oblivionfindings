import { RecruitmentTabs } from '@/components/hr';
import PageShell from '@/components/page-shell';
import { CandidateCard } from '@/components/recruitment/candidate-card';
import {
    stageColors,
    stageLabels,
} from '@/components/recruitment/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { LayoutGrid, Plus, Search } from 'lucide-react';
import { useState } from 'react';

type KanbanCard = {
    id: number;
    name: string;
    email?: string;
    position: string;
    job_posting_title?: string | null;
    days_in_stage: number;
    source: string;
    created_at: string;
};

type Props = {
    columns: Record<string, KanbanCard[]>;
    stages: string[];
    can: { manage: boolean };
};

export default function RecruitmentKanban({ columns, stages, can }: Props) {
    const [search, setSearch] = useState('');

    const filteredColumns = Object.fromEntries(
        Object.entries(columns).map(([stage, cards]) => [
            stage,
            search
                ? cards.filter(
                      (c) =>
                          c.name.toLowerCase().includes(search.toLowerCase()) ||
                          c.position
                              .toLowerCase()
                              .includes(search.toLowerCase()),
                  )
                : cards,
        ]),
    );

    const totalCandidates = Object.values(columns).reduce(
        (sum, cards) => sum + cards.length,
        0,
    );

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
                <PageHero category="hr"
                    icon={LayoutGrid}
                    title="Recruitment Pipeline"
                    description={`Kanban view - ${totalCandidates} candidates across ${stages.length} stages`}
                    stats={[
                        { label: 'Candidates', value: totalCandidates },
                        { label: 'Stages', value: stages.length },
                    ]}
                    actions={
                        can.manage ? (
                            <Button size="sm" asChild>
                                <Link href="/hr/recruitment/candidates/create">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add candidate
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <RecruitmentTabs active="board" />

                {/* Search */}
                <div className="relative max-w-sm">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        placeholder="Filter candidates..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="pl-9"
                    />
                </div>

                {/* Kanban Board */}
                <div className="flex gap-4 overflow-x-auto pb-4">
                    {stages.map((stage) => {
                        const cards = filteredColumns[stage] ?? [];
                        const avgDays =
                            cards.length > 0
                                ? Math.round(
                                      cards.reduce(
                                          (sum, c) => sum + c.days_in_stage,
                                          0,
                                      ) / cards.length,
                                  )
                                : 0;
                        return (
                            <div key={stage} className="w-72 flex-shrink-0">
                                <div
                                    className={`rounded-t-lg border-t-2 px-3 py-2.5 ${stageColors[stage] ?? 'border-muted bg-muted/50'}`}
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-semibold">
                                            {stageLabels[stage] ?? stage}
                                        </span>
                                        <div className="flex items-center gap-1.5">
                                            <Badge
                                                variant="secondary"
                                                className="h-5 text-xs"
                                            >
                                                {cards.length}
                                            </Badge>
                                            {avgDays > 0 && (
                                                <Badge
                                                    variant="outline"
                                                    className={`h-5 text-[10px] ${avgDays > 14 ? 'border-status-critical/30 text-status-critical' : avgDays > 7 ? 'border-status-warning/30 text-status-warning' : 'text-muted-foreground'}`}
                                                >
                                                    ~{avgDays}d
                                                </Badge>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <div className="min-h-[250px] space-y-2 rounded-b-lg border border-t-0 bg-muted/10 p-2">
                                    {cards.length === 0 ? (
                                        <div className="py-12 text-center text-xs text-muted-foreground">
                                            No candidates
                                        </div>
                                    ) : (
                                        cards.map((card) => (
                                            <CandidateCard
                                                key={card.id}
                                                id={card.id}
                                                name={card.name}
                                                position={card.position}
                                                jobPostingTitle={
                                                    card.job_posting_title
                                                }
                                                daysInStage={card.days_in_stage}
                                                source={card.source}
                                                email={card.email}
                                            />
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
