import type { ItDraftSnapshot } from '@/hooks/it-ticket-draft-contract';
import { useItTicketDraft } from '@/hooks/use-it-ticket-draft';
import { useEffect, useRef, useState } from 'react';
import type { CatalogItem, CatalogValue } from './catalogue-request-fields';
import type { CatalogueSubmissionIdentity } from './catalogue-submission-contract';
import { isCatalogueSubmissionIdentity } from './catalogue-submission-contract';
import type { useCatalogueSubmission } from './use-catalogue-submission';

const locatorKey = (actorId: number, itemId: number) =>
    `it.catalogue.draft.v1.actor.${actorId}.item.${itemId}`;
export function catalogueDraftIdentity(
    enabled: boolean,
    actorId: number,
    itemId: number,
): CatalogueSubmissionIdentity | null {
    if (!enabled) return null;
    try {
        const value: unknown = JSON.parse(
            sessionStorage.getItem(locatorKey(actorId, itemId)) ?? 'null',
        );
        return isCatalogueSubmissionIdentity(value) &&
            value.actorId === actorId &&
            value.itemId === itemId
            ? value
            : null;
    } catch {
        return null;
    }
}

/** Uses the canonical encrypted draft client; this stores only an actor-bound locator. */
export function useCatalogueDraft({
    enabled,
    actorId,
    item,
    command,
    values,
    siteId,
    requestedForId,
    step,
    dirty,
    onDenied,
}: {
    enabled: boolean;
    actorId: number;
    item: CatalogItem;
    command: ReturnType<typeof useCatalogueSubmission>;
    values: Record<string, CatalogValue>;
    siteId: number | null;
    requestedForId: number | null;
    step: number;
    dirty: boolean;
    onDenied: () => void;
}) {
    const snapshot: ItDraftSnapshot = {
        fields: {
            catalog_item_id: item.id,
            schema_version: command.identity.schemaVersion,
            catalogue_values: JSON.stringify(
                Object.fromEntries(
                    Object.entries(values).map(([key, value]) => [
                        key,
                        item.form_schema.fields?.find(
                            (field) => field.key === key,
                        )?.type === 'attachment'
                            ? []
                            : value,
                    ]),
                ),
            ),
            site_id: siteId,
            requested_for_user_id: requestedForId,
        },
        step_index: step,
    };
    const draft = useItTicketDraft({
        enabled,
        active: enabled && command.editing,
        actorId,
        context: {
            purpose: 'catalogue_request',
            requestUuid: command.identity.requestUuid,
            catalogItemId: item.id,
            schemaVersion: command.identity.schemaVersion,
        },
        workingSnapshot: snapshot,
        workingDirty: dirty,
        workingOutcomeUnknown: !command.editing && !command.result,
        onAccessLost: onDenied,
    });
    const [preparing, setPreparing] = useState(false);
    const [locatorError, setLocatorError] = useState(false);
    const latest = useRef({ draft, snapshot, command });
    latest.current = { draft, snapshot, command };
    const keepReference = () => {
        try {
            sessionStorage.setItem(
                locatorKey(actorId, item.id),
                JSON.stringify(command.identity),
            );
            if (!catalogueDraftIdentity(true, actorId, item.id))
                throw new Error('Recovery locator unavailable');
            setLocatorError(false);
            return true;
        } catch {
            setLocatorError(true);
            return false;
        }
    };
    const save = async () => {
        if (
            !enabled ||
            preparing ||
            draft.busy ||
            !command.editing ||
            !keepReference()
        )
            return false;
        return draft.save(snapshot);
    };
    const failedAutosave = useRef<string | null>(null);
    const snapshotKey = JSON.stringify(snapshot);
    useEffect(() => {
        if (
            !enabled ||
            !dirty ||
            !command.editing ||
            draft.state !== 'ready' ||
            draft.busy ||
            preparing ||
            draft.isSaved(snapshot) ||
            failedAutosave.current === snapshotKey
        )
            return;
        const timer = setTimeout(async () => {
            if (!(await save())) failedAutosave.current = snapshotKey;
        }, 750);
        return () => clearTimeout(timer);
        // Snapshot identity and draft state control the autosave boundary.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        enabled,
        dirty,
        command.editing,
        draft.state,
        draft.busy,
        preparing,
        snapshotKey,
    ]);
    useEffect(() => {
        if (!command.result && command.phase !== 'cancelled') return;
        latest.current.draft.clearBrowserWork();
        try {
            sessionStorage.removeItem(locatorKey(actorId, item.id));
        } catch {
            /* Terminal references remain recoverable. */
        }
    }, [command.result, command.phase, actorId, item.id]);
    const submit = async () => {
        if (
            preparing ||
            (enabled && (draft.busy || draft.memoryBlocked)) ||
            !command.editing
        )
            return;
        if (!enabled)
            return command.submit(values, siteId, requestedForId ?? undefined);
        if (
            draft.state !== 'ready' ||
            command.identity.schemaVersion !== item.form_schema_version
        )
            return;
        setPreparing(true);
        try {
            if (!keepReference()) return;
            if (!draft.isSaved(snapshot) && !(await draft.save(snapshot)))
                return;
            const reference = draft.submissionReference(snapshot);
            if (!reference) return;
            const staged = latest.current.draft.attachments;
            return command.submit(values, siteId, requestedForId ?? undefined, {
                ...reference,
                staged_attachment_ids: staged.map((file) => file.id),
            });
        } finally {
            setPreparing(false);
        }
    };
    return {
        draft,
        snapshot,
        save,
        submit,
        preparing,
        locatorError,
        upload: async (file: File, field: string) =>
            keepReference() && draft.upload(file, field),
        schemaChanged:
            command.identity.schemaVersion !== item.form_schema_version,
        canEdit:
            !enabled ||
            ((draft.state === 'ready' ||
                (draft.state === 'saving' && draft.pending === 'save')) &&
                !draft.memoryBlocked &&
                !preparing &&
                command.identity.schemaVersion === item.form_schema_version),
        forget: () => {
            try {
                sessionStorage.removeItem(locatorKey(actorId, item.id));
            } catch {
                /* Metadata only. */
            }
        },
    };
}
