import {
    rememberOverlayTrigger,
    restoreOverlayFocus,
} from '@/components/ui/overlay-focus-return';
import { useRef } from 'react';

/** Keep the list's opener across the workspace's nested handoff replacements. */
export function useAlertWorkspaceFocusReturn() {
    const focus = useRef<{
        target: HTMLElement | null;
        owner: HTMLElement | null;
    }>({
        target: null,
        owner: null,
    });
    const closing = useRef(false);

    return {
        rememberOpen: (alertId: number) => {
            closing.current = false;
            focus.current = { target: null, owner: null };
            const trigger = document.querySelector<HTMLElement>(
                `[data-alert-workspace-trigger="${alertId}"]`,
            );
            const active = document.activeElement;
            rememberOverlayTrigger(
                focus,
                trigger ?? (active !== document.body ? active : null),
            );
        },
        beginClose: () => {
            closing.current = true;
        },
        onCloseAutoFocus: (event: Event) => {
            event.preventDefault();
            // Opening the nested handoff also unmounts a dialog; that is not
            // an exit to the list and must not consume the saved return target.
            if (!closing.current) return;
            closing.current = false;
            focus.current.owner ??= document.querySelector<HTMLElement>(
                '[data-alert-workspace-list]',
            );
            restoreOverlayFocus(focus);
        },
    };
}
