import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

type Upload = {
    id: number;
    name: string;
    state: string;
    expected_version: number;
    created_at: string;
    can_retry: boolean;
};
export type UnfinishedUploads = {
    files: Upload[];
    next_before_id: number | null;
};

export function KnowledgeUnfinishedUploads({
    initial,
    articleId,
    actorId,
    editable = true,
    onBusy,
    onSaved,
}: {
    initial: UnfinishedUploads;
    articleId: number;
    actorId: number;
    editable?: boolean;
    onBusy?: (busy: boolean) => void;
    onSaved?: () => void;
}) {
    const [rows, setRows] = useState(initial.files);
    const [next, setNext] = useState(initial.next_before_id);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [concealed, setConcealed] = useState(false);
    const active = useRef(true);
    useEffect(() => {
        active.current = true;
        return () => {
            active.current = false;
        };
    }, []);
    const refresh = (committed = false) =>
        router.reload({
            only: ['article', 'files', 'fileHistory', 'unfinishedUploads'],
            onSuccess: () => {
                if (committed) onSaved?.();
            },
            onFinish: () => onBusy?.(false),
        });
    const failed = (cause: unknown) => {
        if (!active.current) return;
        const status = axios.isAxiosError(cause)
            ? cause.response?.status
            : undefined;
        if (status && [401, 403, 404, 419].includes(status)) setConcealed(true);
        const message = axios.isAxiosError(cause)
            ? cause.response?.data?.errors?.file?.[0]
            : undefined;
        setError(
            typeof message === 'string'
                ? message
                : 'The upload result could not be confirmed. Check upload status before starting another upload.',
        );
    };
    const command = async (file: Upload, action: 'retry' | 'dismiss') => {
        if (busy) return;
        if (action === 'retry' && !editable) return;
        setBusy(true);
        onBusy?.(true);
        setError('');
        try {
            const { data } = await axios.post(
                `/it/knowledge/${articleId}/files/${file.id}/${action}`,
                {
                    actor_user_id: actorId,
                    lock_version: file.expected_version,
                },
            );
            if (!active.current) return;
            if (
                data.actor_user_id !== actorId ||
                data.article_id !== articleId ||
                data.file_id !== file.id ||
                data.state !== (action === 'retry' ? 'ready' : 'abandoned')
            ) {
                setConcealed(true);
                throw new Error('Upload context changed.');
            }
            refresh(action === 'retry');
        } catch (cause) {
            failed(cause);
            onBusy?.(false);
        } finally {
            if (active.current) setBusy(false);
        }
    };
    const loadOlder = async () => {
        if (!next || busy) return;
        setBusy(true);
        setError('');
        try {
            const { data } = await axios.get<
                UnfinishedUploads & {
                    actor_user_id: number;
                    article_id: number;
                }
            >(`/it/knowledge/${articleId}/files/unfinished`, {
                params: { before_id: next },
            });
            if (!active.current) return;
            if (
                data.actor_user_id !== actorId ||
                data.article_id !== articleId ||
                !Array.isArray(data.files)
            ) {
                setConcealed(true);
                throw new Error('Upload context changed.');
            }
            setRows((current) => [
                ...current,
                ...data.files.filter(
                    (file) => !current.some((row) => row.id === file.id),
                ),
            ]);
            setNext(data.next_before_id);
        } catch (cause) {
            failed(cause);
        } finally {
            if (active.current) setBusy(false);
        }
    };
    return (
        <section className="space-y-3 rounded-xl border border-border bg-card p-5">
            <h2 className="text-section-title">Your unfinished uploads</h2>
            <p className="text-subtle">
                These files have not been added to the document. Retry uses the
                same file version. Removing an unfinished upload keeps its
                private retention record.
            </p>
            {!concealed && rows.length === 0 && (
                <p className="text-subtle">No unfinished uploads.</p>
            )}
            {!concealed &&
                rows.map((file) => (
                    <div
                        key={file.id}
                        className="flex flex-wrap items-center justify-between gap-3 border-t border-border py-3"
                    >
                        <div className="min-w-0">
                            <p className="text-sm font-medium break-words">
                                {file.name}
                            </p>
                            <p className="text-caption">
                                {formatDateTime(file.created_at)} ·{' '}
                                {file.state === 'quarantined'
                                    ? 'Quarantined'
                                    : file.state === 'integrity_failed'
                                      ? 'File verification failed'
                                      : file.state === 'scan_unavailable'
                                        ? 'Waiting for malware check'
                                        : 'Upload interrupted'}
                            </p>
                            {!file.can_retry && (
                                <p className="text-caption">
                                    Review the current document and choose
                                    another upload.
                                </p>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {file.can_retry && editable && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => void command(file, 'retry')}
                                >
                                    Retry check and save
                                </Button>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() => void command(file, 'dismiss')}
                            >
                                Remove unfinished upload
                            </Button>
                        </div>
                    </div>
                ))}
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={busy}
                    onClick={() => refresh()}
                >
                    Check upload status
                </Button>
                {!concealed && next && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={() => void loadOlder()}
                    >
                        Load older unfinished uploads
                    </Button>
                )}
            </div>
        </section>
    );
}
