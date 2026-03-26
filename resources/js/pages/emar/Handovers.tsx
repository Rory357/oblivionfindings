import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CheckCircle, Shield } from 'lucide-react';

type Handover = {
    id: number;
    handover_at: string;
    outgoing_user: { id: number; name: string } | null;
    incoming_user: { id: number; name: string } | null;
    site: { id: number; name: string } | null;
    controlled_drugs_verified: boolean;
    controlled_drug_counts: Array<{ medication_id: number; medication_name?: string; expected: number; actual: number; discrepancy: number }> | null;
    outstanding_medications: any[] | null;
    new_prescriptions: any[] | null;
    ceased_medications: any[] | null;
    incidents: any[] | null;
    prn_given: any[] | null;
    flagged_clients: any[] | null;
    general_notes: string | null;
    acknowledged: boolean;
    acknowledged_at: string | null;
};

type Props = {
    handovers: { data: Handover[]; links: any };
};

export default function Handovers({ handovers }: Props) {
    return (
        <AppLayout>
            <Head title="eMAR - Medication Handovers" />
            <PageHeader title="Medication Handovers" description="Shift handover records for medication, including controlled drug counts and outstanding items." backHref="/emar" />
            <PageShell>
                <div className="space-y-4">
                    {handovers.data.map((h) => {
                        const hasDiscrepancies = h.controlled_drug_counts?.some((c) => c.discrepancy !== 0);
                        return (
                            <Card key={h.id} className={hasDiscrepancies ? 'border-red-200 dark:border-red-800' : ''}>
                                <CardHeader className="pb-3">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">{h.outgoing_user?.name ?? 'Unknown'}</span>
                                            <ArrowRight className="h-4 w-4 text-muted-foreground" />
                                            <span className="font-medium">{h.incoming_user?.name ?? 'Unknown'}</span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs text-muted-foreground">
                                                {h.handover_at ? new Date(h.handover_at).toLocaleString('en-NZ', { dateStyle: 'short', timeStyle: 'short' }) : '—'}
                                            </span>
                                            {h.acknowledged ? (
                                                <Badge className="bg-green-100 text-green-700 text-xs"><CheckCircle className="mr-1 h-3 w-3" /> Acknowledged</Badge>
                                            ) : (
                                                <Badge variant="outline" className="text-xs">Pending</Badge>
                                            )}
                                        </div>
                                    </div>
                                    {h.site && <p className="text-xs text-muted-foreground">{h.site.name}</p>}
                                </CardHeader>
                                <CardContent>
                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        {/* Controlled Drug Counts */}
                                        <div>
                                            <h4 className="mb-2 flex items-center gap-1 text-xs font-semibold">
                                                <Shield className="h-3.5 w-3.5" /> CD Counts
                                                {h.controlled_drugs_verified ? (
                                                    <Badge className="ml-1 bg-green-100 text-green-700 text-[10px]">Verified</Badge>
                                                ) : (
                                                    <Badge variant="destructive" className="ml-1 text-[10px]">Unverified</Badge>
                                                )}
                                            </h4>
                                            {h.controlled_drug_counts && h.controlled_drug_counts.length > 0 ? (
                                                <div className="space-y-1 text-xs">
                                                    {h.controlled_drug_counts.map((c, i) => (
                                                        <div key={i} className="flex justify-between">
                                                            <span>{c.medication_name ?? `Med #${c.medication_id}`}</span>
                                                            <span className={c.discrepancy !== 0 ? 'font-bold text-red-600' : ''}>
                                                                {c.actual}/{c.expected}
                                                                {c.discrepancy !== 0 && ` (${c.discrepancy > 0 ? '+' : ''}${c.discrepancy})`}
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            ) : (
                                                <p className="text-xs text-muted-foreground">No CD counts recorded.</p>
                                            )}
                                        </div>

                                        {/* Outstanding Items */}
                                        <div>
                                            <h4 className="mb-2 text-xs font-semibold">Outstanding Items</h4>
                                            <div className="space-y-1 text-xs">
                                                {h.outstanding_medications && h.outstanding_medications.length > 0 && (
                                                    <p><Badge variant="outline" className="text-[10px]">{h.outstanding_medications.length}</Badge> Outstanding meds</p>
                                                )}
                                                {h.new_prescriptions && h.new_prescriptions.length > 0 && (
                                                    <p><Badge className="bg-blue-100 text-blue-700 text-[10px]">{h.new_prescriptions.length}</Badge> New prescriptions</p>
                                                )}
                                                {h.ceased_medications && h.ceased_medications.length > 0 && (
                                                    <p><Badge variant="secondary" className="text-[10px]">{h.ceased_medications.length}</Badge> Ceased meds</p>
                                                )}
                                                {h.incidents && h.incidents.length > 0 && (
                                                    <p><Badge variant="destructive" className="text-[10px]">{h.incidents.length}</Badge> Incidents</p>
                                                )}
                                                {h.prn_given && h.prn_given.length > 0 && (
                                                    <p><Badge variant="outline" className="text-[10px]">{h.prn_given.length}</Badge> PRN given</p>
                                                )}
                                            </div>
                                        </div>

                                        {/* Notes & Flags */}
                                        <div>
                                            <h4 className="mb-2 text-xs font-semibold">Notes</h4>
                                            {h.flagged_clients && h.flagged_clients.length > 0 && (
                                                <div className="mb-2">
                                                    <span className="flex items-center gap-1 text-xs font-medium text-amber-600">
                                                        <AlertTriangle className="h-3 w-3" /> {h.flagged_clients.length} flagged client(s)
                                                    </span>
                                                </div>
                                            )}
                                            <p className="text-xs text-muted-foreground">{h.general_notes ?? 'No notes.'}</p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}

                    {handovers.data.length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center py-12">
                                <ArrowRight className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <p className="text-muted-foreground">No medication handover records.</p>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </PageShell>
        </AppLayout>
    );
}
