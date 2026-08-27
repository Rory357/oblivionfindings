/* eslint-disable no-restricted-syntax -- the limit gauge + dose-timeline rows
 * are bespoke bordered layout (not Card/Button); all colours are tokens. */
/* PRN near-limit drill-down — opened from a Near-limit card. Add-Client
 * WizardShell chrome (two read-only sections: Limit & status / Today's doses)
 * with a footer Options bar whose primary actions open the record/effectiveness
 * wizards in place. Mirrors the detail dialog's look. */
import { Button } from '@/components/ui/button';
import { InfoCard } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    type WizardStep,
} from '@/components/wizard/shell';
import type {
    ClientInfo,
    PrnFollowUp,
    PrnMedication,
} from '@/pages/meds/today/types';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    FileText,
    Flag,
    Gauge,
    Pill,
    Printer,
    Stethoscope,
    User,
} from 'lucide-react';
import { useState } from 'react';

const SECTIONS: WizardStep[] = [
    {
        key: 'limit',
        label: 'Limit & status',
        blurb: 'Daily cap & next dose',
        icon: Gauge,
    },
    {
        key: 'doses',
        label: "Today's doses",
        blurb: 'Last 24 hours',
        icon: Clock,
    },
];

function effPillClass(eff: string | null): string {
    if (eff === 'effective') return 'bg-status-success-bg text-status-success';
    if (eff === 'partially_effective')
        return 'bg-status-warning-bg text-status-warning';
    if (eff === 'not_effective')
        return 'bg-status-critical-bg text-status-critical';
    return 'bg-status-info-bg text-status-info';
}

