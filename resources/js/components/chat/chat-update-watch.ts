export function watchChatUpdates(
    refresh: () => Promise<boolean>,
    options: {
        interval: () => number;
        whenHidden?: boolean;
        events?: string[];
    },
) {
    let stopped = false;
    let running = false;
    let pending = false;
    let urgent = false;
    let failures = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const events = options.events ?? ['oblivion:chat-refresh'];
    const schedule = (delay: number) => {
        clearTimeout(timer);
        if (!stopped) timer = setTimeout(() => void run(), delay);
    };
    const run = async () => {
        urgent = false;
        if (stopped) return;
        if (running) {
            pending = true;
            return;
        }
        if (
            (!options.whenHidden && document.visibilityState !== 'visible') ||
            !navigator.onLine
        ) {
            schedule(options.interval());
            return;
        }
        running = true;
        try {
            failures = (await refresh()) ? 0 : failures + 1;
        } catch {
            failures++;
        } finally {
            running = false;
            if (pending) {
                pending = false;
                schedule(250);
            } else
                schedule(
                    Math.min(
                        60000,
                        options.interval() * 2 ** Math.min(failures, 5),
                    ),
                );
        }
    };
    const wake = () => {
        if (running) pending = true;
        else if (!urgent) {
            urgent = true;
            schedule(250);
        }
    };
    for (const event of events) window.addEventListener(event, wake);
    window.addEventListener('online', wake);
    document.addEventListener('visibilitychange', wake);
    void run();
    return () => {
        stopped = true;
        clearTimeout(timer);
        for (const event of events) window.removeEventListener(event, wake);
        window.removeEventListener('online', wake);
        document.removeEventListener('visibilitychange', wake);
    };
}
