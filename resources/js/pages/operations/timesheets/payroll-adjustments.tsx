import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyList } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Check, ExternalLink } from 'lucide-react';

type Amendment = {
    id: number;
    timesheet_id: number;
    staff_name: string;
    client_name: string;
    site_name: string;
    work_date: string | null;
    original_values: Record<string, unknown>;
    proposed_values: Record<string, unknown>;
    reason: string | null;
    requested_by: string | null;
    reviewed_by: string | null;
    reviewed_at: string | null;
    payroll_reference: string | null;
    timesheet_url: string;
};

type Props = {
    amendments: {
        data: Amendment[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
};

function formatChanges(original: Record<string, unknown>, proposed: Record<string, unknown>): string {
    const parts: string[] = [];
    for (const key of Object.keys(proposed)) {
        const from = original[key] ?? '(empty)';
        const to = proposed[key] ?? '(empty)';
        if (String(from) !== String(to)) {
            parts.push(`${key.replace(/_/g, ' ')}: ${from} \u2192 ${to}`);
        }
    }
    return parts.length > 0 ? parts.join(', ') : 'No visible changes';
}

export default function PayrollAdjustmentsPending({ amendments }: Props) {
    const handleMarkProcessed = (amendmentId: number) => {
        if (!confirm('Mark this payroll adjustment as processed? This confirms the correction has been applied in your payroll system.')) {
            return;
        }
        router.post(`/operations/timesheets/amendments/${amendmentId}/mark-processed`, {}, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout>
            <Head title="Payroll Adjustments Pending" />
            <PageShell
                title="Payroll Adjustments Pending"
                description="Approved timesheet amendments that require payroll correction processing."
            >
                {amendments.data.length === 0 ? (
                    <EmptyList
                        icon={<Check className="h-10 w-10 text-green-500" />}
                        title="No pending payroll adjustments"
                        heading="No pending payroll adjustments"
                        description="All approved amendments have been processed."
                    />
                ) : (
                    <div className="space-y-4">
                        <div className="text-sm text-muted-foreground">
                            {amendments.total} adjustment{amendments.total !== 1 ? 's' : ''} pending payroll processing
                        </div>

                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">Staff</th>
                                        <th className="px-4 py-3 text-left font-medium">Work Date</th>
                                        <th className="px-4 py-3 text-left font-medium">Site</th>
                                        <th className="px-4 py-3 text-left font-medium">Changes</th>
                                        <th className="px-4 py-3 text-left font-medium">Reason</th>
                                        <th className="px-4 py-3 text-left font-medium">Approved</th>
                                        <th className="px-4 py-3 text-left font-medium">Payroll Ref</th>
                                        <th className="px-4 py-3 text-right font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {amendments.data.map((a) => (
                                        <tr key={a.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium">{a.staff_name}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{a.work_date ?? '-'}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{a.site_name || '-'}</td>
                                            <td className="max-w-xs truncate px-4 py-3 text-xs text-muted-foreground">
                                                {formatChanges(a.original_values ?? {}, a.proposed_values ?? {})}
                                            </td>
                                            <td className="max-w-[200px] truncate px-4 py-3 text-xs">{a.reason || '-'}</td>
                                            <td className="px-4 py-3 text-xs text-muted-foreground">
                                                {a.reviewed_at ? new Date(a.reviewed_at).toLocaleDateString() : '-'}
                                                {a.reviewed_by ? ` by ${a.reviewed_by}` : ''}
                                            </td>
                                            <td className="px-4 py-3">
                                                {a.payroll_reference ? (
                                                    <Badge variant="outline">{a.payroll_reference}</Badge>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">-</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <Link href={a.timesheet_url}>
                                                            <ExternalLink className="mr-1 h-3.5 w-3.5" />
                                                            View
                                                        </Link>
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handleMarkProcessed(a.id)}
                                                    >
                                                        <Check className="mr-1 h-3.5 w-3.5" />
                                                        Processed
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {amendments.last_page > 1 && (
                            <div className="flex items-center justify-center gap-2 pt-4">
                                {amendments.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.visit(link.url)}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
