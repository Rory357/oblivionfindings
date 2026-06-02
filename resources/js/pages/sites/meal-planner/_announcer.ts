/**
 * Tiny cross-component announcer for the meal planner. Critical errors/results
 * are mirrored into a single visually-hidden `aria-live="assertive"` region in
 * the orchestrator so assistive tech reliably hears them regardless of how the
 * toast library announces (P2-20).
 */
type Listener = (msg: string) => void;

const listeners = new Set<Listener>();

export function announce(message: string): void {
    listeners.forEach((l) => l(message));
}

export function subscribeAnnounce(listener: Listener): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}
