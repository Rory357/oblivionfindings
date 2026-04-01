import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { List, LayoutGrid, Search, Plus } from 'lucide-react';
import { CandidateCard } from '@/components/recruitment/candidate-card';
import { stageLabels, stageColors } from '@/components/recruitment/status-badge';

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
                ? cards.filter(c => c.name.toLowerCase().includes(search.toLowerCase()) || c.position.toLowerCase().includes(search.toLowerCase()))
                : cards,
        ]),
    );

    const totalCandidates = Object.values(columns).reduce((sum, cards) => sum + cards.length, 0);

    return (
        <AppLayout breadcrumbs={[
            { title: 'HR', href: '/hr' },
            { title: 'Recruitment', href: '/hr/recruitment' },
            { title: 'Kanban', href: '/hr/recruitment/kanban' },
        ]}>
            <Head title="Recruitment Kanban" />
            <PageShell>
                <PageHeader
                    title="Recruitment Pipeline"
                    description={`Kanban view - ${totalCandidates} candidates across ${stages.length} stages`}
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/hr/recruitment"><List className="mr-2 h-4 w-4" />List View</Link>
                            </Button>
                            <Button variant="secondary" size="sm" disabled>
                                <LayoutGrid className="mr-2 h-4 w-4" />Kanban
                            </Button>
                            {can.manage && (
                                <Button size="sm" asChild>
                                    <Link href="/hr/recruitment/candidates/create"><Plus className="mr-2 h-4 w-4" />Add</Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                {/* Search */}
                <div className="relative max-w-sm">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input placeholder="Filter candidates..." value={search} onChange={(e) => setSearch(e.target.value)} className="pl-9" />
                </div>

                {/* Kanban Board */}
                <div className="flex gap-4 overflow-x-auto pb-4">
                    {stages.map((stage) => {
                        const cards = filteredColumns[stage] ?? [];
                        const avgDays = cards.length > 0
                            ? Math.round(cards.reduce((sum, c) => sum + c.days_in_stage, 0) / cards.length)
                            : 0;
                        return (
                            <div key={stage} className="flex-shrink-0 w-72">
                                <div className={`rounded-t-lg border-t-2 px-3 py-2.5 ${stageColors[stage] ?? 'bg-muted/50 border-muted'}`}>
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-semibold">{stageLabels[stage] ?? stage}</span>
                                        <div className="flex items-center gap-1.5">
                                            <Badge variant="secondary" className="text-xs h-5">{cards.length}</Badge>
                                            {avgDays > 0 && (
                                                <Badge variant="outline" className={`text-[10px] h-5 ${avgDays > 14 ? 'text-red-400 border-red-500/30' : avgDays > 7 ? 'text-amber-400 border-amber-500/30' : 'text-muted-foreground'}`}>
                                                    ~{avgDays}d
                                                </Badge>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <div className="space-y-2 rounded-b-lg border border-t-0 bg-muted/10 p-2 min-h-[250px]">
                                    {cards.length === 0 ? (
                                        <div className="text-center text-xs text-muted-foreground py-12">No candidates</div>
                                    ) : (
                                        cards.map((card) => (
                                            <CandidateCard
                                                key={card.id}
                                                id={card.id}
                                                name={card.name}
                                                position={card.position}
                                                jobPostingTitle={card.job_posting_title}
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
