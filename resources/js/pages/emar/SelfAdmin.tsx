import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, ClipboardCheck, User } from 'lucide-react';

type Assessment = {
    id: number;
    status: string;
    outcome: string | null;
    cognitive_capacity: number | null;
    physical_dexterity: number | null;
    vision_ability: number | null;
    swallowing_ability: number | null;
    understanding_score: number | null;
    can_identify_medications: boolean;
    can_read_labels: boolean;
    can_open_packaging: boolean;
    can_manage_timing: boolean;
    can_store_safely: boolean;
    willing_to_self_admin: boolean;
    assessment_date: string | null;
    reassessment_date: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    assessor: { id: number; name: string } | null;
};

type Props = {
    assessments: { data: Assessment[]; links: any };
    dueReassessments: Assessment[];
    clients: { id: number; first_name: string; last_name: string }[];
    filters: { client_id?: string };
};

const outcomeLabels: Record<string, { label: string; color: string }> = {
    independent: { label: 'Cat 1: Independent', color: 'bg-green-100 text-green-700' },
    prompted: { label: 'Cat 2: Prompted', color: 'bg-blue-100 text-blue-700' },
    supervised: { label: 'Cat 3: Supervised', color: 'bg-amber-100 text-amber-700' },
    administered: { label: 'Cat 4: Staff Administered', color: 'bg-red-100 text-red-700' },
};

function ScoreBar({ value, max = 5 }: { value: number | null; max?: number }) {
    if (value === null) return <span className="text-muted-foreground">—</span>;
    const pct = (value / max) * 100;
    const color = pct >= 80 ? 'bg-green-500' : pct >= 60 ? 'bg-amber-500' : 'bg-red-500';
    return (
        <div className="flex items-center gap-2">
            <div className="h-2 w-16 rounded-full bg-muted">
                <div className={`h-2 rounded-full ${color}`} style={{ width: `${pct}%` }} />
            </div>
            <span className="text-xs">{value}/{max}</span>
        </div>
    );
}

export default function SelfAdmin({ assessments, dueReassessments, clients, filters }: Props) {
    return (
        <AppLayout>
            <Head title="eMAR - Self-Administration Assessments" />
            <PageHeader title="Self-Administration Assessments" description="Assess client capacity for self-medication per NZ MOH medication support categories." backHref="/emar" />
            <PageShell>
                {/* Due Reassessments */}
                {dueReassessments.length > 0 && (
                    <Card className="mb-6 border-amber-200 dark:border-amber-800">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base text-amber-700 dark:text-amber-400">
                                <AlertTriangle className="h-4 w-4" /> Reassessments Due ({dueReassessments.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y">
                                {dueReassessments.map((a) => (
                                    <div key={a.id} className="flex items-center justify-between p-3">
                                        <span className="font-medium">{a.client?.last_name}, {a.client?.first_name}</span>
                                        <span className="text-xs text-amber-600">Due: {a.reassessment_date ? new Date(a.reassessment_date).toLocaleDateString('en-NZ') : '—'}</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Filter */}
                <div className="mb-4">
                    <Select value={filters.client_id ?? ''} onValueChange={(v) => router.get('/emar/self-admin', { client_id: v || undefined }, { preserveState: true })}>
                        <SelectTrigger className="w-56"><SelectValue placeholder="All clients" /></SelectTrigger>
                        <SelectContent>
                            {clients.map((c) => <SelectItem key={c.id} value={c.id.toString()}>{c.last_name}, {c.first_name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>

                {/* Assessments */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="p-3 text-left font-medium">Client</th>
                                    <th className="p-3 text-left font-medium">Date</th>
                                    <th className="p-3 text-left font-medium">Outcome</th>
                                    <th className="p-3 text-left font-medium">Cognitive</th>
                                    <th className="p-3 text-left font-medium">Dexterity</th>
                                    <th className="p-3 text-left font-medium">Vision</th>
                                    <th className="p-3 text-left font-medium">Understanding</th>
                                    <th className="p-3 text-left font-medium">Reassessment</th>
                                    <th className="p-3 text-left font-medium">Assessor</th>
                                </tr>
                            </thead>
                            <tbody>
                                {assessments.data.map((a) => {
                                    const outcomeCfg = a.outcome ? outcomeLabels[a.outcome] : null;
                                    return (
                                        <tr key={a.id} className="border-b last:border-0">
                                            <td className="p-3 font-medium">{a.client?.last_name}, {a.client?.first_name}</td>
                                            <td className="p-3 text-xs">{a.assessment_date ? new Date(a.assessment_date).toLocaleDateString('en-NZ') : '—'}</td>
                                            <td className="p-3">{outcomeCfg ? <Badge className={`text-xs ${outcomeCfg.color}`}>{outcomeCfg.label}</Badge> : <span className="text-muted-foreground">—</span>}</td>
                                            <td className="p-3"><ScoreBar value={a.cognitive_capacity} /></td>
                                            <td className="p-3"><ScoreBar value={a.physical_dexterity} /></td>
                                            <td className="p-3"><ScoreBar value={a.vision_ability} /></td>
                                            <td className="p-3"><ScoreBar value={a.understanding_score} /></td>
                                            <td className="p-3 text-xs">
                                                {a.reassessment_date ? new Date(a.reassessment_date).toLocaleDateString('en-NZ') : '—'}
                                                {a.reassessment_date && new Date(a.reassessment_date) < new Date() && <Badge variant="destructive" className="ml-1 text-[10px]">Due</Badge>}
                                            </td>
                                            <td className="p-3 text-xs">{a.assessor?.name ?? '—'}</td>
                                        </tr>
                                    );
                                })}
                                {assessments.data.length === 0 && <tr><td colSpan={9} className="p-6 text-center text-muted-foreground">No assessments found.</td></tr>}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
