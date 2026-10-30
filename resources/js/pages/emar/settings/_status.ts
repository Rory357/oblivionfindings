/* The page's status message (Fleet Settings pattern), held back while a
 * dialog shows its own confirmation — e.g. the "Review the defaults" success
 * pane — so the same news isn't shown twice. It appears once the dialog
 * closes. */
import { useCallback, useRef, useState } from 'react';

export function useStatusMessage() {
    const [message, setMessage] = useState<string | null>(null);
    const holding = useRef(false);
    const held = useRef<string | null>(null);

    const show = useCallback((next: string | null) => {
        if (next && holding.current) {
            held.current = next;
            return;
        }
        setMessage(next);
    }, []);
    /** A dialog with its own success pane is open: keep new messages until it closes. */
    const hold = useCallback(() => {
        holding.current = true;
    }, []);
    /** The dialog closed: show anything kept back. */
    const release = useCallback(() => {
        holding.current = false;
        if (held.current) {
            setMessage(held.current);
            held.current = null;
        }
    }, []);

    return { message, show, hold, release };
}
