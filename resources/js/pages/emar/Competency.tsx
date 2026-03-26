import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Award, CheckCircle, Clock, UserX, XCircle } from 'lucide-react';

type Props = {
    assessments: { data: any[]; links: any };
    expiringSoon: any[];
    expired: any[];
    staffWithoutAssessment: { id: number; name: string; email: string }[];
    filters: { status?: string };
};

const statusConfig: Record<string, { icon: any; color: string }> = {
    passed: { icon: CheckCircle, color: 'text-green-600' },
    failed: { icon: XCircle, color: 'text-red-600' },
    pending: { icon: Clock, color: 'text-amber-600' },
    in_progress: { icon: Clock, color: 'text-blue-600' },
    expired: { icon: AlertTriangle, color: 'text-red-600' },
};

export default function Competency({ assessments, expiringSoon, expired, staffWithoutAssessment, filters }: Props) {
    return (
        <AppLayout>
            <Head title="eMAR - Competency" />
            <PageHeader title="Medication Competency" description="Staff competency assessments for medication administration. Track certifications, renewals, and compliance." backHref="/emar" />
            <PageShell>
                {/* Stats */}
                <div className="mb-6 grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 text-red-700 dark:bg-red-900/40"><XCircle className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{expired.length}</p><p className="text-xs text-muted-foreground">Expired Assessments</p></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-900/40"><Clock className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{expiringSoon.length}</p><p className="text-xs text-muted-foreground">Expiring Within 30 Days</p></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-slate-800/40"><UserX className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{staffWithoutAssessment.length}</p><p className="text-xs text-muted-foreground">Staff Without Assessment</p></div>
                        </CardContent>
                    </Card>
                </div>

                <Tabs defaultValue="assessments">
                    <TabsList className="mb-4">
                        <TabsTrigger value="assessments"><Award className="mr-1 h-3.5 w-3.5" /> All Assessments</TabsTrigger>
                        <TabsTrigger value="expiring"><Clock className="mr-1 h-3.5 w-3.5" /> Expiring Soon ({expiringSoon.length})</TabsTrigger>
                        <TabsTrigger value="unassessed"><UserX className="mr-1 h-3.5 w-3.5" /> Unassessed Staff ({staffWithoutAssessment.length})</TabsTrigger>
                    </TabsList>

                    <TabsContent value="assessments">
                        <div className="mb-4 flex gap-3">
                            <Select value={filters.status ?? ''} onValueChange={(v) => router.get('/emar/competency', { status: v || undefined }, { preserveState: true })}>
                                <SelectTrigger className="w-40"><SelectValue placeholder="All statuses" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="passed">Passed</SelectItem>
                                    <SelectItem value="failed">Failed</SelectItem>
                                    <SelectItem value="pending">Pending</SelectItem>
                                    <SelectItem value="in_progress">In Progress</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Staff Member</th>
                                            <th className="p-3 text-left font-medium">Type</th>
                                            <th className="p-3 text-left font-medium">Date</th>
                                            <th className="p-3 text-left font-medium">Status</th>
                                            <th className="p-3 text-left font-medium">Expiry</th>
                                            <th className="p-3 text-left font-medium">Assessor</th>
                                            <th className="p-3 text-left font-medium">Permissions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {assessments.data.map((a: any) => {
                                            const cfg = statusConfig[a.status] ?? statusConfig.pending;
                                            const Icon = cfg.icon;
                                            return (
                                                <tr key={a.id} className="border-b last:border-0">
                                                    <td className="p-3 font-medium">{a.user?.name}</td>
                                                    <td className="p-3"><Badge variant="outline" className="text-xs">{a.assessment_type}</Badge></td>
                                                    <td className="p-3 text-xs">{a.assessment_date ? new Date(a.assessment_date).toLocaleDateString('en-NZ') : '—'}</td>
                                                    <td className="p-3"><div className="flex items-center gap-1"><Icon className={`h-4 w-4 ${cfg.color}`} /><span className="text-xs">{a.status}</span></div></td>
                                                    <td className="p-3 text-xs">
                                                        {a.expiry_date ? new Date(a.expiry_date).toLocaleDateString('en-NZ') : '—'}
                                                        {a.expiry_date && new Date(a.expiry_date) < new Date() && <Badge variant="destructive" className="ml-1 text-[10px]">Expired</Badge>}
                                                    </td>
                                                    <td className="p-3 text-xs">{a.assessor?.name ?? '—'}</td>
                                                    <td className="p-3">
                                                        <div className="flex gap-1">
                                                            {a.can_administer_unsupervised && <Badge className="bg-green-100 text-green-700 text-[10px]">Unsupervised</Badge>}
                                                            {a.can_witness_controlled && <Badge className="bg-blue-100 text-blue-700 text-[10px]">CD Witness</Badge>}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                        {assessments.data.length === 0 && <tr><td colSpan={7} className="p-6 text-center text-muted-foreground">No assessments found.</td></tr>}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="expiring">
                        <Card>
                            <CardContent className="p-0">
                                <div className="divide-y">
                                    {expiringSoon.map((a: any) => (
                                        <div key={a.id} className="flex items-center justify-between p-3">
                                            <span className="font-medium">{a.user?.name}</span>
                                            <div className="text-sm">
                                                Expires: <span className="font-medium text-amber-600">{a.expiry_date ? new Date(a.expiry_date).toLocaleDateString('en-NZ') : '—'}</span>
                                            </div>
                                        </div>
                                    ))}
                                    {expiringSoon.length === 0 && <div className="p-6 text-center text-muted-foreground">No assessments expiring soon.</div>}
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="unassessed">
                        <Card>
                            <CardContent className="p-0">
                                <div className="divide-y">
                                    {staffWithoutAssessment.map((s) => (
                                        <div key={s.id} className="flex items-center justify-between p-3">
                                            <div>
                                                <span className="font-medium">{s.name}</span>
                                                <span className="ml-2 text-xs text-muted-foreground">{s.email}</span>
                                            </div>
                                            <Badge variant="destructive" className="text-xs">No Active Assessment</Badge>
                                        </div>
                                    ))}
                                    {staffWithoutAssessment.length === 0 && <div className="p-6 text-center text-muted-foreground">All active staff have assessments.</div>}
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </PageShell>
        </AppLayout>
    );
}
