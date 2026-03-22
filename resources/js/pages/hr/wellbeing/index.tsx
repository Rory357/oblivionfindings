import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { type BreadcrumbItem } from '@/types';
import { Heart, Zap, Weight, TrendingUp } from 'lucide-react';
import { LineChart, Line, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

type MoodTrend = {
    week: string;
    total: number;
    great: number;
    good: number;
    okay: number;
    struggling: number;
    bad: number;
};

type Average = {
    week: string;
    avg_energy: number;
    avg_workload: number;
};

type Note = {
    id: number;
    mood: string;
    notes: string;
    check_in_date: string;
};

type Summary = {
    total_checkins: number;
    avg_energy: number | null;
    avg_workload: number | null;
    mood_breakdown: Record<string, number>;
};

type Props = {
    moodTrends: MoodTrend[];
    averages: Average[];
    recentNotes: Note[];
    summary: Summary;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Wellbeing', href: '/hr/wellbeing' },
];

const moodEmoji: Record<string, string> = {
    great: '😄',
    good: '🙂',
    okay: '😐',
    struggling: '😟',
    bad: '😢',
};

const moodColors: Record<string, string> = {
    great: '#22c55e',
    good: '#3b82f6',
    okay: '#eab308',
    struggling: '#f97316',
    bad: '#ef4444',
};

export default function WellbeingIndex({ moodTrends, averages, recentNotes, summary }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Wellbeing Dashboard" />
            <div className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Wellbeing Dashboard</h1>
                    <p className="text-sm text-muted-foreground">Track team wellbeing trends and pulse check-in data</p>
                </div>

                {/* Summary Cards */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <Heart className="h-5 w-5 text-pink-500" />
                                <div>
                                    <p className="text-2xl font-bold">{summary.total_checkins}</p>
                                    <p className="text-sm text-muted-foreground">Check-ins (30 days)</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <Zap className="h-5 w-5 text-yellow-500" />
                                <div>
                                    <p className="text-2xl font-bold">{summary.avg_energy ?? 'N/A'}</p>
                                    <p className="text-sm text-muted-foreground">Avg Energy (1-5)</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <Weight className="h-5 w-5 text-blue-500" />
                                <div>
                                    <p className="text-2xl font-bold">{summary.avg_workload ?? 'N/A'}</p>
                                    <p className="text-sm text-muted-foreground">Avg Workload (1-5)</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <TrendingUp className="h-5 w-5 text-emerald-500" />
                                <div>
                                    <div className="flex gap-1.5 text-lg">
                                        {Object.entries(summary.mood_breakdown).map(([mood, count]) => (
                                            <span key={mood} title={`${mood}: ${count}`}>
                                                {moodEmoji[mood]} <span className="text-sm">{count}</span>
                                            </span>
                                        ))}
                                    </div>
                                    <p className="text-sm text-muted-foreground">Mood Spread</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Mood Trends Chart */}
                {moodTrends.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Mood Trends (% by Week)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ResponsiveContainer width="100%" height={300}>
                                <LineChart data={moodTrends}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="week" />
                                    <YAxis domain={[0, 100]} />
                                    <Tooltip />
                                    <Legend />
                                    <Line type="monotone" dataKey="great" stroke={moodColors.great} name="Great" />
                                    <Line type="monotone" dataKey="good" stroke={moodColors.good} name="Good" />
                                    <Line type="monotone" dataKey="okay" stroke={moodColors.okay} name="Okay" />
                                    <Line type="monotone" dataKey="struggling" stroke={moodColors.struggling} name="Struggling" />
                                    <Line type="monotone" dataKey="bad" stroke={moodColors.bad} name="Bad" />
                                </LineChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                )}

                {/* Energy & Workload Chart */}
                {averages.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Average Energy & Workload by Week</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ResponsiveContainer width="100%" height={300}>
                                <BarChart data={averages}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="week" />
                                    <YAxis domain={[0, 5]} />
                                    <Tooltip />
                                    <Legend />
                                    <Bar dataKey="avg_energy" fill="#eab308" name="Energy" />
                                    <Bar dataKey="avg_workload" fill="#3b82f6" name="Workload" />
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                )}

                {/* Recent Notes */}
                {recentNotes.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent Comments</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                {recentNotes.map((note) => (
                                    <div key={note.id} className="flex items-start gap-3 rounded-lg border p-3">
                                        <span className="text-xl">{moodEmoji[note.mood] || '?'}</span>
                                        <div className="flex-1">
                                            <p className="text-sm">{note.notes}</p>
                                            <p className="text-xs text-muted-foreground mt-1">{note.check_in_date}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {moodTrends.length === 0 && (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            No check-in data yet. Wellbeing trends will appear once team members start checking in.
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
