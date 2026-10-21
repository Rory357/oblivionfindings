export type ChatSoundContext = { active: boolean; conversations: number[] };
type Receipts = {
    claim: (ids: string[]) => Promise<boolean | null>;
    context?: (actorId: number) => Promise<ChatSoundContext | null>;
};
declare global {
    var OblivionChatSoundReceipts: Receipts | undefined;
}

let loading: Promise<void> | undefined;
function loadReceipts() {
    if (globalThis.OblivionChatSoundReceipts) return Promise.resolve();
    loading ??= new Promise<void>((resolve, reject) => {
        // A same-origin classic script also works with the existing classic worker.
        const script = document.createElement('script');
        const timer = setTimeout(
            () => finish(new Error('Sound coordination unavailable')),
            5000,
        );
        const finish = (failure?: Error) => {
            clearTimeout(timer);
            script.onload = script.onerror = null;
            script.remove();
            if (failure) reject(failure);
            else resolve();
        };
        script.src = '/chat-sound-receipts.js';
        script.async = true;
        script.onload = () =>
            finish(
                globalThis.OblivionChatSoundReceipts
                    ? undefined
                    : new Error('Sound coordination unavailable'),
            );
        script.onerror = () =>
            finish(new Error('Sound coordination unavailable'));
        document.head.appendChild(script);
    }).catch((failure) => {
        loading = undefined;
        throw failure;
    });
    return loading;
}

export async function claimChatSound(ids: string[]): Promise<boolean> {
    try {
        await loadReceipts();
        return (
            (await globalThis.OblivionChatSoundReceipts?.claim(ids)) === true
        );
    } catch {
        return false;
    }
}

export async function readChatSoundContext(
    actorId: number,
): Promise<ChatSoundContext | null> {
    try {
        await loadReceipts();
        return (
            (await globalThis.OblivionChatSoundReceipts?.context?.(actorId)) ??
            null
        );
    } catch {
        return null;
    }
}
