const EVENT = 'emar:recorded';
export function medicationRecorded(clientId: number) {
    window.dispatchEvent(new CustomEvent(EVENT, { detail: { clientId } }));
}
export function onMedicationRecorded(clientId: number, refresh: () => void) {
    const listener = (event: Event) => {
        if (
            (event as CustomEvent<{ clientId: number }>).detail?.clientId ===
            clientId
        )
            refresh();
    };
    window.addEventListener(EVENT, listener);
    return () => window.removeEventListener(EVENT, listener);
}