export function PrnNearLimitDialog({
    med,
    client,
    onClose,
    onRecordDose,
    onRecordEffectiveness,
}: {
    med: PrnMedication;
    client: ClientInfo | undefined;
    onClose: () => void;
    /** Open the Record-PRN wizard pre-filled to this med (in place). */
    onRecordDose?: () => void;
    /** Open the effectiveness wizard for the most recent dose (in place). */
    onRecordEffectiveness?: (followUp: PrnFollowUp) => void;
}) {
    const [section, setSection] = useState(0);
    const doses = med.today_doses ?? [];
    const lastDose = doses.length ? doses[doses.length - 1] : null;
    const pct = med.max_per_day
        ? Math.min(
              100,
              Math.round((med.given_last_24h / med.max_per_day) * 100),
          )
        : 0;
    const incident = med.over_limit_incident ?? null;

    const lastFollowUp = (): PrnFollowUp | null =>
        lastDose
            ? {
                  administration_id: lastDose.id,
                  client_id: med.client_id,
                  medication_name: med.name,
                  is_controlled: med.is_controlled,
                  dose_given: lastDose.dose,
                  given_at: null,
                  given_time: lastDose.time,
                  check_at: null,
              }
            : null;

    return (
        <WizardShell
            open
            onClose={onClose}
            title="PRN near-limit detail"
            description="Daily-limit status and today's doses for an as-needed medication."
            railIcon={AlertTriangle}
            railTitle={med.name}
            railSub={
                [client?.name ?? med.client_name, client?.site_name]
                    .filter(Boolean)
                    .join(' · ') || 'Near daily limit'
            }
            steps={SECTIONS}
            stepIndex={section}
            onStepClick={setSection}
            pct={null}
            footerStart={
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                <>
                    {onRecordDose ? (
                        <Button type="button" onClick={onRecordDose}>
                            <Pill className="h-4 w-4" /> Record PRN dose
                        </Button>
                    ) : null}
                    {onRecordEffectiveness ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={!lastDose}
                            onClick={() => {
                                const fu = lastFollowUp();
                                if (fu) onRecordEffectiveness(fu);
                            }}
                        >
                            <Stethoscope className="h-4 w-4" /> Effectiveness
                        </Button>
                    ) : null}
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() =>
                            router.visit(
                                `/operations/clients/${med.client_id}?tab=mar`,
                            )
                        }
                    >
                        <User className="h-4 w-4" /> Client
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() =>
                            router.visit(`/emar/mar?client_id=${med.client_id}`)
                        }
                    >
                        <FileText className="h-4 w-4" /> MAR
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() =>
                            router.visit(`/clients/${med.client_id}/incidents`)
                        }
                    >
                        <Flag className="h-4 w-4" /> Notify / flag
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => window.print()}
                    >
                        <Printer className="h-4 w-4" /> Print
                    </Button>
                </>
            }
        >
            {section === 0 ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <ReviewCard icon={Pill} title="Medication">
                        <ReviewRow
                            label="Name"
                            value={
                                <span className="inline-flex items-center gap-1.5">
                                    {med.name}
                                    {med.is_controlled ? (
                                        <span className="rounded bg-status-critical-bg px-1 py-0.5 text-[9px] font-bold text-status-critical">
                                            CD
                                        </span>
                                    ) : null}
                                </span>
                            }
                        />
                        <ReviewRow label="Route" value={med.route} />
                        <ReviewRow label="Dose" value={med.dose} />
                        <ReviewRow label="Indication" value={med.prn_reason} />
                    </ReviewCard>
                    <ReviewCard icon={User} title="Resident">
                        <ReviewRow
                            label="Name"
                            value={client?.name ?? med.client_name}
                        />
                        <ReviewRow label="Site" value={client?.site_name} />
                    </ReviewCard>
                    <ReviewCard icon={Gauge} title="Daily limit" span>
                        <div className="mb-2.5 flex items-center justify-between">
                            <span
                                className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${med.over_limit ? 'bg-status-critical-bg text-status-critical' : 'bg-status-warning-bg text-status-warning'}`}
                            >
                                {med.over_limit
                                    ? 'At limit'
                                    : `${med.remaining_today ?? 0} left`}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {med.given_last_24h} of {med.max_per_day ?? '—'}{' '}
                                in 24h
                            </span>
                        </div>
                        <div className="mb-3 h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className={`h-full rounded-full ${med.over_limit ? 'bg-status-critical' : 'bg-status-warning'}`}
                                style={{ width: `${pct}%` }}
                            />
                        </div>
                        <ReviewRow
                            label="Max per day"
                            value={
                                med.max_per_day != null
                                    ? `${med.max_per_day}×`
                                    : null
                            }
                        />
                        <ReviewRow
                            label="Min hours between"
                            value={
                                med.min_hours_between != null
                                    ? `${med.min_hours_between} h`
                                    : null
                            }
                        />
                        <ReviewRow
                            label="Remaining today"
                            value={
                                med.remaining_today != null
                                    ? `${med.remaining_today}`
                                    : null
                            }
                        />
                        <ReviewRow
                            label="Last given"
                            value={med.last_given_label}
                        />
                        <ReviewRow
                            label="Next dose allowed"
                            value={
                                med.interval_blocked
                                    ? med.next_allowed_label
                                    : 'Now'
                            }
                        />
                    </ReviewCard>
                    {med.over_limit ? (
                        <InfoCard icon={AlertTriangle} tone="crit">
                            This PRN has reached its daily limit. Don&rsquo;t
                            give another dose without senior sign-off — a
                            further attempt raises an over-limit incident.
                        </InfoCard>
                    ) : (
                        <InfoCard icon={AlertTriangle} tone="warn">
                            Approaching the daily limit (
                            {med.remaining_today ?? 0} dose
                            {med.remaining_today === 1 ? '' : 's'} left). Check
                            the indication and minimum interval before giving
                            another.
                        </InfoCard>
                    )}
                    {incident ? (
                        <ReviewCard
                            icon={Flag}
                            title="Over-limit incident"
                            span
                        >
                            <ReviewRow label="Status" value={incident.status} />
                            <ReviewRow
                                label="Raised"
                                value={incident.occurred_label}
                            />
                            <div className="pt-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => router.visit(incident.url)}
                                >
                                    <Flag className="h-3.5 w-3.5" /> Open
                                    incident
                                </Button>
                            </div>
                        </ReviewCard>
                    ) : null}
                </div>
            ) : (
                <div className="grid gap-4">
                    {doses.length === 0 ? (
                        <div className="rounded-xl border border-border bg-card/70 px-5 py-10 text-center text-sm text-muted-foreground">
                            No doses recorded in the last 24 hours.
                        </div>
                    ) : (
                        <ol className="overflow-hidden rounded-xl border border-border">
                            {doses.map((d, i) => (
                                <li
                                    key={d.id}
                                    className={`flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 ${i > 0 ? 'border-t border-border' : ''}`}
                                >
                                    <span className="w-14 shrink-0 font-semibold tabular-nums">
                                        {d.time ?? '—'}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {d.date_label}
                                    </span>
                                    <span className="font-medium">
                                        {d.dose ?? 'Dose recorded'}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        by {d.given_by ?? '—'}
                                    </span>
                                    <span
                                        className={`ml-auto rounded-full px-2 py-0.5 text-[11px] font-semibold ${effPillClass(d.effectiveness)}`}
                                    >
                                        {d.effectiveness_label ?? 'Review due'}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            )}
        </WizardShell>
    );
}

export default PrnNearLimitDialog;
