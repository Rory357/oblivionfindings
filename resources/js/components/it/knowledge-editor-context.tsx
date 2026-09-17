import {
    KbPreview,
    type KbOptions,
    type KbRow,
} from '@/components/it/it-wizards';
import {
    KNOWLEDGE_DOCUMENT_TYPES,
    KNOWLEDGE_SECTIONS,
} from '@/components/it/knowledge-document';
import { KnowledgeRelatedRecords } from '@/components/it/knowledge-related-records';
import { Button } from '@/components/ui/button';
import { formatDateOnly } from '@/lib/datetime';
import axios from 'axios';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

type Context = {
    actor_user_id: number;
    editable: boolean;
    article: KbRow & { lock_version: number };
    options: KbOptions;
};
type Result =
    | { scope: string; signal?: unknown; status: 'ready'; data: Context }
    | {
          scope: string;
          signal?: unknown;
          status: 'loading' | 'failed' | 'denied';
          message?: string;
      };

/** Refresh one editor without changing list filters or silently rebasing its form. */
export function useKnowledgeEditorContext(
    articleId: number | undefined,
    actorId: number,
    refreshSignal?: unknown,
) {
    const scope = `${actorId}:${articleId ?? 'new'}`;
    const latestScope = useRef(scope);
    const latestSignal = useRef(refreshSignal);
    useLayoutEffect(() => {
        latestScope.current = scope;
        latestSignal.current = refreshSignal;
    }, [scope, refreshSignal]);
    const [attempt, setAttempt] = useState(0);
    const [result, setResult] = useState<Result>({ scope, status: 'loading' });
    useEffect(() => {
        if (articleId === undefined) return;
        const controller = new AbortController();
        const requestScope = `${actorId}:${articleId}`;
        setResult({
            scope: requestScope,
            signal: refreshSignal,
            status: 'loading',
        });
        axios
            .get<Context>(`/it/knowledge/${articleId}/editor-context`, {
                params: { actor_user_id: actorId },
                signal: controller.signal,
                timeout: 30000,
                headers: {
                    Accept: 'application/json',
                    'Cache-Control': 'no-cache',
                },
            })
            .then(({ data }) => {
                if (
                    controller.signal.aborted ||
                    latestScope.current !== requestScope ||
                    latestSignal.current !== refreshSignal
                )
                    return;
                if (data?.actor_user_id !== actorId) {
                    setResult({
                        scope: requestScope,
                        signal: refreshSignal,
                        status: 'denied',
                        message:
                            'The signed-in account changed. Reopen Knowledge with your current account.',
                    });
                    return;
                }
                if (
                    data.article?.id !== articleId ||
                    typeof data.article.lock_version !== 'number' ||
                    !Number.isSafeInteger(data.article.lock_version) ||
                    !data.article.can?.author ||
                    typeof data.editable !== 'boolean' ||
                    !Array.isArray(data.options?.sites) ||
                    !Array.isArray(data.options?.owners) ||
                    !Array.isArray(data.options?.services)
                ) {
                    throw new Error(
                        'The current document could not be confirmed. Try loading it again.',
                    );
                }
                setResult({
                    scope: requestScope,
                    signal: refreshSignal,
                    status: 'ready',
                    data,
                });
            })
            .catch((error: unknown) => {
                if (
                    controller.signal.aborted ||
                    latestScope.current !== requestScope ||
                    latestSignal.current !== refreshSignal
                )
                    return;
                const denied =
                    axios.isAxiosError(error) &&
                    [401, 403, 404, 419].includes(error.response?.status ?? 0);
                setResult({
                    scope: requestScope,
                    signal: refreshSignal,
                    status: denied ? 'denied' : 'failed',
                    message: denied
                        ? 'This document or proposal is no longer available to your current account.'
                        : 'Current document details could not be loaded. Your unsaved text is retained. Try again.',
                });
            });
        return () => controller.abort();
    }, [articleId, actorId, attempt, refreshSignal]);
    const current =
        result.scope === scope && result.signal === refreshSignal
            ? result
            : { scope, status: 'loading' as const };
    return {
        ...current,
        refresh: () => {
            setResult({ scope, signal: refreshSignal, status: 'loading' });
            setAttempt((value) => value + 1);
        },
    };
}

