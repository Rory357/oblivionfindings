export const CHAT_BROWSER_PROTOCOL_VERSION = 2;

/** Verify the actual active worker before registering support for account-safe Chat push. */
export async function verifyChatBrowserWorker(
    registration: ServiceWorkerRegistration,
): Promise<number> {
    const worker = registration.active;
    if (
        !worker ||
        worker.state !== 'activated' ||
        typeof MessageChannel === 'undefined'
    ) {
        throw new Error(
            'Browser notifications are updating. Please try again.',
        );
    }
    const nonce = crypto.randomUUID();
    const channel = new MessageChannel();
    return new Promise<number>((resolve, reject) => {
        let finished = false;
        const finish = (ready: boolean) => {
            if (finished) return;
            finished = true;
            clearTimeout(timer);
            channel.port1.onmessage = null;
            channel.port1.close();
            channel.port2.close();
            if (ready) resolve(CHAT_BROWSER_PROTOCOL_VERSION);
            else
                reject(
                    new Error(
                        'Browser notifications are updating. Refresh this page and try again.',
                    ),
                );
        };
        const timer = setTimeout(() => finish(false), 5000);
        channel.port1.onmessage = (event) => {
            const data = event.data;
            if (data?.nonce !== nonce) return;
            finish(
                data.type === 'chat-worker-ready' &&
                    data.version === CHAT_BROWSER_PROTOCOL_VERSION &&
                    registration.active === worker &&
                    worker.state === 'activated',
            );
        };
        try {
            worker.postMessage({ type: 'chat-worker-capability', nonce }, [
                channel.port2,
            ]);
        } catch {
            finish(false);
        }
    });
}
