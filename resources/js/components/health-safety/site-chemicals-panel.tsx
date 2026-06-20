/* eslint-disable no-restricted-syntax -- Read-mostly site panel: bespoke
 * compliance-snapshot strip + quantity bars + chips, semantic tokens only.
 * The substance master record is managed from the Chemical register; each row
 * deep-links there so the two surfaces never diverge. */
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { SiteAddStorageDialog, type StorageSubstanceOption } from '@/components/health-safety/site-chemical-storage-dialog';
import { FlagBadge } from '@/pages/health-safety/components/register-row-kit';
import { SDS_STATE_META, type SdsState } from '@/pages/health-safety/substances/constants';
import { formatDate } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CheckCircle2, FlaskConical, MapPin, Plus, ShieldAlert } from 'lucide-react';
import { useState } from 'react';

export type SiteChemicalRow = {
    id: number;
    substance: { id: number; name: string; common_name: string | null; is_controlled_substance: boolean } | null;
    location_description: string;
    current_quantity: number | null;
    quantity_unit: string | null;
    maximum_quantity: number | null;
    container_type: string | null;
    properly_labelled: boolean;
    segregation_compliant: boolean;
    last_audit_date: string | null;
    sds_state: SdsState;
};

export type SiteChemicalsData = {
    rows: SiteChemicalRow[];
    summary: { count: number; controlled: number; sds_to_action: number; segregation_gaps: number };
    substances: StorageSubstanceOption[];
    can_add: boolean;
};

function SdsBadge({ state }: { state: SdsState }) {
    const meta = SDS_STATE_META[state];
    return (
        <FlagBadge icon={meta.icon} tone={meta.tone} title={meta.label}>
            {meta.label}
        </FlagBadge>
    );
}

function QuantityBar({ current, max }: { current: number | null; max: number | null }) {
    if (current == null || max == null || max <= 0) return null;
    const pct = Math.min(100, Math.round((current / max) * 100));
    const tone = pct >= 90 ? 'bg-status-critical' : pct >= 70 ? 'bg-status-warning' : 'bg-primary';
    return (
        <div className="mt-1 h-1.5 w-24 overflow-hidden rounded-full bg-muted">
            <div className={`h-full rounded-full ${tone}`} style={{ width: `${pct}%` }} />
        </div>
    );
}

