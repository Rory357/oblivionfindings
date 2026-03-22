import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Users, Star } from 'lucide-react';

type Candidate = { id: number; name: string; readiness: string; strengths: string | null; development_needs: string | null; overall_rating: number | null };
type Plan = { id: number; role_title: string; department: string | null; risk_level: string; current_holder_name: string | null; notes: string | null; candidates: Candidate[] };
type Props = { plan: Plan; can: { manage?: boolean } };

const breadcrumbs = [{ title: 'HR', href: '/hr' }, { title: 'Succession', href: '/hr/succession' }, { title: 'Detail', href: '#' }];
const readinessLabels: Record<string, string> = { ready_now: 'Ready Now', ready_1_year: '1 Year', ready_2_years: '2 Years', developing: 'Developing' };
const readinessColors: Record<string, string> = { ready_now: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', ready_1_year: 'border-blue-500/30 text-blue-400 bg-blue-500/10', ready_2_years: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10', developing: 'border-slate-500/30 text-slate-400' };

export default function SuccessionShow({ plan, can }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Succession: ${plan.role_title}`} />
            <PageShell>
                <PageHeader title={plan.role_title} description={plan.department || 'Succession Plan'} />
                <div className="grid gap-4 md:grid-cols-3">
                    <Card><CardHeader className="pb-2"><CardTitle className="text-sm text-muted-foreground">Current Holder</CardTitle></CardHeader><CardContent><p className="text-lg font-medium">{plan.current_holder_name || 'Vacant'}</p></CardContent></Card>
                    <Card><CardHeader className="pb-2"><CardTitle className="text-sm text-muted-foreground">Risk Level</CardTitle></CardHeader><CardContent><Badge variant="outline">{plan.risk_level}</Badge></CardContent></Card>
                    <Card><CardHeader className="pb-2"><CardTitle className="text-sm text-muted-foreground">Candidates</CardTitle></CardHeader><CardContent><p className="text-lg font-medium flex items-center gap-1"><Users className="h-4 w-4" />{plan.candidates.length}</p></CardContent></Card>
                </div>
                {plan.notes && <Card><CardContent className="pt-4"><p className="text-sm text-muted-foreground">{plan.notes}</p></CardContent></Card>}
                <Card>
                    <CardHeader><CardTitle>Succession Candidates</CardTitle></CardHeader>
                    <CardContent>
                        {plan.candidates.length === 0 ? (
                            <p className="text-center text-muted-foreground py-8">No candidates added yet.</p>
                        ) : (
                            <div className="grid gap-4 md:grid-cols-2">
                                {plan.candidates.map(c => (
                                    <Card key={c.id}>
                                        <CardContent className="pt-4 space-y-2">
                                            <div className="flex items-center justify-between">
                                                <p className="font-medium">{c.name}</p>
                                                <Badge variant="outline" className={readinessColors[c.readiness]}>{readinessLabels[c.readiness] || c.readiness}</Badge>
                                            </div>
                                            {c.overall_rating && <div className="flex gap-0.5">{[1,2,3,4,5].map(s => <Star key={s} className={`h-4 w-4 ${s <= c.overall_rating! ? 'text-yellow-400 fill-yellow-400' : 'text-muted-foreground'}`} />)}</div>}
                                            {c.strengths && <div><p className="text-xs text-muted-foreground">Strengths</p><p className="text-sm">{c.strengths}</p></div>}
                                            {c.development_needs && <div><p className="text-xs text-muted-foreground">Development Needs</p><p className="text-sm">{c.development_needs}</p></div>}
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
