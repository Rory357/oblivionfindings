/* Read-only PRN administration detail — opened from a register row (click or
 * the right-click "View details" action). Built on the Add-Client WizardShell
 * chrome (rail + sectioned panes + footer Options bar) so it matches every
 * other popup workflow; the primary actions open the relevant wizard in place
 * rather than navigating off-page. Colours are semantic tokens throughout. */
import { Button } from '@/components/ui/button';
import { InfoCard } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    type WizardStep,
} from '@/components/wizard/shell';
import type { PrnMedication } from '@/pages/meds/today/types';
import { router } from '@inertiajs/react';
import {
    Activity,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Flag,
    Pill,
    Printer,
    Stethoscope,
    User,
} from 'lucide-react';
import { useState } from 'react';

/** Effectiveness sub-record carried alongside a register administration. */
export type PrnEffectivenessDetail = {
    effectiveness: string | null;
    label: string | null;
    review_minutes_after: number | null;
    observations: string | null;
    escalation_needed: boolean;
    escalation_action: string | null;
    reviewed_by: string | null;
    reviewed_at: string | null;
    reviewed_label: string | null;
};

/** One PRN-given administration row (the `prn()` register payload). */
export type PrnAdministration = {
    id: number;
    client_id: number;
    client_name: string;
    client_room: string | null;
    client_site: string | null;
    client_medication_id: number;
    medication_name: string | null;
    route: string | null;
    prescribed_dose: string | null;
    controlled_drug: boolean;
    dose_given: string | null;
    reason: string | null;
    indication: string | null;
    notes: string | null;
    status: string;
    administered_at: string | null;
    given_time: string | null;
    given_date: string | null;
    given_by: string | null;
    mar_url: string | null;
    baseline: {
        blood_glucose_level?: number | string | null;
        pulse_bpm?: number | null;
        blood_pressure_systolic?: number | null;
        blood_pressure_diastolic?: number | null;
        insulin_units_given?: number | string | null;
    };
    effectiveness: string | null;
    effectiveness_label: string | null;
    effectiveness_detail: PrnEffectivenessDetail | null;
};

