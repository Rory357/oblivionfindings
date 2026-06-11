/* MAR tab — extracted from show.tsx in the client-profile redesign (and to
 * keep the page below the Babel deopt threshold). Same surface: header with
 * the record-dose workflow, allergy/alert banners, eMAR summary stats, links
 * into the eMAR module, and the scheduled / PRN / ceased medication lists. */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    ClipboardList,
    Pill,
    Plus,
    Shield,
} from 'lucide-react';

export type MarTabProps = {
    clientId: number;
    clientFirstName: string;
    siteName?: string | null;
    medications: any[];
    allergies: string[];
    emarSummary: {
        active_medications_count?: number;
        last_administration?: string | null;
        pending_alerts_count?: number;
        next_review_date?: string | null;
    } | null;
    onRecordDose: (medicationId?: number) => void;
};

export function MarTab({
    clientId,
    clientFirstName,
    siteName,
    medications,
    allergies,
    emarSummary,
    onRecordDose,
}: MarTabProps) {
    const meds = medications ?? [];
    const activeMeds = meds.filter((m: any) => m.active !== false);
    const ceasedMeds = meds.filter((m: any) => m.active === false);
    const prnMeds = activeMeds.filter((m: any) => m.is_prn);
    const scheduledMeds = activeMeds.filter((m: any) => !m.is_prn);
    const controlledMeds = activeMeds.filter((m: any) => m.controlled_drug);
    const stockedMeds = activeMeds.filter((m: any) => m.stock);
    const hasAllergies = allergies.length > 0;

    return (
        <div className="space-y-4">
            {/* MAR header + record-dose workflow */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-primary">
                        <Pill className="h-[19px] w-[19px]" />
                    </span>
                    <div>
                        <h2 className="text-lg leading-tight font-semibold">
                            Medication administration
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {activeMeds.length} active medication
                            {activeMeds.length === 1 ? '' : 's'}
                            {siteName ? ` · ${siteName}` : ''}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <Button variant="outline" asChild>
                        <Link href={`/operations/clients/${clientId}/mar`}>
                            Full MAR chart
                        </Link>
                    </Button>
                    <Button
                        onClick={() => onRecordDose()}
                        data-test="mar-record-dose"
                    >
                        <Plus className="mr-1.5 h-4 w-4" />
                        Record dose
                    </Button>
                </div>
            </div>

            {/* Allergy Banner */}
            {hasAllergies && (
                <div className="flex items-center gap-3 rounded-xl border-2 border-status-critical/30 bg-status-critical-bg p-4">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-status-critical-bg text-status-critical">
                        <AlertTriangle className="h-5 w-5" />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm font-semibold text-status-critical">
                            Allergies
                        </p>
                        <div className="mt-1 flex flex-wrap gap-1.5">
                            {allergies.map((a: string) => (
                                <Badge
                                    key={a}
                                    className="border-0 bg-status-critical-bg text-xs font-semibold text-status-critical"
                                >
                                    {a}
                                </Badge>
                            ))}
                        </div>
                    </div>
                </div>
            )}

            {/* Alerts Banner */}
            {emarSummary && (emarSummary.pending_alerts_count ?? 0) > 0 && (
                <div className="flex items-center gap-3 rounded-xl border-2 border-status-warning/30 bg-status-warning-bg p-4">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                        <AlertTriangle className="h-5 w-5" />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm font-semibold text-status-warning">
                            {emarSummary.pending_alerts_count} Active Medication
                            Alert
                            {emarSummary.pending_alerts_count !== 1 ? 's' : ''}
                        </p>
                        <p className="text-xs text-status-warning">
                            Review alerts in the full eMAR dashboard.
                        </p>
                    </div>
                    <Button
                        size="sm"
                        className="bg-status-warning text-primary-foreground hover:bg-status-warning"
                        asChild
                    >
                        <Link href={`/operations/clients/${clientId}/mar`}>
                            Review
                        </Link>
                    </Button>
                </div>
            )}

            {/* Stats */}
            {emarSummary && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border bg-status-info-bg p-4 text-center">
                        <div className="text-3xl font-bold text-status-info">
                            {emarSummary.active_medications_count}
                        </div>
                        <div className="text-[10px] tracking-wider text-status-info uppercase">
                            Active Medications
                        </div>
                    </div>
                    <div className="rounded-xl border bg-status-success-bg p-4 text-center">
                        <div className="text-sm font-bold text-status-success">
                            {emarSummary.last_administration
                                ? new Date(
                                      emarSummary.last_administration,
                                  ).toLocaleDateString('en-NZ', {
                                      day: 'numeric',
                                      month: 'short',
                                      hour: '2-digit',
                                      minute: '2-digit',
                                  })
                                : '—'}
                        </div>
                        <div className="text-[10px] tracking-wider text-status-success uppercase">
                            Last Administration
                        </div>
                    </div>
                    <div
                        className={`rounded-xl border p-4 text-center ${controlledMeds.length > 0 ? 'bg-status-critical-bg' : ''}`}
                    >
                        <div
                            className={`text-3xl font-bold ${controlledMeds.length > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
                        >
                            {controlledMeds.length}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Controlled Drugs
                        </div>
                    </div>
                    <div className="rounded-xl border bg-primary/10 p-4 text-center">
                        <div className="text-sm font-bold text-primary">
                            {emarSummary.next_review_date
                                ? new Date(
                                      emarSummary.next_review_date,
                                  ).toLocaleDateString('en-NZ', {
                                      day: 'numeric',
                                      month: 'short',
                                      year: 'numeric',
                                  })
                                : 'Not scheduled'}
                        </div>
                        <div className="text-[10px] tracking-wider text-primary uppercase">
                            Next Review
                        </div>
                    </div>
                </div>
            )}

            {/* Action Buttons */}
            <div className="flex flex-wrap gap-2">
                <Button
                    className="gap-1.5 bg-status-info hover:bg-status-info"
                    asChild
                >
                    <Link href={`/operations/clients/${clientId}/mar`}>
                        <Pill className="h-3.5 w-3.5" />
                        Daily MAR
                    </Link>
                </Button>
                <Button variant="outline" className="gap-1.5" asChild>
                    <Link href={`/emar/mar?client_id=${clientId}`}>
                        <ClipboardList className="h-3.5 w-3.5" />
                        eMAR Dashboard
                    </Link>
                </Button>
                <Button variant="outline" className="gap-1.5" asChild>
                    <Link href={`/emar/controlled?client_id=${clientId}`}>
                        <Shield className="h-3.5 w-3.5" />
                        Controlled Drugs
                    </Link>
                </Button>
                <Button variant="outline" className="gap-1.5" asChild>
                    <Link href={`/emar/reviews?client_id=${clientId}`}>
                        <BookOpen className="h-3.5 w-3.5" />
                        Reviews
                    </Link>
                </Button>
            </div>

            {stockedMeds.length > 0 && (
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <ClipboardList className="h-4 w-4 text-primary" />
                            Stock
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {stockedMeds.map((m: any) => {
                            const stock = m.stock ?? {};
                            return (
                                <div
                                    key={m.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                                >
                                    <div>
                                        <p className="text-sm font-semibold">
                                            {m.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {stock.on_hand ?? '-'}{' '}
                                            {stock.unit ?? 'doses'} on hand
                                            {stock.reorder_threshold != null
                                                ? ` · reorder at ${stock.reorder_threshold}`
                                                : ''}
                                        </p>
                                        {stock.last_counted_at ? (
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                Last counted{' '}
                                                {new Date(
                                                    stock.last_counted_at,
                                                ).toLocaleDateString('en-NZ', {
                                                    day: 'numeric',
                                                    month: 'short',
                                                })}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {m.controlled_drug ? (
                                            <Badge className="border-0 bg-status-critical-bg text-status-critical">
                                                Controlled
                                            </Badge>
                                        ) : null}
                                        {stock.is_low ? (
                                            <Badge className="border-0 bg-status-warning-bg text-status-warning">
                                                Reorder
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="outline"
                                                className="text-status-success"
                                            >
                                                In stock
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>
            )}

            {/* Scheduled Medications */}
            {scheduledMeds.length > 0 && (
                <Card className="overflow-hidden">
                    <div className="bg-gradient-to-r from-status-info to-primary px-5 py-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-sm font-semibold text-primary-foreground">
                                    Scheduled Medications
                                </h3>
                                <p className="text-xs text-primary-foreground/70">
                                    {scheduledMeds.length} medication
                                    {scheduledMeds.length !== 1 ? 's' : ''} on
                                    the regular chart
                                </p>
                            </div>
                        </div>
                    </div>
                    <CardContent className="p-0">
                        <div className="divide-y">
                            {scheduledMeds.map((m: any) => (
                                <div
                                    key={m.id}
                                    className="flex items-center gap-4 px-5 py-3.5 transition-colors hover:bg-muted/30"
                                >
                                    <div
                                        className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${m.controlled_drug ? 'bg-status-critical-bg text-status-critical' : 'bg-status-info-bg text-status-info'}`}
                                    >
                                        <Pill className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-semibold">
                                                {m.name}
                                            </span>
                                            {m.controlled_drug && (
                                                <Badge className="gap-0.5 border-0 bg-status-critical-bg text-[9px] text-status-critical">
                                                    <Shield className="h-2.5 w-2.5" />
                                                    Controlled
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                            {m.dosage && (
                                                <span className="font-medium text-foreground/70">
                                                    {m.dosage}
                                                </span>
                                            )}
                                            {m.route && <span>{m.route}</span>}
                                            {m.form && <span>{m.form}</span>}
                                            {m.frequency && (
                                                <span className="text-status-info">
                                                    {m.frequency}
                                                </span>
                                            )}
                                        </div>
                                        {m.instructions && (
                                            <p className="mt-1 line-clamp-1 text-xs text-muted-foreground">
                                                {m.instructions}
                                            </p>
                                        )}
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="shrink-0"
                                        onClick={() => onRecordDose(m.id)}
                                    >
                                        Sign
                                    </Button>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* PRN Medications */}
            {prnMeds.length > 0 && (
                <Card className="overflow-hidden">
                    <div className="bg-primary px-5 py-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-sm font-semibold text-primary-foreground">
                                    PRN (As Needed)
                                </h3>
                                <p className="text-xs text-primary-foreground/70">
                                    {prnMeds.length} medication
                                    {prnMeds.length !== 1 ? 's' : ''} available
                                    as needed
                                </p>
                            </div>
                        </div>
                    </div>
                    <CardContent className="p-0">
                        <div className="divide-y">
                            {prnMeds.map((m: any) => (
                                <div
                                    key={m.id}
                                    className="flex items-center gap-4 px-5 py-3.5 transition-colors hover:bg-muted/30"
                                >
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                        <Pill className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-semibold">
                                                {m.name}
                                            </span>
                                            <Badge className="border-0 bg-primary/10 text-[9px] text-primary">
                                                PRN
                                            </Badge>
                                            {m.controlled_drug && (
                                                <Badge className="gap-0.5 border-0 bg-status-critical-bg text-[9px] text-status-critical">
                                                    <Shield className="h-2.5 w-2.5" />
                                                    Controlled
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                            {m.dosage && (
                                                <span className="font-medium text-foreground/70">
                                                    {m.dosage}
                                                </span>
                                            )}
                                            {m.route && <span>{m.route}</span>}
                                            {m.form && <span>{m.form}</span>}
                                        </div>
                                        {m.prn_reason && (
                                            <p className="mt-1 text-xs text-primary">
                                                Indication: {m.prn_reason}
                                            </p>
                                        )}
                                        {m.instructions && (
                                            <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                                {m.instructions}
                                            </p>
                                        )}
                                    </div>
                                    <Button
                                        size="sm"
                                        className="shrink-0"
                                        onClick={() => onRecordDose(m.id)}
                                    >
                                        Give PRN
                                    </Button>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Ceased Medications */}
            {ceasedMeds.length > 0 && (
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm text-muted-foreground">
                            Ceased Medications ({ceasedMeds.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y">
                            {ceasedMeds.map((m: any) => (
                                <div
                                    key={m.id}
                                    className="flex items-center gap-4 px-5 py-2.5 opacity-50"
                                >
                                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        <Pill className="h-4 w-4" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium line-through">
                                                {m.name}
                                            </span>
                                            <Badge className="border-0 bg-muted text-[9px] text-muted-foreground">
                                                Ceased
                                            </Badge>
                                        </div>
                                        <div className="mt-0.5 text-xs text-muted-foreground">
                                            {[m.dosage, m.route, m.form]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Empty state */}
            {meds.length === 0 && (
                <Card className="border-dashed">
                    <CardContent className="flex flex-col items-center justify-center py-16">
                        <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-status-info-bg">
                            <Pill className="h-8 w-8 text-status-info" />
                        </div>
                        <p className="font-medium">No Medications</p>
                        <p className="mt-1 max-w-sm text-center text-sm text-muted-foreground">
                            No medications recorded for {clientFirstName}. Add
                            medications through the medical tab or eMAR system.
                        </p>
                        <Button size="sm" className="mt-4" asChild>
                            <Link
                                href={`/emar/medications?client_id=${clientId}`}
                            >
                                Add Medication
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
