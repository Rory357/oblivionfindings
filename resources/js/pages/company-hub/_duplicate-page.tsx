import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { Copy, FileText, Loader2, LockKeyhole, Save } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';

export type HubCopyContext = {
    actor_id: number;
    page_id: number;
    version: number;
    can_duplicate: boolean;
    source: 'draft' | 'published' | null;
    revision_id: number | null;
    media_versions: Record<string, number>;
    blocked_reason: string | null;
};
const reasons: Record<string, string> = {
    home: 'The shared Home page cannot be duplicated.',
    discarded:
        'Recover or save this draft before copying it, or choose the published version.',
    no_snapshot:
        'There is no saved content for this version. Choose another version or save a draft first.',
    content_unavailable:
        'Some content or files are no longer available. Update the source page before making a copy.',
};

export function DuplicateHubPage({
    pageId,
    version,
    actorId,
    title,
    dirty,
    onSave,
    onClose,
}: {
    pageId: number;
    version: number;
    actorId: number | null;
    title: string;
    dirty: boolean;
    onSave: () => Promise<boolean>;
    onClose: () => void;
}) {
    const [source, setSource] = useState<'draft' | 'published'>('draft');
    const [context, setContext] = useState<HubCopyContext | null>(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const [created, setCreated] = useState<string | null>(null);
    const lock = useRef(false);
    useEffect(() => {
        let current = true;
        setLoading(true);
        setContext(null);
        setError('');
        hubRequest<HubCopyContext>(
            `/company-hub/manage/${pageId}/duplicate?source=${source}`,
            'GET',
        )
            .then((result) => {
                if (current) setContext(result);
            })
            .catch((failure) => {
                if (current)
                    setError(
                        failure instanceof Error
                            ? failure.message
                            : 'Copy options could not be loaded.',
                    );
            })
            .finally(() => {
                if (current) setLoading(false);
            });
        return () => {
            current = false;
        };
    }, [pageId, version, source, retry]);
    useEffect(() => {
        if (!busy) return;
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        const unsubscribe = router.on('before', (event) =>
            event.preventDefault(),
        );
        return () => {
            window.removeEventListener('beforeunload', warn);
            unsubscribe();
        };
    }, [busy]);
    const stale =
        !!context &&
        (context.actor_id !== actorId ||
            context.page_id !== pageId ||
            context.version !== version ||
            context.source !== source);
    const canCopy =
        !!context?.can_duplicate &&
        !stale &&
        !dirty &&
        !busy &&
        !loading &&
        !created;
    const copy = async () => {
        if (!canCopy || !context || lock.current) return;
        lock.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<{
                page_id: number;
                edit_url: string;
            }>(`/company-hub/manage/${pageId}/duplicate`, 'POST', {
                actor_id: context.actor_id,
                version: context.version,
                source: context.source,
                revision_id: context.revision_id,
                media_versions: context.media_versions,
            });
            if (
                !Number.isInteger(result.page_id) ||
                result.page_id <= 0 ||
                result.page_id === pageId ||
                result.edit_url !== `/company-hub/manage/${result.page_id}/edit`
            ) {
                throw new Error(
                    'The copy could not be confirmed. Check your drafts before trying again.',
                );
            }
            setCreated(result.edit_url);
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'The page could not be copied.',
            );
            setContext(null);
        } finally {
            lock.current = false;
            setBusy(false);
        }
    };
    const saveFirst = async () => {
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        setError('');
        try {
            if (!(await onSave()))
                setError(
                    'Your changes have not been saved. Close this dialog to resolve the problem before copying.',
                );
        } catch {
            setError(
                'Your changes have not been saved. Close this dialog to resolve the problem before copying.',
            );
        } finally {
            lock.current = false;
            setBusy(false);
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !lock.current) onClose();
            }}
        >
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Copy className="size-5 text-primary" />
                        {created
                            ? 'Your private copy is ready'
                            : 'Duplicate this page'}
                    </DialogTitle>
                    <DialogDescription>
                        {created
                            ? 'Open the copy to give it a new title and make it your own.'
                            : `Start a new private draft using “${title}”.`}
                    </DialogDescription>
                </DialogHeader>
                {!created && (
                    <fieldset
                        disabled={busy}
                        className="grid grid-cols-2 gap-3"
                    >
                        <legend className="mb-3 text-sm font-medium">
                            Choose the version to copy
                        </legend>
                        {(['draft', 'published'] as const).map((value) => (
                            <label
                                key={value}
                                className={`flex cursor-pointer gap-3 rounded-xl border p-4 ${source === value ? 'border-primary bg-primary/5' : 'border-border'}`}
                            >
                                <input
                                    type="radio"
                                    name="copy-source"
                                    checked={source === value}
                                    onChange={() => setSource(value)}
                                    className="mt-1 accent-primary"
                                />
                                <span>
                                    <strong className="block text-sm">
                                        {value === 'draft'
                                            ? 'Saved draft'
                                            : 'Published version'}
                                    </strong>
                                    <span className="mt-1 block text-xs text-muted-foreground">
                                        {value === 'draft'
                                            ? 'Your saved work in progress.'
                                            : 'The version staff can read.'}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                )}
                <div className="space-y-3 rounded-xl border bg-muted/30 p-4 text-sm">
                    <p className="flex gap-3">
                        <FileText className="mt-0.5 size-4 shrink-0 text-primary" />
                        Copy the layout, hero, content and page-owned files.
                    </p>
                    <p className="flex gap-3">
                        <LockKeyhole className="mt-0.5 size-4 shrink-0 text-primary" />
                        The copy belongs to you and starts as an unpublished
                        draft. Reviews, reactions, history and navigation
                        placement start fresh.
                    </p>
                </div>
                {loading && !created && (
                    <p
                        role="status"
                        className="flex items-center gap-2 text-sm"
                    >
                        <Loader2 className="size-4 animate-spin" />
                        Checking this version…
                    </p>
                )}
                {dirty && !created && (
                    <p className="text-sm text-muted-foreground">
                        Save your latest changes before making a copy, so your
                        work stays safe when you open the new draft.
                    </p>
                )}
                {stale && (
                    <p role="alert" className="text-sm text-destructive">
                        This page or your signed-in account has changed. Close
                        this dialog and reload the editor.
                    </p>
                )}
                {!loading && context && !context.can_duplicate && (
                    <p role="status" className="text-sm text-muted-foreground">
                        {reasons[context.blocked_reason ?? ''] ??
                            'This version cannot currently be copied.'}
                    </p>
                )}
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <DialogFooter>
                    <Button variant="outline" disabled={busy} onClick={onClose}>
                        {created ? 'Keep editing original' : 'Cancel'}
                    </Button>
                    {created ? (
                        <Button onClick={() => router.visit(created)}>
                            Open private copy
                        </Button>
                    ) : dirty ? (
                        <Button
                            disabled={busy}
                            onClick={() => void saveFirst()}
                        >
                            <Save className="size-4" />
                            {busy ? 'Saving…' : 'Save latest changes'}
                        </Button>
                    ) : (
                        <>
                            {!context && !loading && (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => setRetry((n) => n + 1)}
                                >
                                    Check again
                                </Button>
                            )}
                            <Button
                                disabled={!canCopy}
                                onClick={() => void copy()}
                            >
                                <Copy className="size-4" />
                                {busy
                                    ? 'Creating copy…'
                                    : 'Create private copy'}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
