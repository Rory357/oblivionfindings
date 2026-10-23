import { Button } from '@/components/ui/button';
import { FileDropzone, formatFileSize } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { useSafetyDraftState } from '@/hooks/use-safety-workspace-drafts';
import { formatDateTimeLong } from '@/lib/datetime';
import { DRILL_REGISTER } from '@/lib/health-safety/drill-navigation';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { DrillCommandFooter, useDrillCommandForm } from './drill-command-form';
import type { DrillWorkspaceDetail } from './types';

type Upload = {
    key: string;
    file: File;
    kind: string;
    notes: string;
    alt_text: string;
    expected?: number;
};
function DrillEvidenceUpload({
    detail: d,
    returnTo,
}: {
    detail: DrillWorkspaceDetail;
    returnTo: string;
}) {
    const [items, setItems] = useState<Upload[]>([]);
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    useSafetyDraftState({ dirty: items.length > 0, busy });
    const patch = (key: string, update: Partial<Upload>) =>
        setItems((current) =>
            current.map((item) =>
                item.key === key ? { ...item, ...update } : item,
            ),
        );
    const send = () => {
        if (busy || !d.can.add_evidence || !items.length) return;
        setBusy(true);
        setFailure(null);
        const queue = [...items];
        const next = (index: number, version: number) => {
            const item = queue[index];
            if (!item) {
                setBusy(false);
                return;
            }
            const expected = item.expected ?? version;
            patch(item.key, { expected });
            const data = new FormData();
            data.append('file', item.file);
            for (const [key, value] of Object.entries({
                kind: item.kind,
                notes: item.notes,
                alt_text: item.alt_text,
                expected_version: expected,
                idempotency_key: item.key,
                return_to: returnTo,
                section: 'evidence',
            }))
                data.append(key, String(value));
            let responded = false;
            router.post(`${DRILL_REGISTER}/${d.id}/attachments`, data, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    responded = true;
                    const error = (
                        page.props.flash as { error?: string } | undefined
                    )?.error;
                    const updated = page.props.detail as
                        | DrillWorkspaceDetail
                        | undefined;
                    if (
                        error ||
                        !updated ||
                        updated.id !== d.id ||
                        updated.lock_version <= expected ||
                        !Number.isSafeInteger(updated.expected_version) ||
                        updated.expected_version !== updated.lock_version
                    ) {
                        setFailure(
                            error ??
                                'The upload could not be confirmed. Your file is still here. Check the drill before retrying.',
                        );
                        setBusy(false);
                        return;
                    }
                    setItems((current) =>
                        current.filter((pending) => pending.key !== item.key),
                    );
                    next(index + 1, updated.expected_version);
                },
                onError: (errors) => {
                    responded = true;
                    setFailure(
                        Object.values(errors).join(' ') ||
                            'Upload failed. Your remaining files are still here.',
                    );
                    setBusy(false);
                },
                onCancel: () => {
                    responded = true;
                    setFailure(
                        'Upload interrupted. Check the drill before retrying. Your remaining files are still here.',
                    );
                    setBusy(false);
                },
                onFinish: () => {
                    if (!responded) {
                        setFailure(
                            'The upload could not be confirmed. Check the drill before retrying. Your remaining files are still here.',
                        );
                        setBusy(false);
                    }
                },
            });
        };
        next(0, d.expected_version);
    };
    return (
        <div className="space-y-4">
            <h3 className="font-semibold">Add evidence</h3>
            <FileDropzone
                disabled={busy}
                hint="Documents or images · up to 20 MB each"
                onFiles={(files) => {
                    const valid = files.filter(
                        (file) => file.size <= 20 * 1024 * 1024,
                    );
                    setFailure(
                        valid.length !== files.length
                            ? 'Files larger than 20 MB were not added. Choose a smaller file.'
                            : null,
                    );
                    setItems((current) => [
                        ...current,
                        ...valid.map((file) => ({
                            key: crypto.randomUUID(),
                            file,
                            kind: '',
                            notes: '',
                            alt_text: '',
                        })),
                    ]);
                }}
            />
            {items.map((item) => (
                <div
                    key={item.key}
                    className="space-y-3 rounded-lg border border-border p-4"
                >
                    <p className="font-medium break-all">
                        {item.file.name}{' '}
                        <span className="text-subtle">
                            · {formatFileSize(item.file.size)}
                        </span>
                    </p>
                    <fieldset
                        disabled={busy || item.expected !== undefined}
                        className="space-y-3"
                    >
                        <Field label={`Type for ${item.file.name}`}>
                            <SelectInput
                                frontline
                                value={item.kind || 'unspecified'}
                                placeholder="Choose evidence type"
                                onChange={(value) =>
                                    patch(item.key, {
                                        kind:
                                            value === 'unspecified'
                                                ? ''
                                                : value,
                                    })
                                }
                                options={[
                                    {
                                        value: 'unspecified',
                                        label: 'Not specified',
                                    },
                                    { value: 'photo', label: 'Photo' },
                                    { value: 'document', label: 'Document' },
                                    { value: 'record', label: 'Drill record' },
                                    { value: 'other', label: 'Other' },
                                ]}
                            />
                        </Field>
                        <Field label={`Notes for ${item.file.name}`}>
                            <Textarea
                                rows={2}
                                maxLength={1000}
                                value={item.notes}
                                onChange={(event) =>
                                    patch(item.key, {
                                        notes: event.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field
                            label={`Image description for ${item.file.name}`}
                        >
                            <Input
                                className="frontline-tap"
                                maxLength={255}
                                value={item.alt_text}
                                onChange={(event) =>
                                    patch(item.key, {
                                        alt_text: event.target.value,
                                    })
                                }
                            />
                        </Field>
                    </fieldset>
                    {item.expected !== undefined && !busy && (
                        <p className="text-subtle">
                            This upload has been attempted. Retry preserves the
                            same file and submission details.
                        </p>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        className="frontline-tap"
                        disabled={busy}
                        onClick={() =>
                            setItems((current) =>
                                current.filter(
                                    (pending) => pending.key !== item.key,
                                ),
                            )
                        }
                    >
                        Remove {item.file.name} from upload list
                    </Button>
                </div>
            ))}
            {failure && (
                <p role="alert" className="text-sm text-status-critical">
                    {failure}
                </p>
            )}
            {items.length > 0 && (
                <Button
                    type="button"
                    className="frontline-tap"
                    disabled={busy || !d.can.add_evidence}
                    onClick={send}
                >
                    {busy
                        ? 'Uploading…'
                        : `Upload ${items.length} ${items.length === 1 ? 'file' : 'files'}`}
                </Button>
            )}
        </div>
    );
}

function RemoveEvidence({
    detail,
    attachmentId,
    returnTo,
    onClose,
}: {
    detail: DrillWorkspaceDetail;
    attachmentId: number;
    returnTo: string;
    onClose: () => void;
}) {
    const item = detail.attachments.find((file) => file.id === attachmentId);
    const { form, guards, save, failure } = useDrillCommandForm(
        {},
        detail,
        onClose,
    );
    useSafetyDraftState({ dirty: true, busy: form.processing });
    return (
        <form
            className="space-y-3 rounded-lg border border-border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                if (!item?.can.remove) return;
                form.transform(() => ({
                    ...guards,
                    return_to: returnTo,
                    section: 'evidence',
                }));
                save(
                    'delete',
                    `${DRILL_REGISTER}/${detail.id}/attachments/${attachmentId}`,
                );
            }}
        >
            <p>
                Remove <strong>{item?.original_name ?? 'this file'}</strong>{' '}
                from the current evidence list? Its recorded history will
                remain.
            </p>
            {!item?.can.remove ? (
                <>
                    <p role="alert">
                        This file can no longer be removed. Evidence used for a
                        recorded resolution must be retained.
                    </p>
                    <Button
                        type="button"
                        className="frontline-tap"
                        variant="outline"
                        onClick={onClose}
                    >
                        Back to evidence
                    </Button>
                </>
            ) : (
                <DrillCommandFooter
                    label="Remove from current evidence"
                    processing={form.processing}
                    failure={failure}
                    errors={form.errors}
                    onCancel={onClose}
                />
            )}
        </form>
    );
}

export function DrillEvidence({
    detail: d,
    returnTo,
}: {
    detail: DrillWorkspaceDetail;
    returnTo: string;
}) {
    const [selected, setSelected] = useState<number | null>(null);
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-section-title">Evidence</h2>
                {d.exports.evidence_href && (
                    <a
                        href={d.exports.evidence_href}
                        className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                    >
                        Export evidence list
                    </a>
                )}
            </div>
            {d.can.add_evidence && (
                <DrillEvidenceUpload detail={d} returnTo={returnTo} />
            )}
            {selected !== null && (
                <RemoveEvidence
                    key={selected}
                    detail={d}
                    attachmentId={selected}
                    returnTo={returnTo}
                    onClose={() => setSelected(null)}
                />
            )}
            {d.attachments.length ? (
                <ul className="space-y-3">
                    {d.attachments.map((item) => (
                        <li
                            key={item.id}
                            className="space-y-2 rounded-lg border border-border p-4"
                        >
                            <a
                                href={item.url}
                                className="frontline-tap frontline-focus inline-flex items-center break-all text-primary underline"
                            >
                                {item.original_name}
                            </a>
                            <p className="text-subtle">
                                {formatFileSize(item.size ?? 0)}
                                {item.kind ? ` · ${item.kind}` : ''}
                                {item.uploaded_by_name
                                    ? ` · ${item.uploaded_by_name}`
                                    : ''}{' '}
                                ·{' '}
                                {formatDateTimeLong(
                                    item.created_at,
                                    'Upload time not recorded',
                                )}
                            </p>
                            {item.notes && (
                                <p className="text-sm whitespace-pre-wrap">
                                    {item.notes}
                                </p>
                            )}
                            {item.alt_text && (
                                <p className="text-subtle">
                                    Image description: {item.alt_text}
                                </p>
                            )}
                            {item.can.remove && (
                                <Button
                                    className="frontline-tap"
                                    variant="outline"
                                    onClick={() => setSelected(item.id)}
                                >
                                    Remove {item.original_name}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-subtle">
                    No evidence files are recorded for this drill.
                </p>
            )}
        </div>
    );
}
