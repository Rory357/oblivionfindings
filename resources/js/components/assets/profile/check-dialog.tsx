import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    isJsonObject,
    useVehicleRecordCommand as useRecordCommand,
} from '@/components/fleet-assets/vehicle-workspace/record-command';
import { VehicleSearchSelect as SearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import { WorkspaceWizard } from '@/components/fleet-assets/vehicle-workspace/wizard-kit';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { ClipboardCheck } from 'lucide-react';
import { useState } from 'react';
export function AssetCheckDialog({
    assetId,
    assetName,
    version,
    onClose,
    onSaved,
}: {
    assetId: number;
    assetName: string;
    version: number;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [step, setStep] = useState(0),
        [result, setResult] = useState(''),
        [reason, setReason] = useState(''),
        [due, setDue] = useState(''),
        [condition, setCondition] = useState(''),
        [saved, setSaved] = useState(false),
        [error, setError] = useState(''),
        [key, setKey] = useState(() => crypto.randomUUID());
    const command = useRecordCommand(isJsonObject);
    const validate = () => {
        setError(
            !result || !reason.trim()
                ? 'Choose an outcome and describe what you checked.'
                : '',
        );
        return !!result && !!reason.trim();
    };
    const submit = async () => {
        if (!validate()) return;
        if (
            await command.submit(`/assets/${assetId}/profile-actions`, {
                action: 'check',
                request_key: key,
                expected_version: version,
                result,
                reason,
                next_due_at: due || null,
                condition: condition || null,
            })
        ) {
            setSaved(true);
            onSaved();
        }
    };
    return (
        <WorkspaceWizard
            title="Record check observation"
            description={assetName}
            railIcon={ClipboardCheck}
            railSub="Checks & service"
            steps={[
                {
                    key: 'details',
                    label: 'Check details',
                    blurb: 'Record the observed facts',
                    icon: ClipboardCheck,
                },
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Confirm the observation',
                    icon: ClipboardCheck,
                },
            ]}
            step={step}
            setStep={setStep}
            pct={reason ? 70 : 0}
            context={{
                name: assetName,
                detail: 'Use approved equipment instructions',
            }}
            command={command}
            dirty={!!(result || reason || due || condition)}
            saved={saved}
            submitLabel="Record observation"
            onValidateStep={validate}
            onSubmit={submit}
            onClose={onClose}
            onReload={async () => {
                await onSaved();
                command.reset();
                setKey(crypto.randomUUID());
                setStep(0);
            }}
            errorKey={error + command.message}
            success={
                <WizardSuccessPane
                    title="Check recorded"
                    blurb="The observation is in this asset’s history. Any Maintenance restriction remains in place."
                    actions={
                        <Button onClick={onClose}>Return to checks</Button>
                    }
                />
            }
        >
            {step === 0 ? (
                <div className="space-y-5">
                    {error && (
                        <p role="alert" className="text-status-critical">
                            {error}
                        </p>
                    )}
                    <p className="text-subtle">
                        Record an observation completed now. This is not a
                        policy-driven safety assessment or release. Use
                        Maintenance for formal assessment and follow-up.
                    </p>
                    <Label>Outcome *</Label>
                    <div
                        role="group"
                        aria-label="Check outcome"
                        className="flex flex-wrap gap-3"
                    >
                        {[
                            ['pass', 'Pass'],
                            ['fail', 'Fail'],
                            ['needs_followup', 'Needs follow-up'],
                        ].map(([value, label]) => (
                            <Button
                                key={value}
                                variant={
                                    result === value ? 'default' : 'outline'
                                }
                                aria-pressed={result === value}
                                onClick={() => setResult(value)}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>
                    <Label htmlFor="asset-check-observation">
                        What was checked and observed? *
                    </Label>
                    <Textarea
                        id="asset-check-observation"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        maxLength={2000}
                        aria-invalid={!!error && !reason.trim()}
                    />
                    <Label>Observed condition (optional)</Label>
                    <SearchSelect
                        label="Observed condition"
                        value={condition}
                        onChange={setCondition}
                        options={['good', 'worn', 'damaged', 'unknown'].map(
                            (value) => ({
                                value,
                                label:
                                    value.charAt(0).toUpperCase() +
                                    value.slice(1),
                            }),
                        )}
                    />
                    <Label>Next approved due date (optional)</Label>
                    <DatePicker
                        id="asset-check-due"
                        allowClear
                        label="Next approved due date"
                        value={due}
                        onChange={setDue}
                    />
                    <p className="text-caption">
                        Only enter a date from an approved schedule. No interval
                        is assumed.
                    </p>
                </div>
            ) : (
                <ReviewCard title="Recorded facts" icon={ClipboardCheck}>
                    <ReviewRow label="Asset" value={assetName} />
                    <ReviewRow
                        label="Outcome"
                        value={result.replaceAll('_', ' ')}
                    />
                    <ReviewRow label="Observation" value={reason} />
                    <ReviewRow label="Next due" value={due || 'No change'} />
                    <ReviewRow
                        label="Condition"
                        value={condition || 'No change'}
                    />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}
