import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useRef,
    useState,
} from 'react';
import type { CreateHubPageInitial } from './_dialogs';

export type HubPageCreation = {
    initial?: CreateHubPageInitial;
    context?: string;
};
export const HubPageCreationContext = createContext<
    ((creation?: HubPageCreation) => void) | null
>(null);
export function useHubPageCreation() {
    return useContext(HubPageCreationContext);
}

export function useHubPageCreationWorkspace() {
    const [creation, setCreation] = useState<HubPageCreation | null>(null);
    const frame = useRef<number | null>(null);
    const origin = useRef<{
        href: string;
        focus: HTMLElement | null;
        scroll: number;
        regions: { element: Element; top: number }[];
    } | null>(null);
    const cancelFrame = useCallback(() => {
        if (frame.current !== null) cancelAnimationFrame(frame.current);
        frame.current = null;
    }, []);
    useEffect(() => cancelFrame, [cancelFrame]);
    const open = useCallback(
        (next?: HubPageCreation) => {
            if (origin.current) return;
            cancelFrame();
            origin.current = {
                href: window.location.href,
                focus:
                    document.activeElement instanceof HTMLElement
                        ? document.activeElement
                        : null,
                scroll: window.scrollY,
                regions: [...document.querySelectorAll('[scroll-region]')]
                    .slice(0, 12)
                    .map((element) => ({ element, top: element.scrollTop })),
            };
            setCreation(next ?? {});
            frame.current = requestAnimationFrame(() => {
                frame.current = null;
                const heading = document.querySelector<HTMLElement>(
                    '[data-hub-page-creation-heading]',
                );
                heading?.focus({ preventScroll: true });
                heading?.scrollIntoView({
                    block: 'center',
                    behavior: 'instant',
                });
            });
        },
        [cancelFrame],
    );
    const close = useCallback(
        (restoreBrowse = true) => {
            cancelFrame();
            const previous = origin.current;
            origin.current = null;
            setCreation(null);
            if (!restoreBrowse || !previous) return;
            frame.current = requestAnimationFrame(() => {
                frame.current = null;
                if (origin.current || previous.href !== window.location.href)
                    return;
                if (previous.focus?.isConnected)
                    previous.focus.focus({ preventScroll: true });
                window.scrollTo({ top: previous.scroll, behavior: 'instant' });
                for (const { element, top } of previous.regions) {
                    if (element.isConnected) element.scrollTop = top;
                }
            });
        },
        [cancelFrame],
    );
    return { creation, open, close };
}
