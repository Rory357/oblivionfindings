import { ReviewCard, ReviewRow, SelectInput } from '@/components/hr/wizard';
import { TicketDraftRecovery } from '@/components/it/ticket-draft-recovery';
import { Button } from '@/components/ui/button';
import { FileDropzone, formatFileSize } from '@/components/ui/file-dropzone';
import type {
    ItDraftResumed,
    ItDraftSnapshot,
} from '@/hooks/it-ticket-draft-contract';
import type { useItIntakeDraft } from '@/hooks/use-it-intake-draft';
import type { useItTicketCommand } from '@/hooks/use-it-ticket-command';
import type {
    ItDraftBrowserRestored,
    ItTicketDraftClient,
} from '@/hooks/use-it-ticket-draft';
import { IT_ATTACHMENT_ACCEPT } from '@/lib/it-attachments';
import { FileText } from 'lucide-react';
import { useState } from 'react';

const labels = {
    title: 'Title',
    description: 'Description',
    category: 'Category',
    subcategory: 'Subcategory',
    site_id: 'Site',
    impact: 'Impact',
    urgency: 'Urgency',
    priority: 'Priority override',
    priority_reason: 'Priority reason',
    routing_reason: 'Routing reason',
    assigned_to_user_id: 'Assignee',
    requester_user_id: 'Requested for',
    it_service_id: 'Service',
    asset_id: 'Asset',
    device_id: 'Device',
    provisioning_request_id: 'Provisioning request',
    work_type: 'Work type',
    watchers: 'Watchers',
} as const;
export function TicketIntakeDraftPanel({
    intake,
    command,
    snapshot,
    dirty,
    onResume,
    onResumeMemory,
    onDiscarded,
    onStartNew,
    optionLabels = {},
}: {
    intake: ReturnType<typeof useItIntakeDraft>;
    command: ReturnType<typeof useItTicketCommand>;
    snapshot: ItDraftSnapshot;
    dirty: boolean;
    onResume: (saved: ItDraftResumed) => void;
    onResumeMemory?: (restored: ItDraftBrowserRestored) => void;
    onDiscarded: () => void;
    onStartNew: () => void;
    optionLabels?: Record<string, Record<number, string>>;
}) {
    if (
        !intake.enabled &&
        !intake.draft.memoryNotices.length &&
        !intake.draft.memoryWarning &&
        !intake.draft.message
    )
        return null;
    const renderValue = (key: keyof typeof labels, value: unknown) =>
        value === null || value === undefined || value === ''
            ? 'Not selected'
            : Array.isArray(value)
              ? value
                    .map((id) => optionLabels[key]?.[id] ?? `Record ${id}`)
                    .join(', ') || 'None'
              : typeof value === 'number'
                ? (optionLabels[key]?.[value] ?? `Record ${value}`)
                : String(value).replaceAll('_', ' ');
    return (
        <div className="mb-5 space-y-3">
            {intake.locators.some((id) => id !== command.requestId) && (
                <div className="space-y-2">
                    <p className="text-sm font-medium">Saved draft context</p>
                    <fieldset disabled={!command.canEdit || intake.draft.busy}>
                        <SelectInput
                            value={command.requestId ?? ''}
                            ariaLabel="Saved draft context"
                            placeholder="Choose a saved draft"
                            onChange={(id) => command.selectDraftRequest(id)}
                            options={[
                                {
                                    value: command.requestId ?? 'current',
                                    label: 'Current draft',
                                },
                                ...intake.locators
                                    .filter((id) => id !== command.requestId)
                                    .map((id) => ({
                                        value: id,
                                        label: `Saved draft ${id}`,
                                    })),
                            ]}
                        />
                    </fieldset>
                    <p className="text-xs text-muted-foreground">
                        Selecting a context checks its status. Resume remains an
                        explicit choice; your current form text stays here.
                    </p>
                </div>
            )}
            {!intake.locatorAvailable && (
                <p role="alert" className="text-sm text-status-warning">
                    This browser could not retain the draft reference. Keep this
                    form open. Draft reference: {command.requestId}
                </p>
            )}
            <TicketDraftRecovery
                draft={intake.draft}
                snapshot={snapshot}
                hasLocalChanges={dirty}
                onResume={onResume}
                onResumeMemory={onResumeMemory}
                onDiscarded={onDiscarded}
                onStartNew={onStartNew}
                onRequestRecovery={() => void command.recover()}
                renderReview={(saved) => (
                    <ReviewCard icon={FileText} title="Saved ticket details">
                        {(Object.keys(labels) as (keyof typeof labels)[])
                            .filter((key) =>
                                Object.hasOwn(saved.payload.fields, key),
                            )
                            .map((key) => (
                                <ReviewRow
                                    key={key}
                                    label={labels[key]}
                                    value={
                                        <span className="break-words whitespace-pre-wrap">
                                            {renderValue(
                                                key,
                                                saved.payload.fields[key],
                                            )}
                                        </span>
                                    }
                                />
                            ))}
                        <ReviewRow
                            label="Saved files"
                            value={
                                saved.attachments.length
                                    ? saved.attachments
                                          .map(
                                              (file) =>
                                                  `${file.name} (${file.state})`,
                                          )
                                          .join(', ')
                                    : 'None'
                            }
                        />
                    </ReviewCard>
                )}
            />
        </div>
    );
}

/** Files are staged through the canonical draft API, one recoverable identity at a time. */
export function TicketDraftFiles({
    draft,
    disabled = false,
}: {
    draft: ItTicketDraftClient;
    disabled?: boolean;
}) {
    const [selectionError, setSelectionError] = useState<string | null>(null);
    if (['session_expired', 'access_denied'].includes(draft.state))
        return (
            <p className="text-sm text-muted-foreground">
                Saved files are hidden until your access is checked again.
            </p>
        );
    const unavailable =
        disabled ||
        draft.busy ||
        draft.memoryBlocked ||
        draft.state !== 'ready' ||
        !draft.draft?.capabilities.save;
    return (
        <div className="space-y-3">
            <FileDropzone
                multiple={false}
                disabled={unavailable || draft.attachments.length >= 5}
                title="Add a photo or file to this draft"
                hint="Choose one at a time, up to 5 files of 10 MB each"
                accept={IT_ATTACHMENT_ACCEPT}
                onFiles={(files) => {
                    if (unavailable) return;
                    if (files.length !== 1) {
                        setSelectionError(
                            'Choose one file at a time so each upload has a recoverable result.',
                        );
                        return;
                    }
                    setSelectionError(null);
                    void draft.upload(files[0]);
                }}
            />
            {selectionError && (
                <p role="alert" className="text-sm text-status-warning">
                    {selectionError}
                </p>
            )}
            {draft.attachments.map((file) => (
                <div
                    key={file.id}
                    className="flex items-center justify-between gap-3 rounded-lg border border-border p-3 text-sm"
                >
                    <div className="min-w-0">
                        <p className="font-medium break-words">{file.name}</p>
                        <p className="text-xs text-muted-foreground">
                            {formatFileSize(file.size)} ·{' '}
                            {file.state === 'ready'
                                ? 'Saved to draft'
                                : 'Upload incomplete'}
                        </p>
                    </div>
                    <div className="flex shrink-0 gap-2">
                        {file.download_url && (
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href={file.download_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Open
                                </a>
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={unavailable}
                            onClick={() => void draft.remove(file.id)}
                            aria-label={`Remove ${file.name}`}
                        >
                            Remove
                        </Button>
                    </div>
                </div>
            ))}
        </div>
    );
}
