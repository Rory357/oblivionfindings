import { useCallback, useState, type MouseEvent, type ReactNode } from 'react';

import {
    TabStrip,
    type RosterTabItem,
    type RosterTabTone,
} from '@/components/rostering/tab-strip';

/** Canonical HR tab item — alias of the shared Rostering TabStrip item. */
export type HrTabItem = RosterTabItem;
export type HrTabTone = RosterTabTone;

/**
 * Canonical HR tab strip. HR standardises on the Rostering {@link TabStrip}
 * look — toned chips + count badges + active underline-bar + keyboard nav — so
 * tabs are visually identical across modules. Pair with {@link useHrTab} for
 * `?tab=` URL sync (deep-linkable, refresh-safe, no server round-trip).
 */
export function HrTabs(props: {
    value: string;
    onChange: (next: string) => void;
    items: HrTabItem[];
    className?: string;
    ariaLabel?: string;
    onItemContextMenu?: (id: string, event: MouseEvent) => void;
    decorations?: Record<string, ReactNode>;
    trailing?: ReactNode;
}) {
    return <TabStrip {...props} ariaLabel={props.ariaLabel ?? 'HR views'} />;
}

/**
 * Tab state synced to a URL query param (default `?tab=`). Switching is
 * client-side (history.replaceState) so it survives refresh and supports deep
 * links without re-fetching the page.
 */
export function useHrTab(
    defaultTab: string,
    options?: { param?: string; syncUrl?: boolean },
) {
    const param = options?.param ?? 'tab';
    const syncUrl = options?.syncUrl ?? true;
    const [tab, setTab] = useState<string>(() => {
        if (typeof window === 'undefined') return defaultTab;
        return (
            new URLSearchParams(window.location.search).get(param) || defaultTab
        );
    });
    const change = useCallback(
        (next: string) => {
            setTab(next);
            if (syncUrl && typeof window !== 'undefined') {
                const url = new URL(window.location.href);
                url.searchParams.set(param, next);
                window.history.replaceState(
                    window.history.state,
                    '',
                    url.toString(),
                );
            }
        },
        [param, syncUrl],
    );
    return [tab, change] as const;
}

export { TabStrip };
export default HrTabs;
