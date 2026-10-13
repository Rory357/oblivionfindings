import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import {
    rememberOverlayActiveElement,
    restoreOverlayFocus,
} from '@/components/ui/overlay-focus-return';
import { ExportDialog } from '@/pages/emar/reports/_export-dialog';
import type { ExportContext } from '@/pages/emar/reports/_types';
import { useEffect, useRef, useState } from 'react';

/** Round entry points use the same explicit purpose and review as Reports. */
export function RoundSheetExportDialog({
    roundId,
    onClose,
}: {
    roundId: number;
    onClose: () => void;
}) {
    const [context, setContext] = useState<ExportContext | null>(null);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    const [online, setOnline] = useState(navigator.onLine);
    const closing = useRef(false);
    const [returnFocus] = useState(() => {
        const remembered = {
            current: {
                target: null as HTMLElement | null,
                owner: null as HTMLElement | null,
            },
        };
        rememberOverlayActiveElement(remembered);
        return remembered;
    });
    const close = () => {
        closing.current = true;
        onClose();
    };
    const restoreFocus = (event: Event) => {
        event.preventDefault();
        restoreOverlayFocus(returnFocus);
    };

    useEffect(() => {
        const update = () => setOnline(navigator.onLine);
        window.addEventListener('online', update);
        window.addEventListener('offline', update);
        return () => {
            window.removeEventListener('online', update);
            window.removeEventListener('offline', update);
        };
    }, []);

    useEffect(() => {
        const abort = new AbortController();
        setContext(null);
        setError('');
        const params = new URLSearchParams({
            type: 'round_sheet',
            round_id: String(roundId),
        });
        fetch(`/emar/reports/export-options?${params}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: abort.signal,
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        'This round could not be opened for export. Check your access or try again.',
                    );
                const loaded: ExportContext = await response.json();
                if (
                    loaded.selected_round?.id !== roundId ||
                    !loaded.exports.some(
                        (item) => item.type === 'round_sheet' && item.allowed,
                    )
                ) {
                    throw new Error(
                        'This round is not available for export with your current access.',
                    );
                }
                if (!abort.signal.aborted) setContext(loaded);
            })
            .catch((reason) => {
                if (!abort.signal.aborted)
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Export options could not be loaded. Try again.',
                    );
            });
        return () => abort.abort();
    }, [roundId, attempt]);

    const option = context?.exports.find((item) => item.type === 'round_sheet');
    if (context?.selected_round?.id === roundId && option) {
        return (
            <ExportDialog
                key={roundId}
                option={option}
                props={context}
                online={online}
                onClose={close}
                onCloseAutoFocus={restoreFocus}
            />
        );
    }
    return (
        <SettingsModal
            frontline
            title="Make round sheet"
            description="Confirm the selected round and record why you need the file."
            onClose={close}
            onCloseAutoFocus={(event) => {
                // The loaded wizard owns focus unless the person closed this dialog.
                if (!closing.current) event.preventDefault();
                else restoreFocus(event);
            }}
            footer={
                <>
                    <Button variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    {error && (
                        <Button
                            disabled={!online}
                            onClick={() => setAttempt((value) => value + 1)}
                        >
                            Try again
                        </Button>
                    )}
                </>
            }
        >
            {error ? (
                <p role="alert" className="text-subtle text-status-critical">
                    {error}
                </p>
            ) : (
                <p role="status" className="text-subtle">
                    Loading the selected round…
                </p>
            )}
            {!online && (
                <p role="status" className="text-subtle">
                    You’re offline. Reconnect to load the export options.
                </p>
            )}
        </SettingsModal>
    );
}
