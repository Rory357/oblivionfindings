import {
    cellKind,
    cellLabel,
    recordBlock,
} from '@/components/clients/profile/mar-day/dose-cell';
import type { MedicationDay } from '@/components/clients/profile/mar-day/types';
import { AsNeededPicker } from '@/components/emar/record-dose/dialogs';
import {
    RecordDoseDialog,
    type PendingDose,
} from '@/components/emar/record-dose/record-dose-dialog';
import { pendingDoseRecoveries } from '@/components/emar/record-dose/recovery';
import type {
    DoseTarget,
    EntryPoint,
} from '@/components/emar/record-dose/types';
import { useDoseRecorder } from '@/components/emar/recording/use-dose-recorder';
import { PageHeaderPrimaryButton } from '@/components/page';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { Pill, ShieldCheck } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { useRecordJson } from './use-record-json';

/** Header and hub use the same scheduled/PRN seam as the chart cells. */
export function RecordDoseLaunch({
    clientId,
    personName,
    asNeeded = false,
}: {
    clientId: number;
    personName: string;
    asNeeded?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    const close = () => {
        setOpen(false);
        window.setTimeout(
            () => opener.current?.isConnected && opener.current.focus(),
            0,
        );
    };
    return (
        <>
            <PageHeaderPrimaryButton
                icon={Pill}
                onClick={(event) => {
                    opener.current = event.currentTarget;
                    setOpen(true);
                }}
            >
                {asNeeded ? 'Record as-needed dose' : 'Record a dose'}
            </PageHeaderPrimaryButton>
            {open && (
                <DosePicker
                    key={clientId + ':' + asNeeded}
                    clientId={clientId}
                    personName={personName}
                    asNeeded={asNeeded}
                    onClose={close}
                    returnFocus={() => opener.current}
                />
            )}
        </>
    );
}

export function DosePicker({
    clientId,
    personName,
    asNeeded,
    onClose,
    returnFocus,
    entry = 'mar',
}: {
    clientId: number;
    personName: string;
    asNeeded: boolean;
    onClose: () => void;
    returnFocus: () => HTMLElement | null;
    entry?: EntryPoint;
}) {
    // A recording action always checks today's orders, even on a historical chart.
    const { data, load, reload } = useRecordJson<MedicationDay>(
        `/emar/clients/${clientId}/day`,
    );
    const [choosing, setChoosing] = useState(true);
    const [recovering, setRecovering] = useState<DoseTarget | null>(null);
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
                      entry,
                  }
                : null,
        [data, entry],
    );
    const recorder = useDoseRecorder(context, onClose, returnFocus);
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
    const ready = load === 'ready' && data !== null;
    const pending = ready ? pendingDoseRecoveries<PendingDose>(clientId) : [];
    const canChoose = ready && data.can.record && context !== null;
    const hidden =
        ready &&
        (asNeeded
            ? data.prn.hidden > 0
            : data.hidden_controlled.total > 0 || data.prn.hidden > 0);
    const accessMessage =
        ready && !data.can.record
            ? data.can.record_reason === 'no_shift'
                ? `Clock in to a shift covering ${personName}, or ask the medication lead to check your recording authority.`
                : 'Your account can view this chart but cannot record doses. Ask your medication lead to check your recording access.'
            : null;

    return (
        <>
            {choosing &&
                (asNeeded &&
                canChoose &&
                data.prn.rows.length > 0 &&
                pending.length === 0 ? (
                    <AsNeededPicker
                        choices={data.prn.rows}
                        onClose={onClose}
                        onCloseAutoFocus={(event) => {
                            event.preventDefault();
                            returnFocus()?.focus();
                        }}
                        onPick={(id) => {
                            recorder.recordAsNeeded(id);
                            setChoosing(false);
                        }}
                    />
                ) : (
                    <SettingsModal
                        title={
                            asNeeded
                                ? `Record as-needed dose for ${personName}`
                                : `Record a dose for ${personName}`
                        }
                        description={
                            asNeeded
                                ? 'Current as-needed medicines. Choose a medicine to open its safety checks and recording form.'
                                : 'Choose a scheduled dose due today or an as-needed medicine. Safety checks open before recording.'
                        }
                        width={
                            !asNeeded && canChoose && doses.length > 0
                                ? 720
                                : 480
                        }
                        onClose={onClose}
                        onCloseAutoFocus={(event) => event.preventDefault()}
                    >
                        {!ready ? (
                            load === 'loading' ? (
                                <LoadingState
                                    className="py-4"
                                    message="Checking current medicines and recording access…"
                                />
                            ) : (
                                <ErrorState
                                    className="py-4"
                                    title={
                                        load === 'forbidden'
                                            ? 'This medication record is no longer available'
                                            : 'Couldn’t load recording choices'
                                    }
                                    message="Try again before relying on this information."
                                    onRetry={reload}
                                />
                            )
                        ) : (
                            <div className="space-y-3">
                                {pending.map((attempt) => (
                                    <div
                                        key={attempt.key}
                                        className="flex items-center justify-between gap-4 rounded-lg border p-3"
                                    >
                                        <p className="text-sm">
                                            A previous dose attempt needs
                                            confirmation.
                                        </p>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                setRecovering(
                                                    attempt.draft.target,
                                                );
                                                setChoosing(false);
                                            }}
                                        >
                                            Check original attempt
                                        </Button>
                                    </div>
                                ))}
                                {accessMessage && (
                                    <div
                                        role="status"
                                        className="flex items-start gap-2 rounded-lg border bg-muted/30 p-3 text-sm"
                                    >
                                        <ShieldCheck
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        />
                                        <p>{accessMessage}</p>
                                    </div>
                                )}
                                {data.can.record && !context ? (
                                    <ErrorState
                                        className="py-4"
                                        title="Recording information is unavailable"
                                        message="Reload the choices before recording a dose."
                                        onRetry={reload}
                                    />
                                ) : asNeeded ? (
                                    data.prn.rows.length === 0 ? (
                                        <EmptyState
                                            icon={Pill}
                                            variant="compact"
                                            title={
                                                hidden
                                                    ? 'No as-needed medicines available to you'
                                                    : `No as-needed medicines for ${personName}`
                                            }
                                            description={
                                                hidden
                                                    ? 'Controlled medicines require controlled-medicine access. Ask the medication lead to review the chart.'
                                                    : 'An as-needed dose needs a current medication order. Ask the medication lead to check the order if a medicine is missing.'
                                            }
                                        />
                                    ) : canChoose ? (
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                recorder.recordAsNeeded();
                                                setChoosing(false);
                                            }}
                                        >
                                            <Pill className="size-4" /> Choose
                                            an as-needed medicine
                                        </Button>
                                    ) : null
                                ) : canChoose ? (
                                    <>
                                        {doses.length > 0 ? (
                                            doses.map(({ med, dose, kind }) => {
                                                const blocked = recordBlock(
                                                    dose,
                                                    kind,
                                                    data,
                                                    personName,
                                                );
                                                return (
                                                    <Card
                                                        key={dose.key}
                                                        className="flex flex-row items-start justify-between gap-4 p-3"
                                                    >
                                                        <div className="min-w-0">
                                                            <p className="font-semibold">
                                                                {med.name} ·{' '}
                                                                {dose.time}
                                                            </p>
                                                            <p className="text-sm text-muted-foreground">
                                                                {med.dose} ·{' '}
                                                                {cellLabel(
                                                                    dose,
                                                                    kind,
                                                                )}
                                                            </p>
                                                            {blocked && (
                                                                <p className="text-caption mt-2">
                                                                    {blocked}
                                                                </p>
                                                            )}
                                                        </div>
                                                        <Button
                                                            size="sm"
                                                            className="shrink-0"
                                                            disabled={Boolean(
                                                                blocked,
                                                            )}
                                                            title={
                                                                blocked ??
                                                                undefined
                                                            }
                                                            onClick={() => {
                                                                recorder.recordScheduled(
                                                                    dose,
                                                                );
                                                                setChoosing(
                                                                    false,
                                                                );
                                                            }}
                                                        >
                                                            Record this dose
                                                        </Button>
                                                    </Card>
                                                );
                                            })
                                        ) : (
                                            <EmptyState
                                                icon={Pill}
                                                variant="compact"
                                                title="No scheduled doses are due now"
                                                description={
                                                    data.prn.rows.length > 0
                                                        ? 'You can choose an as-needed medicine below.'
                                                        : 'Check the chart for later doses. As-needed recording requires a current as-needed order.'
                                                }
                                            />
                                        )}
                                        {data.prn.rows.length > 0 && (
                                            <Button
                                                variant="outline"
                                                onClick={() => {
                                                    recorder.recordAsNeeded();
                                                    setChoosing(false);
                                                }}
                                            >
                                                <Pill className="size-4" />{' '}
                                                Record an as-needed dose
                                            </Button>
                                        )}
                                    </>
                                ) : null}
                                {hidden &&
                                    !(
                                        asNeeded && data.prn.rows.length === 0
                                    ) && (
                                        <p className="text-caption text-muted-foreground">
                                            Controlled medicines need
                                            controlled-medicine access.
                                        </p>
                                    )}
                            </div>
                        )}
                    </SettingsModal>
                ))}
            {recorder.element}
            {recovering && (
                <RecordDoseDialog
                    target={recovering}
                    entry={entry}
                    signedAs={
                        context?.signedAs ?? { name: '', role_label: null }
                    }
                    onClose={onClose}
                    returnFocus={returnFocus}
                />
            )}
        </>
    );
}
