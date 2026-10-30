import ConfirmDialog from '@/components/confirm-dialog';
import {
    emptyWitness,
    witnessErrors,
    WitnessField,
} from '@/components/emar/controlled/product-ui';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { ClipboardCheck, Users } from 'lucide-react';
import { useState } from 'react';
import { useStockCommand } from './_requests';
import type { ItemDetail } from './_types';

export function ControlledOpeningDialog({
    item,
    onClose,
    onSaved,
}: {
    item: ItemDetail;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [witness, setWitness] = useState(emptyWitness);
    const [step, setStep] = useState(0);
    const [discard, setDiscard] = useState(false);
    const command = useStockCommand();
    const candidates = (item.witnesses ?? []).filter(
        (person) => person.id !== item.current_user_id,
    );
    const valid =
        item.on_hand !== null &&
        !!item.unit?.trim() &&
        Object.keys(witnessErrors(witness, candidates)).length === 0;
    const close = () => {
        if (!command.saving) {
            if (witness.id || witness.pin) setDiscard(true);
            else onClose();
        }
    };
    const save = async () => {
        if (!valid) return;
        const result = await command.run({
            action: 'initialise',
            client_medication_id: item.id,
            confirm_balance: true,
            witnessed_by: Number(witness.id),
            witness_credential: witness.pin,
        });
        if (result) {
            setWitness(emptyWitness());
            onSaved();
            onClose();
        }
    };
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close}
                title="Start controlled pack tracking"
                description="Carry forward only the checked recorded balance."
                railIcon={ClipboardCheck}
                railTitle="Opening stock"
                railSub={`${item.client_name} · ${item.name}`}
                steps={[
                    {
                        key: 'witness',
                        label: 'Check together',
                        blurb: 'Authenticate the second checker',
                        icon: Users,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Keep the source history',
                        icon: ClipboardCheck,
                        disabled: !valid || command.saving,
                    },
                ]}
                stepIndex={step}
                onStepClick={(next) => {
                    if (!command.saving && (next === 0 || valid)) setStep(next);
                }}
                pct={(step + 1) * 50}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={command.saving}
                        onClick={close}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            disabled={command.saving || step === 0}
                            onClick={() => setStep(0)}
                        >
                            Back
                        </Button>
                        <Button
                            disabled={command.saving || !valid}
                            onClick={() =>
                                step === 0 ? setStep(1) : void save()
                            }
                        >
                            {command.saving
                                ? 'Saving…'
                                : step === 0
                                  ? 'Continue'
                                  : 'Use checked balance'}
                        </Button>
                    </>
                }
            >
                <WizardStepPane>
                    <fieldset disabled={command.saving} className="space-y-5">
                        {Object.entries(command.errors).map(([key, error]) => (
                            <SettingsNotice key={key}>{error}</SettingsNotice>
                        ))}
                        <SettingsNotice>
                            This creates an opening pack from the existing
                            recorded balance. The original batch, expiry and
                            receipt date stay unknown. If the physical count
                            differs, resolve that through the controlled
                            register before using this opening.
                        </SettingsNotice>
                        {step === 0 ? (
                            <WitnessField
                                value={witness}
                                onChange={setWitness}
                                candidates={candidates}
                                errors={command.errors}
                            />
                        ) : (
                            <ReviewCard
                                icon={ClipboardCheck}
                                title="Checked opening"
                            >
                                <ReviewRow
                                    label="Person"
                                    value={item.client_name}
                                />
                                <ReviewRow label="Medicine" value={item.name} />
                                <ReviewRow
                                    label="Recorded balance"
                                    value={`${item.on_hand ?? 'Unknown'} ${item.unit ?? ''}`}
                                />
                                <ReviewRow
                                    label="Witness"
                                    value={
                                        candidates.find(
                                            (person) =>
                                                String(person.id) ===
                                                witness.id,
                                        )?.name ?? 'Not selected'
                                    }
                                />
                            </ReviewCard>
                        )}
                    </fieldset>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    if (command.uncertain) onSaved();
                    onClose();
                }}
                title={
                    command.uncertain
                        ? 'Leave this unconfirmed opening?'
                        : 'Discard this opening?'
                }
                description={
                    command.uncertain
                        ? 'Pack tracking may already have started. Check the current stock record before starting another opening.'
                        : 'The recorded balance has not changed.'
                }
                confirmText={
                    command.uncertain ? 'Close and check stock' : 'Discard'
                }
            />
        </>
    );
}
