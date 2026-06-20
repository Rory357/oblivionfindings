/**
 * Read-only Privacy panel for the client profile — lists the Privacy Act 2020
 * access / correction requests made about this client. Cross-module link into
 * the privacy command centre.
 */
import { Button } from '@/components/ui/button';
import { fmtDate, PRIVACY_PILL, requestStatus, requestType } from '@/pages/privacy/privacy-shared';
import { Link } from '@inertiajs/react';
import { ExternalLink, FileText, Plus, ShieldCheck } from 'lucide-react';

type Dsr = {
    id: number;
    reference: string;
    request_type: string;
    status: string;
    received_at: string | null;
    due_date: string | null;
    is_overdue: boolean;
    assigned_to: string | null;
};

export function ClientPrivacyPanel({ requests, canManage }: { requests: Dsr[]; canManage: boolean }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2.5">
                    <span className="grid h-10 w-10 place-items-center rounded-xl bg-primary/10 text-primary">
                        <ShieldCheck className="h-5 w-5" />
                    </span>
                    <div>
                        <h3 className="text-base font-bold">Privacy requests</h3>
                        <p className="text-[13px] text-muted-foreground">Access &amp; correction requests about this client (Privacy Act 2020, IPP 6/7).</p>
                    </div>
                </div>
                {canManage ? (
                    <Button asChild size="sm">
                        <Link href="/privacy/dashboard?new=request">
                            <Plus className="mr-1.5 h-4 w-4" /> Log a request
                        </Link>
                    </Button>
                ) : null}
            </div>

            {requests.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border p-8 text-center">
                    <FileText className="mx-auto h-8 w-8 text-muted-foreground/50" />
                    <div className="mt-2 text-sm font-semibold">No privacy requests</div>
                    <div className="text-[13px] text-muted-foreground">No one has made an access or correction request about this client.</div>
                </div>
            ) : (
                <div className="flex flex-col gap-2">
                    {requests.map((r) => {
                        const st = requestStatus(r.status);
                        const ty = requestType(r.request_type);
                        return (
                            <Link
                                key={r.id}
                                href={`/privacy/requests/${r.id}`}
                                className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card/70 p-3.5 transition-colors hover:border-primary/40 hover:bg-muted/30"
                            >
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="font-semibold">{r.reference}</span>
                                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${PRIVACY_PILL[ty.tone]}`}>{ty.label}</span>
                                    </div>
                                    <div className="mt-0.5 text-xs text-muted-foreground">
                                        Received {fmtDate(r.received_at)} · Due{' '}
                                        <span className={r.is_overdue ? 'font-semibold text-status-critical' : undefined}>
                                            {fmtDate(r.due_date)}
                                            {r.is_overdue ? ' · overdue' : ''}
                                        </span>
                                        {r.assigned_to ? ` · ${r.assigned_to}` : ''}
                                    </div>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${PRIVACY_PILL[st.tone]}`}>{st.label}</span>
                                    <ExternalLink className="h-4 w-4 text-muted-foreground" />
                                </div>
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
