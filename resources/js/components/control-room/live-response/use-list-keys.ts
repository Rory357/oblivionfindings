import { useEffect, useRef } from 'react';

/** Typing, a pop-up or an open menu owns the keyboard; the list keeps out. */
function keyboardIsBusy(event: KeyboardEvent): boolean {
    if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return true;
    const target = event.target as HTMLElement | null;
    if (
        target?.closest(
            'input, textarea, select, [contenteditable="true"], [role="combobox"], [role="listbox"]',
        )
    )
        return true;
    return Boolean(
        document.querySelector(
            '[role="dialog"], [role="alertdialog"], [role="menu"], [data-radix-popper-content-wrapper]',
        ),
    );
}

/**
 * Keyboard for a Live response list (Stephan, 24 Oct 2026: "faster for
 * operators"): ↑ / ↓ (or K / J) move the selection, A opens Acknowledge for
 * the selected alert, Enter opens its full record. Ignored while typing, in a
 * pop-up or menu, or when a modifier key is held.
 */
export function useListKeys({
    ids,
    selectedId,
    onSelect,
    onOpen,
    onAcknowledge,
}: {
    /** The rows in the order they are shown. */
    ids: number[];
    selectedId: number | null;
    onSelect: (id: number) => void;
    onOpen?: (id: number) => void;
    /** Only called when the alert can be acknowledged; return false if it can't. */
    onAcknowledge?: (id: number) => boolean | void;
}) {
    const latest = useRef({ ids, selectedId, onSelect, onOpen, onAcknowledge });
    latest.current = { ids, selectedId, onSelect, onOpen, onAcknowledge };

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (keyboardIsBusy(event)) return;
            const { ids: list, selectedId: current, onSelect: select, onOpen: open, onAcknowledge: ack } =
                latest.current;
            if (list.length === 0) return;
            const index = current === null ? -1 : list.indexOf(current);
            const key = event.key;

            if (key === 'ArrowDown' || key === 'j' || key === 'ArrowUp' || key === 'k') {
                const down = key === 'ArrowDown' || key === 'j';
                const next = down
                    ? Math.min(list.length - 1, index + 1)
                    : Math.max(0, index === -1 ? 0 : index - 1);
                event.preventDefault();
                if (list[next] !== current) select(list[next]);
                document
                    .querySelector(`[data-alert-row="${list[next]}"]`)
                    ?.scrollIntoView({ block: 'nearest' });
                return;
            }
            if (current === null) return;
            if (key === 'Enter' && open) {
                // Enter on a focused control keeps its own meaning.
                const focused = document.activeElement;
                if (focused && focused !== document.body && focused.closest('button, a, [role="button"]'))
                    return;
                event.preventDefault();
                open(current);
                return;
            }
            if ((key === 'a' || key === 'A') && ack) {
                if (ack(current) !== false) event.preventDefault();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);
}
