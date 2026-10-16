import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { registerDraftHistoryGuard } from '@/lib/draft-history';
import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { Loader2, Save } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export function useEditorLeave({
    dirty,
    saving,
    save,
    discardLocal,
    onBlocked,
}: {
    dirty: boolean;
    saving: boolean;
    save: () => Promise<boolean>;
    discardLocal: () => void;
    onBlocked: () => void;
}) {
    const [visit, setVisit] = useState<PendingVisit | null>(null);
    const [history, setHistory] = useState<{
        run: () => void;
        cancel: () => void;
    } | null>(null);
    const historyIntent = useRef<typeof history>(null);
    const [leaving, setLeaving] = useState(false);
    const [error, setError] = useState('');
    const allowed = useRef<string | null>(null);
    const lock = useRef(false);
    const alive = useRef(false);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    // Keep the same guard lease while Save and leave makes the draft clean.
    // Replacing it would invalidate the exact history destination being approved.
    const historyGuarded = dirty || saving || leaving || history !== null;
    useEffect(() => {
        if (!historyGuarded) return;
        return registerDraftHistoryGuard((run, cancel) => {
            if (lock.current && !historyIntent.current) {
                cancel();
                return;
            }
            // A further Back/Forward attempt replaces the history helper's
            // decision. Keep its current continuation while the save finishes.
            historyIntent.current = { run, cancel };
            setVisit(null);
            setError('');
            setHistory({ run, cancel });
        });
    }, [historyGuarded]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (!dirty && !saving && !lock.current) return;
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        const unsubscribe = router.on('before', (event) => {
            const next = event.detail.visit;
            if (allowed.current === next.url.href && next.method === 'get') {
                allowed.current = null;
                return;
            }
            if (lock.current) {
                event.preventDefault();
                return;
            }
            if (!dirty && !saving) return;
            event.preventDefault();
            if (next.method !== 'get') {
                onBlocked();
                return;
            }
            setError('');
            historyIntent.current?.cancel();
            historyIntent.current = null;
            setHistory(null);
            setVisit(next);
        });
        return () => {
            window.removeEventListener('beforeunload', warn);
            unsubscribe();
        };
    }, [dirty, saving, onBlocked]);

    const leave = async (keep: boolean) => {
        if ((!visit && !history) || saving || lock.current) return;
        lock.current = true;
        setLeaving(true);
        setError('');
        const saved = !keep || (await save());
        if (!alive.current) return;
        if (!saved) {
            lock.current = false;
            setLeaving(false);
            setError(
                'Your changes have not been saved. Stay here to fix the problem, or leave without these changes.',
            );
            return;
        }
        if (!keep) discardLocal();
        if (history) {
            historyIntent.current?.run();
            historyIntent.current = null;
            lock.current = false;
            setLeaving(false);
            setHistory(null);
            return;
        }
        if (!visit) return;
        allowed.current = visit.url.href;
        router.visit(visit.url, {
            ...visit,
            onFinish: () => {
                allowed.current = null;
                lock.current = false;
                setLeaving(false);
                setVisit(null);
            },
        });
    };
    const stay = () => {
        if (lock.current) return;
        historyIntent.current?.cancel();
        historyIntent.current = null;
        setHistory(null);
        setVisit(null);
    };
    return {
        pending: !!visit || !!history,
        dialog: (
            <Dialog
                open={!!visit || !!history}
                onOpenChange={(open) => {
                    if (!open) stay();
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Keep your latest changes?</DialogTitle>
                        <DialogDescription>
                            Choose what to do before leaving the editor. Leaving
                            without changes keeps your last saved draft and
                            published page.
                        </DialogDescription>
                    </DialogHeader>
                    {saving && (
                        <p
                            role="status"
                            className="flex items-center gap-2 text-sm"
                        >
                            <Loader2 className="size-4 animate-spin" />
                            Finishing the current save…
                        </p>
                    )}
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    <DialogFooter className="gap-2">
                        <Button
                            variant="ghost"
                            disabled={leaving}
                            onClick={stay}
                        >
                            Keep editing
                        </Button>
                        <Button
                            variant="outline"
                            disabled={saving || leaving}
                            onClick={() => void leave(false)}
                        >
                            Leave without changes
                        </Button>
                        <Button
                            disabled={saving || leaving}
                            onClick={() => void leave(true)}
                        >
                            <Save className="size-4" />
                            {leaving ? 'Leaving…' : 'Save and leave'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        ),
    };
}
