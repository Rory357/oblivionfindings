import { RecordPicker } from '@/components/people-locations/record-picker';
import { Button } from '@/components/ui/button';
import { WizardShell } from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useState } from 'react';
import { Field, Note } from './_parts';

type Person = {
    id: number;
    name?: string;
    first_name?: string;
    last_name?: string;
};
type Medicine = { id: number; client_id: number; name: string };

/** Keep existing chart/supply entry links on the same source-and-check workflow. */
export function LegacyOrderLink({
    open = true,
    onClose,
    clientId,
    clients = [],
    medication,
    medications = [],
    covert = false,
    checkMode,
}: {
    open?: boolean;
    onClose: () => void;
    clientId?: number | null;
    clients?: Person[];
    medication?: Medicine;
    medications?: Medicine[];
    covert?: boolean;
    checkMode?: 'independent' | 'send_back';
}) {
    const [person, setPerson] = useState(
        String(clientId ?? medication?.client_id ?? ''),
    );
    const [medicine, setMedicine] = useState(String(medication?.id ?? ''));
    const selected =
        medication ?? medications.find((item) => String(item.id) === medicine);
    const enter = () =>
        router.visit(
            `/emar/prescriptions?${new URLSearchParams({
                client_id: String(selected?.client_id ?? person),
                ...(selected ? { order_id: String(selected.id) } : {}),
                action: covert ? 'covert' : checkMode ? 'check' : 'entry',
                ...(checkMode ? { mode: checkMode } : {}),
            }).toString()}`,
        );
    return (
        <WizardShell
            frontline
            open={open}
            onClose={onClose}
            title={
                covert
                    ? 'Authorise covert giving'
                    : checkMode === 'send_back'
                      ? 'Send the proposed version back'
                      : medication
                        ? 'Enter an order change'
                        : 'Enter a medication order'
            }
            description="Keep the prescriber’s source, order versions and checks together."
            railIcon={FileText}
            railTitle="Prescription source"
            railSub={medication?.name ?? 'Choose the record'}
            steps={[
                {
                    key: 'record',
                    label: 'The record',
                    blurb: 'Continue with the source and evidence',
                    icon: FileText,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Cancel
                </Button>
            }
            footerEnd={
                <Button disabled={covert ? !selected : !person} onClick={enter}>
                    {covert
                        ? 'Record the authorisation'
                        : checkMode
                          ? 'Open the version and source'
                          : 'Enter from the prescriber’s source'}
                </Button>
            }
        >
            <div className="grid gap-5">
                {covert ? (
                    <Field id="legacy-order-medicine" label="Medication order">
                        <RecordPicker
                            label="Medication order"
                            value={medicine}
                            onChange={setMedicine}
                            options={medications.map((item) => {
                                const resident = clients.find(
                                    (candidate) =>
                                        candidate.id === item.client_id,
                                );
                                return {
                                    value: String(item.id),
                                    label: item.name,
                                    description:
                                        resident?.name ??
                                        [
                                            resident?.first_name,
                                            resident?.last_name,
                                        ]
                                            .filter(Boolean)
                                            .join(' '),
                                };
                            })}
                        />
                    </Field>
                ) : !clientId && !medication ? (
                    <Field id="legacy-order-person" label="Person">
                        <RecordPicker
                            label="Person"
                            value={person}
                            onChange={setPerson}
                            options={clients.map((item) => ({
                                value: String(item.id),
                                label:
                                    item.name ??
                                    [item.first_name, item.last_name]
                                        .filter(Boolean)
                                        .join(' '),
                            }))}
                        />
                    </Field>
                ) : null}
                <Note>
                    {covert
                        ? 'Record the capacity assessment, guardian or EPOA consultation, pharmacist advice and signed prescriber authorisation for this medicine.'
                        : checkMode
                          ? 'Review the proposed version and record why it needs correcting. The previous checked prescription stays in effect.'
                          : medication
                            ? 'The checked prescription stays in effect while the change waits for its separate check.'
                            : 'Attach the written source or record the spoken instruction and its witnessed read-back. A separate check is required before the first dose.'}
                </Note>
            </div>
        </WizardShell>
    );
}
