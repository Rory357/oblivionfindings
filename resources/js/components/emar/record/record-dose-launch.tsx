import {
    cellKind,
    cellLabel,
    recordBlock,
} from '@/components/clients/profile/mar-day/dose-cell';
import type { MedicationDay } from '@/components/clients/profile/mar-day/types';
import { useDoseRecorder } from '@/components/emar/recording/use-dose-recorder';
import { PageHeaderPrimaryButton } from '@/components/page';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { Pill } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ReadingState } from './reading';
import { useRecordJson } from './use-record-json';

/** Header and hub use the same scheduled/PRN seam as the chart cells. */
export function RecordDoseLaunch({
    clientId,
    personName,
}: {
    clientId: number;
    personName: string;
}) {
    const [open, setOpen] = useState(false);
    return (
        <>
            <PageHeaderPrimaryButton icon={Pill} onClick={() => setOpen(true)}>
                Record a dose
            </PageHeaderPrimaryButton>
            {open && (
                <DosePicker
                    key={clientId}
                    clientId={clientId}
                    personName={personName}
                    onClose={() => setOpen(false)}
                />
            )}
        </>
    );
}

function DosePicker({
    clientId,
    personName,
    onClose,
}: {
    clientId: number;
    personName: string;
    onClose: () => void;
}) {
    const { data, load, reload } = useRecordJson<MedicationDay>(
        `/emar/clients/${clientId}/day`,
    );
    const [choosing, setChoosing] = useState(true);
    const context = useMemo(
        () =>
            data?.recorder?.client
                ? {
                      client: data.recorder.client,
                      date: data.date,
                      witnesses: data.recorder.witnesses,
                      notGivenReasons: data.recorder.not_given_reasons,
                      signedAs: data.recorder.signed_as,
                      prnMedications: data.prn.rows,
                  }
                : null,
        [data],
    );
    const recorder = useDoseRecorder(context, onClose);
    const doses =
        data?.medicines
            .flatMap((med) =>
                Object.values(med.cells)
                    .flat()
                    .map((dose) => ({ med, dose, kind: cellKind(dose, true) })),
            )
            .filter(({ kind }) =>
                ['due_now', 'due', 'overdue'].includes(kind),
            ) ?? [];
    return (
        <>
            {choosing && (
                <SettingsModal
                    frontline
                    title={`Record a dose for ${personName}`}
                    description="Choose today's scheduled dose or an as-needed medicine. Recording checks your authority and the current instructions."
                    width={720}
                    onClose={onClose}
                >
                    {!data || load !== 'ready' ? (
                        <ReadingState load={load} reload={reload} />
                    ) : (
                        <div className="space-y-3">
                            {!data.can.record && (
                                <p className="text-sm">
                                    {data.can.record_reason === 'no_shift'
                                        ? 'Recording needs a current shift or approved medication authority.'
                                        : 'Recording needs medication recording access.'}
                                </p>
                            )}
                            {doses.length ? (
                                doses.map(({ med, dose, kind }) => {
                                    const blocked = recordBlock(
                                        dose,
                                        kind,
                                        data,
                                        personName,
                                    );
                                    return (
                                        <div
                                            key={dose.key}
                                            className="rounded-lg border p-3"
                                        >
                                            <p className="font-semibold">
                                                {med.name} · {dose.time}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {med.dose} ·{' '}
                                                {cellLabel(dose, kind)}
                                            </p>
                                            <Button
                                                className="mt-2"
                                                disabled={Boolean(blocked)}
                                                title={blocked ?? undefined}
                                                onClick={() => {
                                                    recorder.recordScheduled(
                                                        dose,
                                                    );
                                                    setChoosing(false);
                                                }}
                                            >
                                                Record this dose
                                            </Button>
                                            {blocked && (
                                                <p className="text-caption mt-2">
                                                    {blocked}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })
                            ) : (
                                <p className="text-sm">
                                    No scheduled doses are due now.
                                </p>
                            )}
                            {data.prn.rows.length > 0 && (
                                <Button
                                    variant="outline"
                                    disabled={!data.can.record}
                                    onClick={() => {
                                        recorder.recordAsNeeded();
                                        setChoosing(false);
                                    }}
                                >
                                    Record an as-needed dose
                                </Button>
                            )}
                            {data.hidden_controlled.total > 0 ||
                            data.prn.hidden > 0 ? (
                                <p className="text-caption text-muted-foreground">
                                    Controlled medicines need
                                    controlled-medicine access.
                                </p>
                            ) : null}
                        </div>
                    )}
                </SettingsModal>
            )}
            {recorder.element}
        </>
    );
}
