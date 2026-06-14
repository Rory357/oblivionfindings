import { useCallback, useState } from 'react';

import {
    TabStrip,
    type RosterTabItem,
    type RosterTabTone,
} from '@/components/rostering/tab-strip';

/** Canonical Finance tab item — alias of the shared Rostering TabStrip item. */
export type FinanceTabItem = RosterTabItem;
export type FinanceTabTone = RosterTabTone;

/**
 * Canonical Finance tab strip. Finance standardises on the Rostering
 * {@link TabStrip} look — toned chips + count badges + active underline-bar +
 * keyboard nav — so tabs are visually identical across modules (HR uses the same
 * underlying component). Pair with {@link useFinanceTab} for `?tab=` URL sync.
 */
export function FinanceTabs(props: {
    value: string;
    onChange: (next: string) => void;
    items: FinanceTabItem[];
    className?: string;
    ariaLabel?: string;
}) {
    return <TabStrip {...props} ariaLabel={props.ariaLabel ?? 'Finance views'} />;
}

/**
 * Tab state synced to a URL query param (default `?tab=`). Switching is
 * client-side (history.replaceState) so it survives refresh and supports deep
 * links without re-fetching the page. (Generic URL-sync hook; mirrors HR's
 * useHrTab — kept finance-local to avoid coupling two concurrently-evolving
 * loops; a shared home can be hoisted in the M10 de-dup sweep.)
 */
export function useFinanceTab(
    defaultTab: string,
    options?: { param?: string; syncUrl?: boolean },
) {
    const param = options?.param ?? 'tab';
    const syncUrl = options?.syncUrl ?? true;
    const [tab, setTab] = useState<string>(() => {
        if (typeof window === 'undefined') return defaultTab;
        return new URLSearchParams(window.location.search).get(param) || defaultTab;
    });
    const change = useCallback(
        (next: string) => {
            setTab(next);
            if (syncUrl && typeof window !== 'undefined') {
                const url = new URL(window.location.href);
                url.searchParams.set(param, next);
                window.history.replaceState(window.history.state, '', url.toString());
            }
        },
        [param, syncUrl],
    );
    return [tab, change] as const;
}

export { TabStrip };
export default FinanceTabs;
