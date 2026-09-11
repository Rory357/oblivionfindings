import { ticketFormData } from '@/components/it/ticket-command-wizard';
import { useEffect, useRef, useState } from 'react';
import {
    forgetIntakeDraft,
    intakeDraftLocators,
    retainIntakeDraft,
    type IntakeDraftPurpose,
} from './it-intake-draft-locator';
import {
    draftSnapshotKey,
    readDraftPayload,
    type ItDraftSnapshot,
} from './it-ticket-draft-contract';
import type { useItTicketCommand } from './use-it-ticket-command';
import { useItTicketDraft } from './use-it-ticket-draft';
import { itIntakeDraftMemoryRequestIds } from './use-it-ticket-draft-memory';

export function restoreIntakeDraft<T extends Record<string, unknown>>(
    defaults: T,
    snapshot: ItDraftSnapshot,
): T {
    const values = { ...defaults };
    for (const [key, value] of Object.entries(snapshot.fields)) {
        if (!Object.hasOwn(defaults, key) || key === 'attachments') continue;
        (values as Record<string, unknown>)[key] =
            key.endsWith('_id') && typeof defaults[key] === 'string'
                ? value === null
                    ? 'unassigned'
                    : String(value)
                : key === 'priority' && value === null
                  ? 'automatic'
                  : value === null && Array.isArray(defaults[key])
                    ? []
                    : value === null && typeof defaults[key] === 'string'
                      ? ''
                      : value;
    }
    return values;
}

export function intakeDraftSnapshot(
    purpose: IntakeDraftPurpose,
    data: Record<string, unknown>,
    step: number,
): ItDraftSnapshot {
    const fields = Object.fromEntries(
        Object.entries(data)
            .filter(([key]) => key !== 'attachments')
            .map(([key, value]) => [
                key,
                value === 'unassigned' ||
                (key === 'priority' && value === 'automatic') ||
                (key.endsWith('_id') && value === '')
                    ? null
                    : value,
            ]),
    );
    // Invalid values remain an explicit validation failure, never a partial saved copy.
    return (
        readDraftPayload({ fields, step_index: step }, purpose) ?? {
            fields: fields as ItDraftSnapshot['fields'],
            step_index: step,
        }
    );
}

