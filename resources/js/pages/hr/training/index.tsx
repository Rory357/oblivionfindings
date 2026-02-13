import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { Users, GraduationCap, AlertTriangle, Clock, CheckCircle2, BookOpen } from 'lucide-react';

interface Stats {
    total_staff: number;
    fully_trained: number;
    partial: number;
    untrained: number;
}

interface UpcomingExpiry {
    id: number;
    user_name: string;
    user_email: string;
    requirement_name: string;
    expiry_date: string;
    days_until_expiry: number;
}

interface RecentCompletion {
    id: number;
    user_name: string;
    requirement_name: string;
    completed_date: string;
}

interface RequirementStat {
    id: number;
    name: string;
    type: string;
    total_applicable: number;
    compliant_count: number;
    expired_count: number;
    completion_rate: number;
}

interface Props {
    stats: Stats;
    upcomingExpiries: UpcomingExpiry[];
    recentCompletions: RecentCompletion[];
    requirementStats: RequirementStat[];
    filters: { q: string; type: string | null };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'Training', href: '/hr/training' },
];

export default function TrainingIndex({ stats, upcomingExpiries, recentCompletions, requirementStats, filters }: Props) {
    function applyFilter(key: string, value: string | null) {
        router.get('/hr/training', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    const trainingRate = stats.total_staff > 0
        ? Math.round((stats.fully_trained / stats.total_staff) * 100)
        : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Training Dashboard" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Training Dashboard</h1>
                        <p className="text-muted-foreground">Monitor staff training compliance and upcoming renewals</p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href="/hr/compliance">Compliance Dashboard</Link>
                    </Button>
                </div>

                {/* Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Total Staff</p>
                                    <p className="text-3xl font-bold">{stats.total_staff}</p>
                                </div>
                                <Users className="h-8 w-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Fully Trained</p>
                                    <p className="text-3xl font-bold text-green-600">{stats.fully_trained}</p>
                                    <p className="text-xs text-muted-foreground">{trainingRate}% of staff</p>
                                </div>
                                <GraduationCap className="h-8 w-8 text-green-500" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Partially Trained</p>
                                    <p className="text-3xl font-bold text-yellow-600">{stats.partial}</p>
                                </div>
                                <Clock className="h-8 w-8 text-yellow-500" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Untrained</p>
                                    <p className="text-3xl font-bold text-destructive">{stats.untrained}</p>
                                </div>
                                <AlertTriangle className="h-8 w-8 text-destructive" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Upcoming Expiries */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <AlertTriangle className="h-5 w-5 text-yellow-500" />
                                Upcoming Expiries
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {upcomingExpiries.length === 0 ? (
                                <div className="px-4 py-8 text-center text-muted-foreground">
                                    <CheckCircle2 className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                    <p className="text-sm">No upcoming expiries in the next 60 days.</p>
                                </div>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="px-4 py-2 text-left font-medium">Staff</th>
                                            <th className="px-4 py-2 text-left font-medium">Requirement</th>
                                            <th className="px-4 py-2 text-left font-medium">Expires</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {upcomingExpiries.map((expiry) => (
                                            <tr key={expiry.id} className="hover:bg-muted/30">
                                                <td className="px-4 py-2">
                                                    <div className="font-medium">{expiry.user_name}</div>
                                                    <div className="text-xs text-muted-foreground">{expiry.user_email}</div>
                                                </td>
                                                <td className="px-4 py-2">{expiry.requirement_name}</td>
                                                <td className="px-4 py-2">
                                                    <div className={`font-medium ${expiry.days_until_expiry <= 14 ? 'text-destructive' : 'text-yellow-600'}`}>
                                                        {expiry.expiry_date}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {expiry.days_until_expiry <= 0
                                                            ? 'Expired'
                                                            : expiry.days_until_expiry === 1
                                                                ? '1 day left'
                                                                : `${expiry.days_until_expiry} days left`}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>

                    {/* Recent Completions */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CheckCircle2 className="h-5 w-5 text-green-500" />
                                Recent Completions
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {recentCompletions.length === 0 ? (
                                <div className="px-4 py-8 text-center text-muted-foreground">
                                    <BookOpen className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                    <p className="text-sm">No recent training completions.</p>
                                </div>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="px-4 py-2 text-left font-medium">Staff</th>
                                            <th className="px-4 py-2 text-left font-medium">Requirement</th>
                                            <th className="px-4 py-2 text-left font-medium">Completed</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {recentCompletions.map((completion) => (
                                            <tr key={completion.id} className="hover:bg-muted/30">
                                                <td className="px-4 py-2 font-medium">{completion.user_name}</td>
                                                <td className="px-4 py-2">{completion.requirement_name}</td>
                                                <td className="px-4 py-2 text-muted-foreground">{completion.completed_date}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Requirement-Level Completion Rates */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2">
                                <GraduationCap className="h-5 w-5" />
                                Training Requirement Completion Rates
                            </CardTitle>
                            <div className="flex items-center gap-2">
                                <Input
                                    placeholder="Search requirements..."
                                    defaultValue={filters.q}
                                    className="w-48"
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') applyFilter('q', (e.target as HTMLInputElement).value);
                                    }}
                                />
                                <Select value={filters.type || '__none__'} onValueChange={(v) => applyFilter('type', v === '__none__' ? null : v)}>
                                    <SelectTrigger className="w-40"><SelectValue placeholder="Type" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">All Types</SelectItem>
                                        <SelectItem value="certification">Certification</SelectItem>
                                        <SelectItem value="training">Training</SelectItem>
                                        <SelectItem value="document">Document</SelectItem>
                                        <SelectItem value="check">Background Check</SelectItem>
                                        <SelectItem value="license">License</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Requirement</th>
                                    <th className="px-4 py-3 text-left font-medium">Type</th>
                                    <th className="px-4 py-3 text-center font-medium">Applicable</th>
                                    <th className="px-4 py-3 text-center font-medium">Compliant</th>
                                    <th className="px-4 py-3 text-center font-medium">Expired</th>
                                    <th className="px-4 py-3 text-left font-medium">Completion Rate</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {requirementStats.map((req) => (
                                    <tr key={req.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3 font-medium">{req.name}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant="outline" className="capitalize text-xs">{req.type.replace('_', ' ')}</Badge>
                                        </td>
                                        <td className="px-4 py-3 text-center">{req.total_applicable}</td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="font-medium text-green-600">{req.compliant_count}</span>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {req.expired_count > 0 ? (
                                                <span className="font-medium text-destructive">{req.expired_count}</span>
                                            ) : (
                                                <span className="text-muted-foreground">0</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <div className="h-2 w-24 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className={`h-full rounded-full transition-all ${
                                                            req.completion_rate === 100
                                                                ? 'bg-green-500'
                                                                : req.completion_rate >= 70
                                                                    ? 'bg-yellow-500'
                                                                    : 'bg-destructive'
                                                        }`}
                                                        style={{ width: `${req.completion_rate}%` }}
                                                    />
                                                </div>
                                                <span className="text-xs font-medium">{req.completion_rate}%</span>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {requirementStats.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                                            <GraduationCap className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                            <p>No training requirements found.</p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
