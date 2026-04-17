import { useEffect, useState } from 'react';

import {
    getOfflineQueueSnapshot,
    subscribeOfflineQueue,
    type OfflineQueueState,
} from '@/lib/offline-queue';

/**
 * Subscribe to the shared offline-queue state (online flag + pending count +
 * syncing flag). Used by the thin offline banner and by submit surfaces that
 * want to peek at whether the device is currently offline before calling
 * `submitOffline`. Returns the latest state snapshot.
 */
export function useOfflineQueueState(): OfflineQueueState {
    const [state, setState] = useState<OfflineQueueState>(() =>
        getOfflineQueueSnapshot(),
    );

    useEffect(() => {
        return subscribeOfflineQueue(setState);
    }, []);

    return state;
}
