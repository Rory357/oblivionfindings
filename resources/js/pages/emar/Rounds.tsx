import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Clock, CheckCircle, AlertTriangle, Play, Users } from 'lucide-react';

type MedRound = {
    id: number;
    name: string;
    round_type: string;
    scheduled_time: string;
    window_minutes: number;
    status: string;
    total_medications: number;
    administered_count: number;
    refused_count: number;
    withheld_count: number;
    missed_count: number;
    started_at: string | null;
    completed_at: string | null;
    assigned_to: { id: number; name: string } | null;
    started_by: { id: number; name: string } | null;
    completed_by: { id: number; name: string } | null;
    notes: string | null;
};

type Template = {
    id: number;
    name: string;
    scheduled_time: string;
    window_minutes: number;
    days_of_week: number[] | null;
    active: boolean;
};

type Props = {
    rounds: MedRound[];
    templates: Template[];
    date: string;
};

const statusConfig: Record<string, { color: string; icon: any }> = {
    pending: { color: 'bg-gray-100 text-gray-700', icon: Clock },
    in_progress: { color: 'bg-blue-100 text-blue-700', icon: Play },
    completed: { color: 'bg-green-100 text-green-700', icon: CheckCircle },
    partial: { color: 'bg-amber-100 text-amber-700', icon: AlertTriangle },
};

export default function Rounds({ rounds, templates, date }: Props) {
    function navigateDate(offset: number) {
        const d = new Date(date);
        d.setDate(d.getDate() + offset);
        router.get('/emar/rounds', { date: d.toISOString().split('T')[0] }, { preserveState: true });
    }

    return (
        <AppLayout>
            <Head title="eMAR - Medication Rounds" />
            <PageHeader title="Medication Rounds" description="Manage daily medication administration rounds, assignments, and completion tracking." backHref="/emar" />
            <PageShell>
                {/* Date Navigation */}
                <div className="mb-6 flex items-center gap-3">
                    <Button variant="outline" size="icon" onClick={() => navigateDate(-1)}><ChevronLeft className="h-4 w-4" /></Button>
                    <Input type="date" value={date} onChange={(e) => router.get('/emar/rounds', { date: e.target.value }, { preserveState: true })} className="w-40" />
                    <Button variant="outline" size="icon" onClick={() => navigateDate(1)}><ChevronRight className="h-4 w-4" /></Button>
                    <Button variant="outline" size="sm" onClick={() => router.get('/emar/rounds', { date: new Date().toISOString().split('T')[0] })}>Today</Button>
                </div>

                {/* Rounds Grid */}
                <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {rounds.map((round) => {
                        const cfg = statusConfig[round.status] ?? statusConfig.pending;
                        const Icon = cfg.icon;
                        const completionPct = round.total_medications > 0 ? Math.round((round.administered_count / round.total_medications) * 100) : 0;
                        return (
                            <Card key={round.id}>
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-base">{round.name}</CardTitle>
                                        <Badge className={`text-xs ${cfg.color}`}>
                                            <Icon className="mr-1 h-3 w-3" /> {round.status}
                                        </Badge>
                                    </div>
                                    <p className="text-xs text-muted-foreground">{round.scheduled_time} ({round.round_type})</p>
                                </CardHeader>
                                <CardContent>
                                    <div className="mb-3">
                                        <div className="mb-1 flex justify-between text-xs text-muted-foreground">
                                            <span>Progress</span>
                                            <span>{round.administered_count} / {round.total_medications}</span>
                                        </div>
                                        <Progress value={completionPct} className="h-2" />
                                    </div>
                                    <div className="grid grid-cols-4 gap-2 text-center text-xs">
                                        <div>
                                            <p className="font-bold text-green-600">{round.administered_count}</p>
                                            <p className="text-muted-foreground">Given</p>
                                        </div>
                                        <div>
                                            <p className="font-bold text-orange-500">{round.refused_count}</p>
                                            <p className="text-muted-foreground">Refused</p>
                                        </div>
                                        <div>
                                            <p className="font-bold text-amber-500">{round.withheld_count}</p>
                                            <p className="text-muted-foreground">Withheld</p>
                                        </div>
                                        <div>
                                            <p className="font-bold text-red-500">{round.missed_count}</p>
                                            <p className="text-muted-foreground">Missed</p>
                                        </div>
                                    </div>
                                    {round.assigned_to && (
                                        <div className="mt-3 flex items-center gap-1 text-xs text-muted-foreground">
                                            <Users className="h-3 w-3" /> {round.assigned_to.name}
                                        </div>
                                    )}
                                    {round.started_at && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Started: {new Date(round.started_at).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}
                                            {round.completed_at && ` — Completed: ${new Date(round.completed_at).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}`}
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        );
                    })}
                    {rounds.length === 0 && (
                        <Card className="sm:col-span-2 lg:col-span-3">
                            <CardContent className="flex flex-col items-center py-12">
                                <Clock className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <p className="text-muted-foreground">No medication rounds scheduled for this date.</p>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Round Templates */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Round Templates</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="p-3 text-left font-medium">Name</th>
                                    <th className="p-3 text-left font-medium">Time</th>
                                    <th className="p-3 text-left font-medium">Window</th>
                                    <th className="p-3 text-left font-medium">Days</th>
                                    <th className="p-3 text-left font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {templates.map((t) => {
                                    const dayNames = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                    return (
                                        <tr key={t.id} className="border-b last:border-0">
                                            <td className="p-3 font-medium">{t.name}</td>
                                            <td className="p-3">{t.scheduled_time}</td>
                                            <td className="p-3">±{t.window_minutes} min</td>
                                            <td className="p-3 text-xs">{t.days_of_week ? t.days_of_week.map((d) => dayNames[d]).join(', ') : 'Every day'}</td>
                                            <td className="p-3">{t.active ? <Badge className="bg-green-100 text-green-700 text-xs">Active</Badge> : <Badge variant="outline" className="text-xs">Inactive</Badge>}</td>
                                        </tr>
                                    );
                                })}
                                {templates.length === 0 && <tr><td colSpan={5} className="p-6 text-center text-muted-foreground">No round templates configured.</td></tr>}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