function CompChip({ ok, label }: { ok: boolean; label: string }) {
    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${ok ? 'bg-status-success-bg text-status-success' : 'bg-status-warning-bg text-status-warning'}`}>
            {ok ? <CheckCircle2 className="h-3 w-3" /> : <AlertTriangle className="h-3 w-3" />} {label}
        </span>
    );
}

export function SiteChemicalsPanel({ data, siteId, siteName }: { data: SiteChemicalsData; siteId: number; siteName: string }) {
    const { rows, summary, substances } = data;
    const [addOpen, setAddOpen] = useState(false);

    return (
        <Card>
            <CardHeader className="gap-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
                            <FlaskConical className="h-5 w-5" />
                        </span>
                        <div>
                            <CardTitle className="flex items-center gap-2">
                                Chemicals stored here
                                <span className="text-sm font-normal text-muted-foreground">· {summary.count} substance{summary.count === 1 ? '' : 's'}</span>
                            </CardTitle>
                            <p className="mt-0.5 text-sm text-muted-foreground">Site-scoped storage. The substance master record lives in the Chemical register.</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {data.can_add ? (
                            <Button size="sm" variant="outline" onClick={() => setAddOpen(true)}>
                                <Plus className="mr-1.5 h-4 w-4" /> Add storage here
                            </Button>
                        ) : null}
                        <Button asChild size="sm">
                            <Link href="/health-safety/substances">
                                Open chemical register <ArrowRight className="ml-1.5 h-4 w-4" />
                            </Link>
                        </Button>
                    </div>
                </div>

                {summary.count > 0 ? (
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg bg-muted/40 px-3 py-2 text-xs">
                        <span className="font-semibold text-foreground">Compliance snapshot</span>
                        <span className="text-muted-foreground">{summary.count} substances</span>
                        <span className={summary.controlled > 0 ? 'text-status-warning' : 'text-muted-foreground'}>{summary.controlled} controlled</span>
                        <span className={summary.sds_to_action > 0 ? 'text-status-critical' : 'text-muted-foreground'}>{summary.sds_to_action} SDS to action</span>
                        <span className={summary.segregation_gaps > 0 ? 'text-status-critical' : 'text-muted-foreground'}>{summary.segregation_gaps} segregation gap{summary.segregation_gaps === 1 ? '' : 's'}</span>
                    </div>
                ) : null}
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-10 text-center">
                        <MapPin className="mb-2 h-8 w-8 text-muted-foreground/40" />
                        <p className="text-sm font-semibold text-foreground">No chemicals stored here</p>
                        <p className="mt-0.5 max-w-xs text-xs text-muted-foreground">Storage locations are added from each substance's record in the Chemical register.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                    <th className="px-3 py-2">Substance</th>
                                    <th className="px-3 py-2">Location</th>
                                    <th className="px-3 py-2">Quantity held</th>
                                    <th className="px-3 py-2">Container</th>
                                    <th className="px-3 py-2">SDS</th>
                                    <th className="px-3 py-2">Storage compliance</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.map((r) => (
                                    <tr
                                        key={r.id}
                                        tabIndex={r.substance ? 0 : -1}
                                        onClick={() => r.substance && router.visit(`/health-safety/substances?substance=${r.substance.id}`)}
                                        onKeyDown={(e) => {
                                            if (r.substance && (e.key === 'Enter' || e.key === ' ')) {
                                                e.preventDefault();
                                                router.visit(`/health-safety/substances?substance=${r.substance.id}`);
                                            }
                                        }}
                                        className={r.substance ? 'cursor-pointer transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none' : ''}
                                    >
                                        <td className="px-3 py-3 align-top">
                                            <div className="flex items-center gap-1.5">
                                                <span className="font-medium">{r.substance?.name ?? 'Unknown'}</span>
                                                {r.substance?.is_controlled_substance ? <ShieldAlert className="h-3.5 w-3.5 shrink-0 text-status-critical" aria-label="Controlled" /> : null}
                                            </div>
                                            {r.substance?.common_name ? <p className="text-xs text-muted-foreground">{r.substance.common_name}</p> : null}
                                        </td>
                                        <td className="px-3 py-3 align-top">
                                            <div className="text-sm">{r.location_description}</div>
                                            {r.last_audit_date ? <div className="text-[11px] text-muted-foreground">Audited {formatDate(r.last_audit_date)}</div> : null}
                                        </td>
                                        <td className="px-3 py-3 align-top">
                                            {r.current_quantity != null ? (
                                                <span className="text-sm font-medium">
                                                    {r.current_quantity}
                                                    {r.maximum_quantity != null ? ` / ${r.maximum_quantity}` : ''} {r.quantity_unit ?? ''}
                                                </span>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">—</span>
                                            )}
                                            <QuantityBar current={r.current_quantity} max={r.maximum_quantity} />
                                        </td>
                                        <td className="px-3 py-3 align-top text-sm text-muted-foreground">{r.container_type ?? '—'}</td>
                                        <td className="px-3 py-3 align-top">
                                            <SdsBadge state={r.sds_state} />
                                        </td>
                                        <td className="px-3 py-3 align-top">
                                            <div className="flex flex-wrap gap-1.5">
                                                <CompChip ok={r.properly_labelled} label="Labelled" />
                                                <CompChip ok={r.segregation_compliant} label="Segregated" />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <p className="mt-3 text-xs text-muted-foreground">
                            Storage locations are site-scoped but managed from each substance's record. Adding, editing or removing a location updates the register.
                        </p>
                    </div>
                )}
            </CardContent>

            {data.can_add ? (
                <SiteAddStorageDialog open={addOpen} onClose={() => setAddOpen(false)} siteId={siteId} siteName={siteName} substances={substances} />
            ) : null}
        </Card>
    );
}