/** Intake adapter: the existing receipt UUID is also the immutable draft context. */
export function useItIntakeDraft({
    enabled,
    actorId,
    purpose,
    command,
    snapshot,
    dirty,
    files,
    onAccessLost,
}: {
    enabled: boolean;
    actorId: number;
    purpose: IntakeDraftPurpose;
    command: ReturnType<typeof useItTicketCommand>;
    snapshot: ItDraftSnapshot;
    dirty: boolean;
    files?: File[];
    onAccessLost?: () => void;
}) {
    const draft = useItTicketDraft({
        enabled,
        active:
            !command.restoredFromReference && command.state !== 'access_denied',
        actorId,
        context: { purpose, requestUuid: command.requestId ?? '' },
        workingSnapshot: snapshot,
        workingDirty: dirty,
        workingFiles: enabled ? undefined : files,
        acceptSelectedFiles: !enabled,
        workingOutcomeUnknown: !command.canEdit && command.result === null,
        workingSettledOperationToken: command.settledOperationToken,
        onAccessLost,
    });
    const latest = useRef({ draft, snapshot, command });
    latest.current = { draft, snapshot, command };
    const [preparing, setPreparing] = useState(false);
    const [locatorAvailable, setLocatorAvailable] = useState(true);
    const [locators, setLocators] = useState(() =>
        intakeDraftLocators(enabled, actorId, purpose),
    );
    const failedAutosave = useRef<string | null>(null);
    const key = draftSnapshotKey(snapshot);
    useEffect(() => {
        if (Object.keys(draft.errors).length > 0)
            failedAutosave.current = draftSnapshotKey(latest.current.snapshot);
    }, [draft.errors]);
    useEffect(() => {
        if (
            !enabled ||
            (!dirty && !draft.draft?.has_content) ||
            !command.canEdit ||
            draft.state !== 'ready' ||
            draft.busy ||
            preparing ||
            draft.isSaved(snapshot) ||
            failedAutosave.current === key
        )
            return;
        const timeout = setTimeout(async () => {
            const current = latest.current;
            if (
                !current.command.canEdit ||
                current.draft.busy ||
                current.draft.state !== 'ready'
            )
                return;
            if (!(await current.draft.save(current.snapshot)))
                failedAutosave.current = key;
        }, 750);
        return () => clearTimeout(timeout);
        // Read the current callable/snapshot through refs; state changes still cancel the timer.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        enabled,
        dirty,
        command.canEdit,
        draft.state,
        draft.busy,
        preparing,
        key,
    ]);
    useEffect(() => {
        const metadata = draft.draft;
        if (
            !enabled ||
            !metadata ||
            typeof metadata.request_uuid !== 'string' ||
            metadata.request_uuid !== command.requestId
        )
            return;
        if (metadata.state !== 'active' || command.result)
            forgetIntakeDraft(actorId, purpose, metadata.request_uuid);
        else if (
            metadata.has_content ||
            ['outcome_unknown', 'session_expired', 'conflict'].includes(
                draft.state,
            )
        )
            setLocatorAvailable(
                retainIntakeDraft(actorId, purpose, metadata.request_uuid),
            );
        setLocators(intakeDraftLocators(enabled, actorId, purpose));
    }, [
        enabled,
        actorId,
        purpose,
        draft.draft,
        draft.state,
        command.requestId,
        command.result,
    ]);
    useEffect(() => {
        if (command.result) {
            latest.current.draft.clearBrowserWork();
            forgetIntakeDraft(actorId, purpose, command.result.request_uuid);
            setLocators(intakeDraftLocators(enabled, actorId, purpose));
        }
    }, [enabled, actorId, purpose, command.result]);

    const keepReference = () => {
        const requestId = command.requestId;
        if (!enabled || !requestId || draft.draft?.request_uuid !== requestId)
            return false;
        const retained = retainIntakeDraft(actorId, purpose, requestId);
        setLocatorAvailable(retained);
        return retained;
    };
    const saveForClose = async () => {
        if (!enabled || !command.requestId || !(await draft.save(snapshot)))
            return false;
        const retained = retainIntakeDraft(actorId, purpose, command.requestId);
        setLocatorAvailable(retained);
        return retained;
    };
    const discardForClose = async () => {
        const requestId = command.requestId;
        if (!enabled || !requestId || !(await draft.discard())) return false;
        forgetIntakeDraft(actorId, purpose, requestId);
        return true;
    };
    const submit = async (body: Parameters<typeof ticketFormData>[0]) => {
        if (preparing || !command.canEdit || draft.memoryBlocked) return null;
        if (!enabled) return command.submit(ticketFormData(body));
        if (draft.busy || draft.state !== 'ready') return null;
        setPreparing(true);
        try {
            const frozenSnapshot = structuredClone(snapshot);
            if (
                !draft.isSaved(frozenSnapshot) &&
                !(await draft.save(frozenSnapshot))
            )
                return null;
            const reference = draft.submissionReference(frozenSnapshot);
            if (!reference) return null;
            // The command freezes the reference and payload together. Canonical
            // intake consumes that generation and transfers its files atomically.
            return await command.submit(
                ticketFormData({ ...body, attachments: [], ...reference }),
            );
        } finally {
            setPreparing(false);
        }
    };
    const saved = enabled && draft.isSaved(snapshot);
    return {
        draft,
        submit,
        preparing,
        saved,
        locators: [
            ...new Set([
                ...locators,
                ...itIntakeDraftMemoryRequestIds(actorId, purpose),
            ]),
        ],
        locatorAvailable,
        saveForClose,
        discardForClose,
        keepReference,
        canSubmit:
            command.canEdit &&
            !draft.memoryBlocked &&
            !preparing &&
            (!enabled ||
                (!draft.busy &&
                    draft.state === 'ready' &&
                    draft.draft?.capabilities.submit === true)),
        dirty:
            ((dirty ||
                (draft.draft?.has_content === true &&
                    draft.state === 'ready')) &&
                !saved) ||
            (enabled &&
                (!locatorAvailable ||
                    draft.busy ||
                    ['outcome_unknown', 'conflict', 'session_expired'].includes(
                        draft.state,
                    ))),
        enabled,
    };
}
