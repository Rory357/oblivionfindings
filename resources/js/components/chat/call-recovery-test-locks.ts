/** In-memory exclusive lock manager for lifecycle tests; no browser fallback. */
export function createCallTestLocks(): LockManager {
    const held = new Set<string>();
    const queues = new Map<string, (() => void)[]>();
    return {
        request: async (
            name: string,
            options: LockOptions,
            callback: LockGrantedCallback<unknown>,
        ) => {
            if (options.signal && options.ifAvailable)
                throw new DOMException(
                    'Invalid lock options',
                    'NotSupportedError',
                );
            if (options.signal?.aborted)
                throw new DOMException('Aborted', 'AbortError');
            if (held.has(name) && options.ifAvailable) return callback(null);
            if (held.has(name)) {
                await new Promise<void>((resolve, reject) => {
                    const queue = queues.get(name) ?? [];
                    const grant = () => {
                        options.signal?.removeEventListener('abort', abort);
                        resolve();
                    };
                    const abort = () => {
                        const index = queue.indexOf(grant);
                        if (index >= 0) queue.splice(index, 1);
                        reject(new DOMException('Aborted', 'AbortError'));
                    };
                    queue.push(grant);
                    queues.set(name, queue);
                    options.signal?.addEventListener('abort', abort, {
                        once: true,
                    });
                });
            } else held.add(name);
            try {
                return await callback({ name, mode: 'exclusive' });
            } finally {
                const next = queues.get(name)?.shift();
                if (next) next();
                else held.delete(name);
            }
        },
    } as LockManager;
}
