import { type ReactNode, useLayoutEffect, useRef } from 'react';
import './chat-call-dock.css';

/** One non-modal call layer for every web shell; its size reserves page space. */
export function ChatCallDock({
    children,
    covered = false,
}: {
    children: ReactNode;
    covered?: boolean;
}) {
    const dock = useRef<HTMLDivElement>(null);
    const isCovered = useRef(covered);
    useLayoutEffect(() => {
        isCovered.current = covered;
    }, [covered]);
    useLayoutEffect(() => {
        const element = dock.current;
        if (!element) return;
        let previousFocus = document.activeElement;
        const rememberWorkFocus = (event: FocusEvent) => {
            if (
                !isCovered.current &&
                event.target instanceof HTMLElement &&
                event.target !== document.body &&
                !element.contains(event.target)
            )
                previousFocus = event.target;
        };
        const root = document.documentElement;
        const measure = () =>
            root.style.setProperty(
                '--chat-call-dock-height',
                `${Math.ceil(element.getBoundingClientRect().height)}px`,
            );
        root.dataset.chatCallDock = 'visible';
        measure();
        const observer =
            typeof ResizeObserver === 'undefined'
                ? null
                : new ResizeObserver(measure);
        observer?.observe(element);
        const focusControls = (event: KeyboardEvent) => {
            if (
                !isCovered.current &&
                event.altKey &&
                event.shiftKey &&
                event.code === 'KeyC'
            ) {
                const control = element.querySelector<HTMLButtonElement>(
                    'button:not(:disabled)',
                );
                if (control) {
                    event.preventDefault();
                    control.focus();
                }
            }
        };
        document.addEventListener('keydown', focusControls);
        document.addEventListener('focusin', rememberWorkFocus);
        return () => {
            observer?.disconnect();
            document.removeEventListener('keydown', focusControls);
            document.removeEventListener('focusin', rememberWorkFocus);
            delete root.dataset.chatCallDock;
            root.style.removeProperty('--chat-call-dock-height');
            if (
                element.contains(document.activeElement) &&
                previousFocus instanceof HTMLElement &&
                previousFocus.isConnected
            )
                previousFocus.focus({ preventScroll: true });
        };
    }, []);
    return (
        <div
            ref={dock}
            className="chat-call-dock"
            style={
                covered
                    ? { visibility: 'hidden', pointerEvents: 'none' }
                    : undefined
            }
            aria-hidden={covered || undefined}
            aria-keyshortcuts="Alt+Shift+C"
        >
            {children}
        </div>
    );
}
