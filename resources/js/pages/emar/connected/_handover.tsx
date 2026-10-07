import { SettingsNotice } from '@/components/settings/settings-notice';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { ClipboardCheck } from 'lucide-react';
import type { Transfer } from './_types';

export function HandoverFacts({ transfer }: { transfer: Transfer }) {
    const snapshot = transfer.snapshot;
    return (
        <div className="space-y-4">
            <ReviewCard icon={ClipboardCheck} title="Source person and capture">
                <ReviewRow
                    label="Person"
                    value={snapshot.person?.name ?? transfer.client_name}
                />
                <ReviewRow
                    label="Date of birth"
                    value={
                        snapshot.person?.date_of_birth
                            ? formatDateOnly(snapshot.person.date_of_birth)
                            : 'Not recorded'
                    }
                />
                <ReviewRow
                    label="NHI"
                    value={snapshot.person?.nhi_number || 'Not recorded'}
                />
                <ReviewRow
                    label="Captured"
                    value={
                        snapshot.captured_at
                            ? formatDateTime(snapshot.captured_at)
                            : 'Not recorded'
                    }
                />
            </ReviewCard>
            {transfer.direction === 'incoming' && (
                <SettingsNotice>
                    Incoming medication and allergy facts are unverified. Check
                    each fact against the source before reconciliation.
                </SettingsNotice>
            )}
            <ReviewCard icon={ClipboardCheck} title="Recorded medicines">
                {snapshot.medications?.length ? (
                    snapshot.medications.map((m, i) => {
                        const p = m.prescription;
                        return (
                            <details
                                key={m.id ?? i}
                                className="rounded-lg border border-border p-3 open:space-y-3"
                            >
                                <summary className="cursor-pointer text-sm font-semibold focus-visible:outline-2 focus-visible:outline-ring">
                                    {p.name} · {p.dosage} · {p.route}
                                </summary>
                                <ReviewRow
                                    label="State / check"
                                    value={
                                        transfer.direction === 'incoming'
                                            ? 'Unverified provider facts'
                                            : [
                                                  m.state,
                                                  m.approval_status,
                                                  m.version != null
                                                      ? 'Version ' + m.version
                                                      : '',
                                              ]
                                                  .filter(Boolean)
                                                  .join(' · ')
                                    }
                                />
                                <ReviewRow
                                    label="Schedule"
                                    value={
                                        p.is_prn
                                            ? 'As needed'
                                            : [
                                                  p.frequency,
                                                  (p.dose_times ?? []).join(
                                                      ', ',
                                                  ),
                                              ]
                                                  .filter(Boolean)
                                                  .join(' · ')
                                    }
                                />
                                <ReviewRow
                                    label="Instructions"
                                    value={p.instructions || 'Not recorded'}
                                />
                                <ReviewRow
                                    label="Form / indication"
                                    value={
                                        [p.form, p.indication]
                                            .filter(Boolean)
                                            .join(' · ') || 'Not recorded'
                                    }
                                />
                                <ReviewRow
                                    label="Dose quantity"
                                    value={
                                        p.dose_amount != null
                                            ? String(p.dose_amount) +
                                              ' ' +
                                              (p.dose_unit ?? '')
                                            : 'Not recorded'
                                    }
                                />
                                <ReviewRow
                                    label="Start / end date"
                                    value={
                                        (p.start_date
                                            ? formatDateOnly(p.start_date)
                                            : 'Start not recorded') +
                                        ' · ' +
                                        (p.end_date
                                            ? formatDateOnly(p.end_date)
                                            : 'No end date recorded')
                                    }
                                />
                                {p.is_prn && (
                                    <>
                                        <ReviewRow
                                            label="As-needed reason"
                                            value={
                                                p.prn_reason || 'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Maximum in 24 hours"
                                            value={
                                                p.max_per_day ?? 'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Minimum interval (hours)"
                                            value={
                                                p.min_hours_between_doses ??
                                                'Not recorded'
                                            }
                                        />
                                    </>
                                )}
                                <ReviewRow
                                    label="Prescriber / pharmacy"
                                    value={
                                        [p.prescriber, p.pharmacy]
                                            .filter(Boolean)
                                            .join(' · ') || 'Not recorded'
                                    }
                                />
                                <ReviewRow
                                    label="Recorded safety flags"
                                    value={
                                        [
                                            p.controlled_drug &&
                                                'Controlled medicine',
                                            p.high_risk && 'High risk',
                                            p.witness_required &&
                                                'Second person required',
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') || 'None recorded'
                                    }
                                />
                                <ReviewRow
                                    label="Last recorded given dose"
                                    value={
                                        m.last_dose
                                            ? [
                                                  m.last_dose.dose_given ||
                                                      'Amount not recorded',
                                                  m.last_dose.given_at
                                                      ? formatDateTime(
                                                            m.last_dose
                                                                .given_at,
                                                        )
                                                      : 'Time not recorded',
                                              ].join(' · ')
                                            : 'No given dose recorded in this snapshot'
                                    }
                                />
                                <ReviewRow
                                    label="Next recorded due time"
                                    value={
                                        m.next_due_at
                                            ? formatDateTime(m.next_due_at)
                                            : 'No future due time recorded in this snapshot'
                                    }
                                />
                            </details>
                        );
                    })
                ) : (
                    <p className="text-caption">
                        No medication entries in this snapshot. Confirm against
                        the source chart.
                    </p>
                )}
            </ReviewCard>
            <ReviewCard icon={ClipboardCheck} title="Allergy evidence">
                {snapshot.allergies?.length ? (
                    snapshot.allergies.map((a, i) => (
                        <ReviewRow
                            key={i}
                            label={a.allergen}
                            value={
                                [a.reaction, a.severity, a.notes]
                                    .filter(Boolean)
                                    .join(' · ') || 'Details not recorded'
                            }
                        />
                    ))
                ) : (
                    <p className="text-caption">
                        No allergy entries in this snapshot. This is not
                        confirmation of no allergies.
                    </p>
                )}
            </ReviewCard>
            {snapshot.limitations?.map((note, i) => (
                <SettingsNotice key={i}>{note}</SettingsNotice>
            ))}
        </div>
    );
}
