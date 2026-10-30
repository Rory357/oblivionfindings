/** Native cancellable history events complement Inertia's GET-only before event. */
type HistoryDestination = { key: string; sameDocument: boolean };
type HistoryNavigateEvent = Event & {
    navigationType: string;
    destination: HistoryDestination;
};
export type DraftHistoryNavigation = EventTarget & {
    currentEntry: { key: string } | null;
    traverseTo: (key: string) => { finished: Promise<unknown> };
};
type Guard = (continueHistory: () => void, cancelHistory: () => void) => void;
type Approval = { navigation: DraftHistoryNavigation; key: string };
type Decision = { lease: symbol };
const guards = new Map<symbol, Guard>();
let observed: DraftHistoryNavigation | null = null;
let approved: Approval | null = null;
let decision: Decision | null = null;

export function draftHistoryNavigation(): DraftHistoryNavigation | null {
    if (typeof window === 'undefined') return null;
    const navigation = (
        window as Window & { navigation?: DraftHistoryNavigation }
    ).navigation;
    return navigation &&
        typeof navigation.addEventListener === 'function' &&
        typeof navigation.traverseTo === 'function'
        ? navigation
        : null;
}
function clearApproval(ticket: Approval) {
    if (approved === ticket) approved = null;
}
function onNavigate(raw: Event) {
    const event = raw as HistoryNavigateEvent;
    const navigation = observed;
    if (
        !navigation ||
        event.defaultPrevented ||
        event.navigationType !== 'traverse'
    )
        return;
    const destination = event.destination.key;
    if (approved?.navigation === navigation && approved.key === destination) {
        approved = null;
        return;
    }
    if (!event.destination.sameDocument || !event.cancelable) return;
    approved = null;
    const selected = Array.from(guards.entries()).at(-1);
    if (!selected) return;
    event.preventDefault();
    const [lease, confirm] = selected;
    const origin = navigation.currentEntry?.key;
    const pending = { lease };
    decision = pending;
    confirm(
        () => {
            // A replaced actor/form or another committed page cannot replay this old decision.
            if (
                decision !== pending ||
                observed !== navigation ||
                !guards.has(lease) ||
                navigation.currentEntry?.key !== origin
            )
                return;
            decision = null;
            const ticket = { navigation, key: destination };
            approved = ticket;
            try {
                const result = navigation.traverseTo(destination);
                result.finished.then(
                    () => clearApproval(ticket),
                    () => clearApproval(ticket),
                );
            } catch {
                clearApproval(ticket);
            }
        },
        () => {
            if (decision === pending) decision = null;
        },
    );
}
export function registerDraftHistoryGuard(confirm: Guard): () => void {
    const navigation = draftHistoryNavigation();
    if (!navigation) return () => undefined;
    if (observed && observed !== navigation) {
        observed.removeEventListener('navigate', onNavigate);
        guards.clear();
        approved = null;
        decision = null;
    }
    if (observed !== navigation) {
        observed = navigation;
        navigation.addEventListener('navigate', onNavigate);
    }
    const lease = Symbol('active draft history guard');
    guards.set(lease, confirm);
    return () => {
        guards.delete(lease);
        if (decision?.lease === lease) decision = null;
        if (guards.size === 0 && observed === navigation) {
            navigation.removeEventListener('navigate', onNavigate);
            observed = null;
            approved = null;
            decision = null;
        }
    };
}
