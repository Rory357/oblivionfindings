export type ChatBrowserLease = { release: () => void };

/** A browser-owned lock survives background throttling and releases when its tab closes. */
export function acquireChatBrowserLease(
    name: string,
    signal: AbortSignal,
    locks: LockManager | undefined,
    unavailable: string,
): Promise<ChatBrowserLease | null> {
    if (!locks?.request) return Promise.reject(new Error(unavailable));
    if (signal.aborted) return Promise.resolve(null);
    return new Promise((resolve, reject) => {
        let delivered = false;
        void locks
            .request(
                name,
                { mode: 'exclusive', ifAvailable: true },
                async (lock) => {
                    if (!lock || signal.aborted) {
                        delivered = true;
                        resolve(null);
                        return;
                    }
                    let release!: () => void;
                    const held = new Promise<void>((done) => {
                        release = done;
                    });
                    const close = () => {
                        signal.removeEventListener('abort', close);
                        release();
                    };
                    signal.addEventListener('abort', close, { once: true });
                    delivered = true;
                    resolve({ release: close });
                    await held;
                },
            )
            .catch((error) => {
                if (!delivered) reject(error);
            });
    });
}
