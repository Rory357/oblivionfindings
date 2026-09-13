import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useItApprovalHistory } from '@/hooks/use-it-approval-history';
import { ChevronDown, History } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import { TicketApprovalRecord } from './ticket-approval-record';

function watchFragment(changed: () => void) {
    window.addEventListener('hashchange', changed);
    window.addEventListener('popstate', changed);
    return () => {
        window.removeEventListener('hashchange', changed);
        window.removeEventListener('popstate', changed);
    };
}
const currentFragment = () => window.location.hash;
const serverFragment = () => '';

export function TicketApprovalHistory({
    actorId,
    ticketId,
    version,
    total,
    currentApprovalId,
    onAccessLost,
    onSessionExpired,
}: {
    actorId: number;
    ticketId: number;
    version: number;
    total: number;
    currentApprovalId: number | null;
    onAccessLost: () => void;
    onSessionExpired?: () => void;
}) {
    const fragment = useSyncExternalStore(
        watchFragment,
        currentFragment,
        serverFragment,
    );
    const match = /^#approval-([1-9]\d*)$/.exec(fragment);
    const linkedId = match ? Number(match[1]) : null;
    const targetId =
        linkedId !== currentApprovalId && Number.isSafeInteger(linkedId)
            ? (linkedId ?? undefined)
            : undefined;
    const [open, setOpen] = useState(false);
    const requested = useRef<string | null>(null);
    const refreshButton = useRef<HTMLButtonElement>(null);
    const cancelButton = useCallback((node: HTMLButtonElement | null) => {
        if (!node) return;
        return () => {
            if (document.activeElement === node) refreshButton.current?.focus();
        };
    }, []);
    const history = useItApprovalHistory({
        actorId,
        ticketId,
        enabled: open,
        targetId,
        onAccessLost,
        onSessionExpired,
    });
    const stale = history.page !== null && history.page.version < version;
    const loadHistory = history.load;
    useEffect(() => {
        if (targetId !== undefined) setOpen(true);
    }, [targetId]);
    useEffect(() => {
        if (!open || targetId === undefined) {
            requested.current = null;
            return;
        }
        const identity = `${actorId}:${ticketId}:${targetId}`;
        if (requested.current === identity) return;
        requested.current = identity;
        void loadHistory();
    }, [actorId, ticketId, targetId, open, loadHistory]);
    useEffect(() => {
        if (
            stale ||
            targetId === undefined ||
            !history.page?.records.some((record) => record.id === targetId)
        )
            return;
        const record = document.getElementById(`approval-${targetId}`);
        record?.scrollIntoView({ block: 'start' });
        record?.focus({ preventScroll: true });
    }, [history.page, stale, targetId]);
    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="rounded-xl border border-border"
        >
            <CollapsibleTrigger asChild>
                <Button
                    variant="ghost"
                    className="h-auto w-full justify-start gap-2 px-4 py-3"
                >
                    <History className="h-4 w-4" aria-hidden="true" />
                    Approval history · {total}{' '}
                    {total === 1 ? 'request' : 'requests'}
                    <ChevronDown
                        className="ml-auto h-4 w-4"
                        aria-hidden="true"
                    />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent className="space-y-4 border-t border-border p-4">
                {targetId !== undefined && (
                    <p className="text-sm font-medium">
                        Linked approval request #{targetId}
                    </p>
                )}
                {history.error && (
                    <p role="alert" className="text-sm text-status-critical">
                        {history.error}
                    </p>
                )}
                {stale && (
                    <p role="status" className="text-sm">
                        The ticket changed. Refresh approval history before
                        relying on earlier records.
                    </p>
                )}
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        ref={refreshButton}
                        variant="outline"
                        aria-disabled={history.busy}
                        className="aria-disabled:opacity-50"
                        onClick={() => {
                            if (!history.busy) void history.load();
                        }}
                    >
                        {history.page
                            ? 'Refresh approval history'
                            : history.error
                              ? 'Retry history check'
                              : 'Load approval history'}
                    </Button>
                    <Button
                        variant="outline"
                        aria-disabled={
                            history.busy || !history.page?.nextPage || stale
                        }
                        className="aria-disabled:opacity-50"
                        onClick={() => {
                            if (
                                !history.busy &&
                                history.page?.nextPage &&
                                !stale
                            )
                                void history.load(true);
                        }}
                    >
                        Older requests
                    </Button>
                    {history.busy && (
                        <>
                            <p role="status" className="text-sm">
                                Loading approval history…
                            </p>
                            <Button
                                ref={cancelButton}
                                variant="outline"
                                onClick={history.cancel}
                            >
                                Cancel history check
                            </Button>
                        </>
                    )}
                </div>
                {history.page && !stale && (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Page {history.page.page} · {history.page.total}{' '}
                            recorded{' '}
                            {history.page.total === 1 ? 'request' : 'requests'}
                            {!history.page.nextPage && ' · No older requests'}
                        </p>
                        {history.page.records.map((record) => (
                            <div
                                key={record.id}
                                className="border-b border-border pb-4 last:border-0"
                            >
                                <TicketApprovalRecord
                                    record={record}
                                    anchor={record.id !== currentApprovalId}
                                />
                            </div>
                        ))}
                        {!history.page.total && (
                            <p className="text-sm">
                                No approval requests have been recorded.
                            </p>
                        )}
                    </>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}
