// Shared by the app and its classic service worker. No message text, account,
// client or conversation identifiers are retained, only opaque notice receipts.
(() => {
    if (globalThis.OblivionChatSoundReceipts) return;
    const uuid =
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    const lifetime = 48 * 60 * 60 * 1000;

    function claim(values) {
        if (
            !Array.isArray(values) ||
            !values.length ||
            values.length > 100 ||
            values.some((id) => typeof id !== 'string' || !uuid.test(id))
        )
            return Promise.resolve(false);
        if (!globalThis.indexedDB) return Promise.resolve(null);
        const ids = [...new Set(values.map((id) => id.toLowerCase()))];
        return new Promise((resolve) => {
            let finished = false;
            let database;
            let transaction;
            const finish = (value) => {
                if (finished) return;
                finished = true;
                clearTimeout(timer);
                database?.close();
                resolve(value);
            };
            const timer = setTimeout(() => {
                try {
                    transaction?.abort();
                } catch {
                    /* Already settled. */
                }
                finish(null);
            }, 3000);
            try {
                const request = indexedDB.open(
                    'oblivion-chat-sound-receipts',
                    1,
                );
                request.onupgradeneeded = () => {
                    const store = request.result.createObjectStore('receipts', {
                        keyPath: 'id',
                    });
                    store.createIndex('expires', 'expires');
                };
                request.onerror = request.onblocked = () => finish(null);
                request.onsuccess = () => {
                    database = request.result;
                    if (finished) {
                        database.close();
                        return;
                    }
                    database.onversionchange = () => database.close();
                    try {
                        transaction = database.transaction(
                            'receipts',
                            'readwrite',
                        );
                        const store = transaction.objectStore('receipts');
                        const now = Date.now();
                        let claimed = false;
                        // One read/write transaction serialises competing tabs and push.
                        for (const id of ids) {
                            const get = store.get(id);
                            get.onsuccess = () => {
                                if (!get.result || get.result.expires <= now) {
                                    store.put({ id, expires: now + lifetime });
                                    claimed = true;
                                }
                            };
                        }
                        // Bound cleanup work, even after the browser has been closed for weeks.
                        let removed = 0;
                        const old = store
                            .index('expires')
                            .openCursor(IDBKeyRange.upperBound(now));
                        old.onsuccess = () => {
                            const cursor = old.result;
                            if (cursor && removed++ < 100) {
                                if (!ids.includes(cursor.primaryKey))
                                    cursor.delete();
                                cursor.continue();
                            }
                        };
                        transaction.oncomplete = () => finish(claimed);
                        transaction.onerror = transaction.onabort = () =>
                            finish(null);
                    } catch {
                        finish(null);
                    }
                };
            } catch {
                finish(null);
            }
        });
    }
    function context(actorId) {
        if (
            !Number.isSafeInteger(actorId) ||
            actorId <= 0 ||
            !globalThis.BroadcastChannel
        )
            return Promise.resolve(null);
        return new Promise((resolve) => {
            let channel;
            let timer;
            const result = { active: false, conversations: [] };
            const finish = () => {
                clearTimeout(timer);
                channel?.close();
                resolve(result);
            };
            try {
                const request = crypto.randomUUID();
                channel = new BroadcastChannel(
                    `oblivion-chat-sound-context-${actorId}`,
                );
                channel.onmessage = (event) => {
                    const data = event.data;
                    if (
                        data?.request !== request ||
                        data?.type !== 'context' ||
                        typeof data.active !== 'boolean' ||
                        !Array.isArray(data.conversations) ||
                        data.conversations.length > 100 ||
                        data.conversations.some(
                            (id) => !Number.isSafeInteger(id) || id <= 0,
                        )
                    )
                        return;
                    result.active ||= data.active;
                    result.conversations = [
                        ...new Set([
                            ...result.conversations,
                            ...data.conversations,
                        ]),
                    ];
                };
                timer = setTimeout(finish, 180);
                channel.postMessage({ type: 'probe', request });
            } catch {
                clearTimeout(timer);
                channel?.close();
                resolve(null);
            }
        });
    }

    // null means unavailable: in-app sound stays silent, OS push retains its
    // ordinary tag/renotify behavior. Only a committed true permits app audio.
    globalThis.OblivionChatSoundReceipts = Object.freeze({ claim, context });
})();
