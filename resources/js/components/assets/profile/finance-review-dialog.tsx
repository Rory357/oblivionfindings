import {
    isJsonObject,
    useVehicleRecordCommand as useRecordCommand,
} from '@/components/fleet-assets/vehicle-workspace/record-command';
import { VehicleSearchSelect as SearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import { WorkspaceWizard } from '@/components/fleet-assets/vehicle-workspace/wizard-kit';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { Wallet } from 'lucide-react';
import { useState } from 'react';
import type { ProfileWorkspace } from './types';

export function AssetFinanceReviewDialog({
    assetId,
    assetName,
    workspace,
    initialType = '',
    onClose,
    onSaved,
}: {
    assetId: number;
    assetName: string;
    workspace: ProfileWorkspace;
    initialType?: string;
    onClose: () => void;
    onSaved: () => Promise<void>;
}) {
    const [step, setStep] = useState(0),
        [type, setType] = useState(initialType),
        [source, setSource] = useState('vehicle'),
        [amount, setAmount] = useState(''),
        [note, setNote] = useState(''),
        [documentId, setDocumentId] = useState(''),
        [saved, setSaved] = useState(false),
        [message, setMessage] = useState(''),
        [error, setError] = useState(''),
        [key, setKey] = useState(() => crypto.randomUUID());
    const command = useRecordCommand(isJsonObject);
    const documents = workspace.documents.filter(
        (file) => file.current && file.state === 'available' && file.set_id,
    );
    const validate = () => {
        const problem =
            !type || !note.trim()
                ? 'Choose a request type and describe what Finance needs to review.'
                : amount &&
                    (!/^\d+(\.\d{1,2})?$/.test(amount) ||
                        Number(amount) > 99999999.99)
                  ? 'Enter an estimate between 0 and 99,999,999.99.'
                  : documentId &&
                      !documents.some((file) => String(file.id) === documentId)
                    ? 'This evidence version changed. Select a current checked document.'
                    : '';
        setError(problem);
        return !problem;
    };
    const submit = async () => {
        if (!validate()) return;
        const result = await command.submit(
            `/assets/${assetId}/finance-review`,
            {
                request_type: type,
                source,
                amount: amount || null,
                note,
                existing_document_id: documentId ? Number(documentId) : null,
                request_key: key,
            },
        );
        if (result) {
            setMessage(String(result.message));
            setSaved(true);
            await onSaved();
        }
    };
    return (
        <WorkspaceWizard
            title="Request Finance review"
            description={assetName}
            railIcon={Wallet}
            railSub="Finance"
            steps={[
                {
                    key: 'details',
                    label: 'Request details',
                    blurb: 'Explain the review and source',
                    icon: Wallet,
                },
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Confirm before sending to Finance',
                    icon: Wallet,
                },
            ]}
            step={step}
            setStep={setStep}
            pct={type && note ? 80 : 0}
            context={{
                name: assetName,
                detail: 'Finance decides the next action',
            }}
            command={command}
            dirty={
                !!(type || note || amount || documentId || source !== 'vehicle')
            }
            saved={saved}
            submitLabel="Send to Finance"
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
                    title="Finance review submitted"
                    blurb={message}
                    actions={<Button onClick={onClose}>Done</Button>}
                />
            }
        >
            {error && (
                <p
                    role="alert"
                    className="rounded-lg border border-destructive/30 p-3 text-destructive"
                >
                    {error}
                </p>
            )}
            {step === 0 ? (
                <div className="space-y-5">
                    <p className="text-subtle rounded-lg border bg-muted p-3">
                        This sends a review request. Finance retains approval,
                        payment, depreciation and disposal decisions.
                    </p>
                    <div>
                        <Label>Request type *</Label>
                        <SearchSelect
                            value={type}
                            onChange={setType}
                            options={workspace.finance_types}
                            label="Request type"
                            invalid={!!error && !type}
                        />
                    </div>
                    <div>
                        <Label>Source record</Label>
                        <SearchSelect
                            value={source}
                            onChange={setSource}
                            options={workspace.finance_sources}
                            label="Source record"
                        />
                    </div>
                    <div>
                        <Label htmlFor="finance-estimate">
                            Estimate (NZD, optional)
                        </Label>
                        <Input
                            id="finance-estimate"
                            inputMode="decimal"
                            value={amount}
                            onChange={(event) => setAmount(event.target.value)}
                            placeholder="0.00"
                        />
                    </div>
                    <div>
                        <Label htmlFor="finance-note">
                            What Finance needs to review *
                        </Label>
                        <Textarea
                            id="finance-note"
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            maxLength={2000}
                            aria-invalid={!!error && !note.trim()}
                        />
                    </div>
                    <div>
                        <Label>Supporting document (optional)</Label>
                        <SearchSelect
                            value={documentId}
                            onChange={setDocumentId}
                            options={[
                                { value: '', label: 'No attachment' },
                                ...documents.map((file) => ({
                                    value: String(file.id),
                                    label: `${file.name} · Version ${file.version}`,
                                })),
                            ]}
                            label="Supporting document"
                        />
                        <p className="text-caption mt-2 text-muted-foreground">
                            Choose a current file that passed its file check.
                            Upload new evidence in Documents first.
                        </p>
                    </div>
                </div>
            ) : (
                <ReviewCard title="Finance request" icon={Wallet}>
                    <ReviewRow
                        label="Type"
                        value={
                            workspace.finance_types.find(
                                (item) => item.value === type,
                            )?.label || type
                        }
                    />
                    <ReviewRow
                        label="Source"
                        value={
                            workspace.finance_sources.find(
                                (item) => item.value === source,
                            )?.label || assetName
                        }
                    />
                    <ReviewRow
                        label="Estimate"
                        value={
                            amount
                                ? `NZD ${Number(amount).toFixed(2)} · not approved`
                                : 'No estimate'
                        }
                    />
                    <ReviewRow label="Review note" value={note} />
                    <ReviewRow
                        label="Evidence"
                        value={
                            documents.find(
                                (file) => String(file.id) === documentId,
                            )?.name || 'No attachment'
                        }
                    />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}