const SECTIONS: WizardStep[] = [
    {
        key: 'admin',
        label: 'Administration',
        blurb: 'Dose, indication & baseline obs',
        icon: Pill,
    },
    {
        key: 'review',
        label: 'Review & audit',
        blurb: 'Effectiveness & trail',
        icon: FileText,
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

export function PrnDetailDialog({
    admin,
    med,
    onClose,
    onRecordEffectiveness,
    onReRecordDose,
}: {
    admin: PrnAdministration;
    /** Matching live PRN med for the today-count chip (given/max). */
    med: PrnMedication | undefined;
    onClose: () => void;
    /** Open the effectiveness wizard for this administration (in place). */
    onRecordEffectiveness: () => void;
    /** Open the Record-PRN wizard pre-filled to this med (in place). */
    onReRecordDose: () => void;
}) {
    const [section, setSection] = useState(0);
    const eff = admin.effectiveness_detail;
    const reviewDue = !eff;
    const baseline = admin.baseline ?? {};
    const hasBaseline = Object.values(baseline).some(
        (v) => v != null && v !== '',
    );
    const givenAt = [admin.given_time, admin.given_date]
        .filter(Boolean)
        .join(' · ');
    const todayCount =
        med && med.max_per_day != null
            ? `${med.given_last_24h} of ${med.max_per_day}`
            : null;

    return (
        <WizardShell
            open
            onClose={onClose}
            title="PRN administration detail"
            description="Read-only detail of an as-needed medication administration."
            railIcon={ClipboardCheck}
            railTitle={admin.client_name}
            railSub={
                [admin.client_room, admin.client_site]
                    .filter(Boolean)
                    .join(' · ') || 'PRN administration'
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
                    {reviewDue ? (
                        <Button type="button" onClick={onRecordEffectiveness}>
                            <Stethoscope className="h-4 w-4" /> Record
                            effectiveness
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onRecordEffectiveness}
                        >
                            <Stethoscope className="h-4 w-4" /> Re-record
                            effectiveness
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onReRecordDose}
                    >
                        <Pill className="h-4 w-4" /> Re-record dose
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() =>
                            router.visit(
                                `/operations/clients/${admin.client_id}?tab=mar`,
                            )
                        }
                    >
                        <User className="h-4 w-4" /> Client
                    </Button>
                    {admin.mar_url ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => router.visit(admin.mar_url!)}
                        >
                            <FileText className="h-4 w-4" /> MAR
                        </Button>
                    ) : null}
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
                    <ReviewCard icon={User} title="Resident" span>
                        <ReviewRow label="Name" value={admin.client_name} />
                        <ReviewRow label="Room" value={admin.client_room} />
                        <ReviewRow label="Site" value={admin.client_site} />
                    </ReviewCard>
                    <ReviewCard icon={Pill} title="Medication">
                        <ReviewRow
                            label="Name"
                            value={
                                <span className="inline-flex items-center gap-1.5">
                                    {admin.medication_name ?? '—'}
                                    {admin.controlled_drug ? (
                                        <span className="rounded bg-status-critical-bg px-1 py-0.5 text-[9px] font-bold text-status-critical">
                                            CD
                                        </span>
                                    ) : null}
                                </span>
                            }
                        />
                        <ReviewRow label="Route" value={admin.route} />
                        <ReviewRow
                            label="Dose given"
                            value={admin.dose_given}
                        />
                        <ReviewRow
                            label="Prescribed"
                            value={admin.prescribed_dose}
                        />
                        <ReviewRow
                            label="Indication"
                            value={admin.reason ?? admin.indication}
                        />
                    </ReviewCard>
                    <ReviewCard icon={ClipboardCheck} title="This dose">
                        <ReviewRow label="Time given" value={givenAt} />
                        <ReviewRow label="Given by" value={admin.given_by} />
                        <ReviewRow label="Today's count" value={todayCount} />
                    </ReviewCard>
                    {hasBaseline ? (
                        <ReviewCard
                            icon={Activity}
                            title="Baseline observations"
                            span
                        >
                            {baseline.pulse_bpm != null ? (
                                <ReviewRow
                                    label="Pulse"
                                    value={`${baseline.pulse_bpm} bpm`}
                                />
                            ) : null}
                            {baseline.blood_pressure_systolic != null &&
                            baseline.blood_pressure_diastolic != null ? (
                                <ReviewRow
                                    label="Blood pressure"
                                    value={`${baseline.blood_pressure_systolic}/${baseline.blood_pressure_diastolic} mmHg`}
                                />
                            ) : null}
                            {baseline.blood_glucose_level != null ? (
                                <ReviewRow
                                    label="Blood glucose"
                                    value={`${baseline.blood_glucose_level} mmol/L`}
                                />
                            ) : null}
                            {baseline.insulin_units_given != null ? (
                                <ReviewRow
                                    label="Insulin"
                                    value={`${baseline.insulin_units_given} units`}
                                />
                            ) : null}
                        </ReviewCard>
                    ) : null}
                    {admin.notes ? (
                        <ReviewCard
                            icon={FileText}
                            title="Observations at administration"
                            span
                        >
                            <p className="text-[13px] leading-relaxed text-muted-foreground">
                                {admin.notes}
                            </p>
                        </ReviewCard>
                    ) : null}
                </div>
            ) : (
                <div className="grid gap-4">
                    {eff ? (
                        <>
                            <ReviewCard
                                icon={CheckCircle2}
                                title="Effectiveness review"
                            >
                                <ReviewRow
                                    label="Outcome"
                                    value={
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${effPillClass(eff.effectiveness)}`}
                                        >
                                            {eff.label ?? '—'}
                                        </span>
                                    }
                                />
                                <ReviewRow
                                    label="Reviewed after"
                                    value={
                                        eff.review_minutes_after != null
                                            ? `${eff.review_minutes_after} min`
                                            : null
                                    }
                                />
                                <ReviewRow
                                    label="Observations"
                                    value={eff.observations}
                                />
                                <ReviewRow
                                    label="Escalation"
                                    value={
                                        eff.escalation_needed ? (
                                            <span className="inline-flex items-center gap-1 text-status-critical">
                                                <Flag className="h-3 w-3" />{' '}
                                                {eff.escalation_action ??
                                                    'Raised'}
                                            </span>
                                        ) : (
                                            'Not needed'
                                        )
                                    }
                                />
                            </ReviewCard>
                            <ReviewCard icon={FileText} title="Audit trail">
                                <ReviewRow
                                    label="Recorded"
                                    value={`${admin.given_by ?? '—'}${givenAt ? ` · ${givenAt}` : ''}`}
                                />
                                <ReviewRow
                                    label="Reviewed"
                                    value={`${eff.reviewed_by ?? '—'}${eff.reviewed_label ? ` · ${eff.reviewed_label}` : ''}`}
                                />
                            </ReviewCard>
                        </>
                    ) : (
                        <>
                            <InfoCard icon={Stethoscope} tone="warn">
                                Effectiveness review still due — record the
                                outcome of this dose to close the loop.
                            </InfoCard>
                            <ReviewCard icon={FileText} title="Audit trail">
                                <ReviewRow
                                    label="Recorded"
                                    value={`${admin.given_by ?? '—'}${givenAt ? ` · ${givenAt}` : ''}`}
                                />
                                <ReviewRow label="Reviewed" value={null} />
                            </ReviewCard>
                        </>
                    )}
                </div>
            )}
        </WizardShell>
    );
}

export default PrnDetailDialog;
