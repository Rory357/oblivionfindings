/* First-aid treatments tab — read-only client-profile panel. Surfaces the first-aid
 * records linked to this client (first_aid_records.client_id), mirroring IncidentsTab:
 * a stat strip + outcome-toned treatment cards that deep-link to the register's detail
 * modal. Recording/editing stays in the H&S First Aid register (need-to-know + perms). */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatDateTimeLong } from '@/lib/datetime';
import { TONE_BG } from '@/pages/health-safety/components/register-row-kit';
import { injuryLabel, outcomeLabel, outcomeTone } from '@/pages/health-safety/first-aid/options';
import { Link } from '@inertiajs/react';
import { Ambulance, HeartPulse } from 'lucide-react';

/* eslint-disable @typescript-eslint/no-explicit-any -- raw Eloquent rows from the profile
 * controller, matching the sibling IncidentsTab contract. */
export function FirstAidTab({ records }: { records: any[] }) {
    const last30 = records.filter(
        (r) => r.treatment_date && Date.now() - new Date(r.treatment_date).getTime() < 30 * 86400000,
    ).length;
    const ambulance = records.filter((r) => r.ambulance_called).length;
    const hospital = records.filter((r) => r.treatment_outcome === 'sent_to_hospital').length;

    return (
        <div className="space-y-4">
            {/* Stat strip */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {(
                    [
                        ['Treatments', records.length, 'text-status-info'],
                        ['Last 30 days', last30, 'text-status-info'],
                        ['Ambulance', ambulance, ambulance > 0 ? 'text-status-critical' : 'text-muted-foreground'],
                        ['To hospital', hospital, hospital > 0 ? 'text-status-critical' : 'text-muted-foreground'],
                    ] as [string, number, string][]
                ).map(([label, value, tone]) => (
                    /* eslint-disable-next-line no-restricted-syntax -- MiniStat tile per the profile pattern language */
                    <div key={label} className="rounded-xl border bg-card px-4 py-3">
                        <div className={`text-xl font-bold ${tone}`}>{value}</div>
                        <div className="text-xs text-muted-foreground">{label}</div>
                    </div>
                ))}
            </div>

            {/* Treatment cards */}
            {records.length ? (
                <div className="space-y-3">
                    {records.map((r: any) => {
                        const tone = outcomeTone(String(r.treatment_outcome ?? ''));
                        return (
                            <Card key={r.id} className="p-4">
                                <div className="flex items-start gap-3">
                                    <span className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${TONE_BG[tone]}`}>
                                        <HeartPulse className="h-[17px] w-[17px]" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-semibold">
                                                {injuryLabel(String(r.injury_illness_type ?? ''))}
                                                {r.body_part ? <span className="font-normal text-muted-foreground"> · {r.body_part}</span> : null}
                                            </span>
                                            <Badge className={`border-0 ${TONE_BG[tone]}`}>{outcomeLabel(String(r.treatment_outcome ?? ''))}</Badge>
                                            {r.ambulance_called ? (
                                                <Badge className="border-0 bg-status-critical-bg text-status-critical">
                                                    <Ambulance className="mr-1 h-3 w-3" /> Ambulance
                                                </Badge>
                                            ) : null}
                                            <span className="ml-auto text-[11px] text-muted-foreground">
                                                {r.treatment_date ? formatDateTimeLong(r.treatment_date) : ''}
                                                {r.firstAider?.name ? ` · ${r.firstAider.name}` : ''}
                                            </span>
                                        </div>
                                        {r.treatment_given ? (
                                            <p className="mt-1.5 text-sm leading-relaxed text-foreground/90">{r.treatment_given}</p>
                                        ) : null}
                                        {r.site?.name ? <p className="mt-1 text-xs text-muted-foreground">{r.site.name}</p> : null}
                                        <div className="mt-2">
                                            <Button size="sm" variant="outline" asChild>
                                                <Link href={`/health-safety/first-aid?record=${r.id}`}>Open record</Link>
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </Card>
                        );
                    })}
                </div>
            ) : (
                <Card className="border-dashed">
                    <CardContent className="flex flex-col items-center justify-center py-12">
                        <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            <HeartPulse className="h-[22px] w-[22px]" />
                        </div>
                        <p className="text-sm font-medium">No first-aid treatments recorded</p>
                        <p className="mt-1 max-w-xs text-center text-xs text-muted-foreground">
                            First-aid treatments for this client appear here when recorded in the Health &amp; Safety register.
                        </p>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
