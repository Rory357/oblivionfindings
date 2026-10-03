import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import {
    ClipboardCheck,
    Pencil,
    Plus,
    ShieldAlert,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { ReadingState } from './reading';
import { SectionCard } from './ui';
import { useDraftClose } from './use-draft-close';
import { useRecordJson } from './use-record-json';

type Entry = {
    key: string;
    allergen: string;
    severity?: string | null;
    reaction?: string | null;
    notes?: string | null;
    identified_date?: string | null;
    identified_by?: string | null;
};
export type AllergySummary = {
    status: 'none' | 'recorded' | 'no_known';
    entries: Entry[];
    reviewed: { at: string; by: string | null; how: string } | null;
    digest: string;
    can_edit?: boolean;
    can_review?: boolean;
};

export function AllergyRecord({
    clientId,
    editable = false,
}: {
    clientId: number;
    editable?: boolean;
}) {
    const { data, load, reload } = useRecordJson<AllergySummary>(
        `/emar/clients/${clientId}/record/allergies`,
    );
    const [action, setAction] = useState<'edit' | 'review' | null>(null);
    if (load !== 'ready' || !data)
        return <ReadingState load={load} reload={reload} />;
    return (
        <>
            <SectionCard
                icon={ShieldAlert}
                title="Allergy record"
                right={
                    editable ? (
                        <>
                            {data.can_edit && (
                                <Button
                                    variant="outline"
                                    onClick={() => setAction('edit')}
                                >
                                    <Pencil className="size-4" /> Edit allergies
                                </Button>
                            )}
                            {data.can_review && (
                                <Button
                                    variant="outline"
                                    onClick={() => setAction('review')}
                                >
                                    <ClipboardCheck className="size-4" />{' '}
                                    Confirm reviewed
                                </Button>
                            )}
                        </>
                    ) : (
                        <Button variant="outline" asChild>
                            <Link
                                href={`/operations/clients/${clientId}/medical`}
                            >
                                Open health profile
                            </Link>
                        </Button>
                    )
                }
            >
                {data.entries.length ? (
                    <div className="divide-y rounded-lg border">
                        {data.entries.map((entry) => (
                            <div key={entry.key} className="p-3">
                                <p className="font-semibold">
                                    {entry.allergen || 'Allergen not recorded'}
                                    {entry.severity && (
                                        <span className="text-caption ml-2 text-muted-foreground">
                                            {entry.severity.replaceAll(
                                                '_',
                                                ' ',
                                            )}
                                        </span>
                                    )}
                                </p>
                                <p className="text-sm">
                                    {entry.reaction || 'Reaction not recorded'}
                                </p>
                                {entry.notes && (
                                    <p className="text-caption mt-1 whitespace-pre-wrap text-muted-foreground">
                                        {entry.notes}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm">
                        {data.status === 'no_known'
                            ? 'No known allergies — confirmed after review.'
                            : 'No allergies recorded. This does not establish no known allergies.'}
                    </p>
                )}
                {data.reviewed ? (
                    <div className="text-caption text-muted-foreground">
                        Reviewed by {data.reviewed.by || 'Recorded reviewer'} ·{' '}
                        {formatDateTime(data.reviewed.at)}
                        <p className="whitespace-pre-wrap">
                            Checked with: {data.reviewed.how}
                        </p>
                    </div>
                ) : (
                    <p className="text-caption text-status-warning">
                        Not reviewed — a house lead or clinical lead confirms
                        the list.
                    </p>
                )}
            </SectionCard>
            {action && (
                <AllergyEditor
                    key={action}
                    action={action}
                    clientId={clientId}
                    summary={data}
                    onClose={() => setAction(null)}
                    onSaved={() => {
                        setAction(null);
                        reload();
                    }}
                />
            )}
        </>
    );
}

function AllergyEditor({
    action,
    clientId,
    summary,
    onClose,
    onSaved,
}: {
    action: 'edit' | 'review';
    clientId: number;
    summary: AllergySummary;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [records, setRecords] = useState<Entry[]>(summary.entries);
    const [method, setMethod] = useState('');
    const [noKnown, setNoKnown] = useState(false);
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [requestUuid] = useState(() => crypto.randomUUID());
    const { requestClose, confirmation } = useDraftClose(
        JSON.stringify(records) !== JSON.stringify(summary.entries) ||
            Boolean(method || noKnown),
        busy,
        onClose,
    );
    const update = (key: string, field: keyof Entry, value: string) =>
        setRecords((rows) =>
            rows.map((row) =>
                row.key === key ? { ...row, [field]: value || null } : row,
            ),
        );
    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            await axios.post(`/emar/clients/${clientId}/record/allergies`, {
                action,
                digest: summary.digest,
                request_uuid: requestUuid,
                records,
                method,
                no_known: noKnown,
            });
            onSaved();
        } catch (cause) {
            const response = axios.isAxiosError(cause) ? cause.response : null;
            setError(
                response?.status === 409
                    ? 'The list changed or this request has already been saved. Close and reload the allergy record before editing again.'
                    : Object.values(response?.data?.errors ?? {})
                          .flat()
                          .join(' ') ||
                          'We couldn’t confirm the save. Your entries are still here; retry the same save.',
            );
        } finally {
            setBusy(false);
        }
    };
    const valid =
        action === 'edit'
            ? records.every((entry) => entry.allergen.trim())
            : method.trim().length > 0 &&
              (summary.entries.length > 0 || noKnown);
    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                title={
                    action === 'edit'
                        ? 'Edit allergy record'
                        : 'Review allergy record'
                }
                description="The health profile holds the allergy list and its review evidence."
                railIcon={ShieldAlert}
                railTitle="Allergy record"
                railSub="Health profile"
                stepIndex={step}
                onStepClick={setStep}
                steps={[
                    {
                        key: 'record',
                        label: action === 'edit' ? 'Allergies' : 'Review',
                        blurb: 'Check the recorded evidence',
                        icon: Pencil,
                    },
                    {
                        key: 'check',
                        label: 'Check and save',
                        blurb: 'Confirm the list',
                        icon: ClipboardCheck,
                    },
                ]}
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={step ? () => setStep(0) : requestClose}
                        >
                            {step ? 'Back' : 'Cancel'}
                        </Button>
                        <Button
                            disabled={busy || !valid}
                            onClick={step ? save : () => setStep(1)}
                        >
                            {busy
                                ? 'Saving…'
                                : step
                                  ? 'Save allergy record'
                                  : 'Continue'}
                        </Button>
                    </>
                }
            >
                <WizardStepPane>
                    {error && (
                        <p
                            role="alert"
                            className="mb-4 text-sm text-status-critical"
                        >
                            {error}
                        </p>
                    )}
                    {step ? (
                        <div className="space-y-3">
                            {records.map((entry) => (
                                <ReviewRow
                                    key={entry.key}
                                    label={entry.allergen}
                                    value={
                                        [
                                            entry.severity,
                                            entry.reaction,
                                            entry.notes,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') ||
                                        'No additional details recorded'
                                    }
                                />
                            ))}
                            {action === 'edit' ? (
                                <p className="text-sm">
                                    Saving changes clears the review stamp.
                                    Removed entries and copied source records
                                    remain in history.
                                </p>
                            ) : (
                                <>
                                    <ReviewRow
                                        label="Checked with"
                                        value={method}
                                    />
                                    {noKnown && (
                                        <ReviewRow
                                            label="Status"
                                            value="No known allergies after review"
                                        />
                                    )}
                                </>
                            )}
                        </div>
                    ) : action === 'review' ? (
                        <div className="space-y-4">
                            <p className="text-sm">
                                Check the list with the person, their clinical
                                record or their prescriber before confirming.
                            </p>
                            <Field label="Who or what did you check?" required>
                                <Textarea
                                    value={method}
                                    onChange={(event) =>
                                        setMethod(event.target.value)
                                    }
                                />
                            </Field>
                            {summary.entries.length === 0 && (
                                <label className="flex min-h-11 items-center gap-3 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={noKnown}
                                        onChange={(event) =>
                                            setNoKnown(event.target.checked)
                                        }
                                    />{' '}
                                    I checked and confirmed no known allergies
                                </label>
                            )}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {records.map((entry) => (
                                <div
                                    key={entry.key}
                                    className="space-y-3 rounded-lg border p-4"
                                >
                                    <Field label="Allergen" required>
                                        <Input
                                            value={entry.allergen}
                                            onChange={(event) =>
                                                update(
                                                    entry.key,
                                                    'allergen',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Severity">
                                        <SelectInput
                                            value={
                                                entry.severity || 'not_recorded'
                                            }
                                            onChange={(value) =>
                                                update(
                                                    entry.key,
                                                    'severity',
                                                    value === 'not_recorded'
                                                        ? ''
                                                        : value,
                                                )
                                            }
                                            placeholder="Severity"
                                            options={[
                                                {
                                                    value: 'not_recorded',
                                                    label: 'Not recorded',
                                                },
                                                ...[
                                                    'mild',
                                                    'moderate',
                                                    'severe',
                                                    'life_threatening',
                                                ].map((value) => ({
                                                    value,
                                                    label: value.replaceAll(
                                                        '_',
                                                        ' ',
                                                    ),
                                                })),
                                            ]}
                                        />
                                    </Field>
                                    <Field label="Reaction">
                                        <Textarea
                                            value={entry.reaction || ''}
                                            onChange={(event) =>
                                                update(
                                                    entry.key,
                                                    'reaction',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Notes">
                                        <Textarea
                                            value={entry.notes || ''}
                                            onChange={(event) =>
                                                update(
                                                    entry.key,
                                                    'notes',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            setRecords((rows) =>
                                                rows.filter(
                                                    (row) =>
                                                        row.key !== entry.key,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" /> Remove
                                        from current list
                                    </Button>
                                </div>
                            ))}
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setRecords((rows) => [
                                        ...rows,
                                        {
                                            key: crypto.randomUUID(),
                                            allergen: '',
                                        },
                                    ])
                                }
                            >
                                <Plus className="size-4" /> Add allergy
                            </Button>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            {confirmation}
        </>
    );
}
