import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { ClipboardCheck, Calendar, User, ArrowLeft, Filter } from 'lucide-react';

type Site = {
    id: number;
    name: string;
};

type Run = {
    id: number;
    template: {
        id: number;
        name: string;
    };
    status: 'scheduled' | 'in_progress' | 'completed' | 'overdue' | 'skipped';
    scheduled_date: string;
    completed_at?: string;
    completed_by?: { id: number; name: string } | null;
};

type Props = {
    site: Site;
    runs: {
        data: Run[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { status?: string };
};

const statusLabels: Record<string, string> = {
    scheduled: 'Scheduled',
    in_progress: 'In Progress',
    completed: 'Completed',
    overdue: 'Overdue',
    skipped: 'Skipped',
};

const statusColors: Record<string, string> = {
    scheduled: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    in_progress: 'bg-blue-500/20 text-blue-400 border-blue-500/30',
    completed: 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30',
    overdue: 'bg-red-500/20 text-red-400 border-red-500/30',
    skipped: 'bg-slate-500/20 text-slate-300 border-slate-500/30',
};

export default function ChecklistRuns({ site, runs, filters }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Checklists', href: `/sites/${site.id}/checklists` }, { title: 'Runs', href: '#' }]}>
            <Head title={`${site.name} - Checklist Runs`} />

            <div className="m-4 max-w-5xl mx-auto space-y-4">
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
                            Checklist Runs
                        </h1>
                        <p className="text-sm text-slate-400">{site.name}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm">
                            <Filter className="w-4 h-4 mr-1" />
                            Filter
                        </Button>
                    </div>
                </div>

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-4">
                    <Card className="bg-slate-800/30">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{runs.data.length}</div>
                            <div className="text-sm text-slate-400">Total Runs</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-yellow-500/5 border-yellow-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-yellow-400">
                                {runs.data.filter(r => r.status === 'scheduled').length}
                            </div>
                            <div className="text-sm text-slate-400">Scheduled</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-blue-500/5 border-blue-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-blue-400">
                                {runs.data.filter(r => r.status === 'in_progress').length}
                            </div>
                            <div className="text-sm text-slate-400">In Progress</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-emerald-500/5 border-emerald-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-emerald-400">
                                {runs.data.filter(r => r.status === 'completed').length}
                            </div>
                            <div className="text-sm text-slate-400">Completed</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Runs List */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">All Runs</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {runs.data.length === 0 ? (
                            <div className="text-center py-8 text-slate-400">
                                <ClipboardCheck className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No checklist runs yet</p>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {runs.data.map((run) => (
                                    <div
                                        key={run.id}
                                        className="flex items-center justify-between p-3 rounded-lg border border-slate-700 hover:bg-slate-800/50"
                                    >
                                        <div>
                                            <div className="font-medium">{run.template.name}</div>
                                            <div className="text-sm text-slate-400 flex items-center gap-3 mt-1">
                                                <span className="flex items-center gap-1">
                                                    <Calendar className="w-3.5 h-3.5" />
                                                    {new Date(run.scheduled_date).toLocaleDateString()}
                                                </span>
                                                {run.completed_by && (
                                                    <span className="flex items-center gap-1">
                                                        <User className="w-3.5 h-3.5" />
                                                        {run.completed_by.name}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <Badge className={statusColors[run.status]}>
                                                {statusLabels[run.status]}
                                            </Badge>
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={`/checklists/runs/${run.id}`}>
                                                    View
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