export function KnowledgeConflictReview({
    current,
    options,
    editable,
    processing = false,
    onUseVersion,
}: {
    current: KbRow & { lock_version: number };
    options: KbOptions;
    editable: boolean;
    processing?: boolean;
    onUseVersion: (version: number) => void;
}) {
    const content = current.working_copy?.content ?? current;
    const names = (ids: number[]) =>
        ids
            .map(
                (id) =>
                    options.sites.find((site) => Number(site.id) === Number(id))
                        ?.name ?? 'Site no longer available',
            )
            .join(', ');
    return (
        <section
            className="mb-5 space-y-4 rounded-xl border border-status-warning/40 bg-status-warning-bg p-4"
            aria-label="Current saved document"
        >
            <div>
                <h2 className="font-semibold">
                    Review the current saved document
                </h2>
                <p className="mt-1 text-sm">
                    Your proposal is retained in the editor. Compare it with
                    these saved details before continuing.
                </p>
            </div>
            <dl className="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt className="font-medium">Title</dt>
                    <dd>{content.title}</dd>
                </div>
                <div>
                    <dt className="font-medium">State</dt>
                    <dd>
                        {(
                            current.working_copy?.status ?? current.status
                        ).replaceAll('_', ' ')}
                    </dd>
                </div>
                <div>
                    <dt className="font-medium">Type</dt>
                    <dd>
                        {KNOWLEDGE_DOCUMENT_TYPES.find(
                            (type) => type.value === content.document_type,
                        )?.label ?? 'Guide'}
                    </dd>
                </div>
                <div>
                    <dt className="font-medium">Category</dt>
                    <dd>{content.category}</dd>
                </div>
                <div>
                    <dt className="font-medium">Audience</dt>
                    <dd>{content.audience?.replaceAll('_', ' ')}</dd>
                </div>
                <div>
                    <dt className="font-medium">Sites</dt>
                    <dd>
                        {content.site_scope?.length
                            ? names(content.site_scope)
                            : 'No specific Sites'}
                    </dd>
                </div>
                <div>
                    <dt className="font-medium">Review owner</dt>
                    <dd>
                        {options.owners.find(
                            (owner) =>
                                Number(owner.id) ===
                                Number(content.owner_user_id),
                        )?.name ?? 'No eligible owner'}
                    </dd>
                </div>
                <div>
                    <dt className="font-medium">Review due</dt>
                    <dd>{formatDateOnly(content.review_due_at, 'Not set')}</dd>
                </div>
                <div>
                    <dt className="font-medium">Primary service</dt>
                    <dd>
                        {options.services.find(
                            (service) =>
                                Number(service.id) ===
                                Number(content.related_service_id),
                        )?.name ?? 'None selected'}
                    </dd>
                </div>
            </dl>
            <KbPreview body={content.body ?? ''} />
            {KNOWLEDGE_SECTIONS.filter(
                (field) => content.structured_content?.[field.key],
            ).map((field) => (
                <section key={field.key}>
                    <h3 className="text-sm font-semibold">{field.label}</h3>
                    <KbPreview
                        body={content.structured_content?.[field.key] ?? ''}
                    />
                </section>
            ))}
            <KnowledgeRelatedRecords records={content.related_records} />
            {editable ? (
                <Button
                    type="button"
                    variant="outline"
                    disabled={processing}
                    onClick={() => onUseVersion(current.lock_version)}
                >
                    Keep my proposal against this version
                </Button>
            ) : (
                <p className="text-sm font-medium">
                    This document cannot currently be edited. Return it to draft
                    through its lifecycle action before saving changes.
                </p>
            )}
        </section>
    );
}
