import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';

type Props = {
    destructions: { data: any[]; links: any };
    filters: { controlled_only?: boolean };
};

export default function Destructions({ destructions, filters }: Props) {
    return (
        <AppLayout>
            <Head title="eMAR - Destruction Records" />
            <PageHeader title="Medication Destruction / Disposal" description="Records of medication destruction and disposal with dual-witness verification." backHref="/emar" />
            <PageShell>
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="p-3 text-left font-medium">Date</th>
                                    <th className="p-3 text-left font-medium">Client</th>
                                    <th className="p-3 text-left font-medium">Medication</th>
                                    <th className="p-3 text-left font-medium">Form</th>
                                    <th className="p-3 text-left font-medium">Qty</th>
                                    <th className="p-3 text-left font-medium">Reason</th>
                                    <th className="p-3 text-left font-medium">Method</th>
                                    <th className="p-3 text-left font-medium">Destroyed By</th>
                                    <th className="p-3 text-left font-medium">Witness 1</th>
                                    <th className="p-3 text-left font-medium">Witness 2</th>
                                </tr>
                            </thead>
                            <tbody>
                                {destructions.data.map((d: any) => (
                                    <tr key={d.id} className="border-b last:border-0">
                                        <td className="p-3 text-xs">{d.destroyed_at ? new Date(d.destroyed_at).toLocaleDateString('en-NZ') : '—'}</td>
                                        <td className="p-3">{d.client ? `${d.client.last_name}, ${d.client.first_name}` : '—'}</td>
                                        <td className="p-3">
                                            <span className="font-medium">{d.medication_name}</span>
                                            {d.is_controlled_drug && <Badge variant="destructive" className="ml-1 text-[10px]">CD {d.controlled_drug_class}</Badge>}
                                        </td>
                                        <td className="p-3 text-xs">{d.form ?? '—'} {d.strength ?? ''}</td>
                                        <td className="p-3 font-mono">{d.quantity} {d.unit}</td>
                                        <td className="p-3 text-xs">{d.reason}</td>
                                        <td className="p-3 text-xs">{d.disposal_method}</td>
                                        <td className="p-3 text-xs">{d.destroyed_by_user?.name ?? '—'}</td>
                                        <td className="p-3 text-xs">{d.witness_1?.name ?? '—'}</td>
                                        <td className="p-3 text-xs">{d.witness_2?.name ?? '—'}</td>
                                    </tr>
                                ))}
                                {destructions.data.length === 0 && (
                                    <tr>
                                        <td colSpan={10} className="p-6 text-center text-muted-foreground">
                                            <Trash2 className="mx-auto mb-2 h-8 w-8 text-muted-foreground/30" />
                                            No destruction records found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
