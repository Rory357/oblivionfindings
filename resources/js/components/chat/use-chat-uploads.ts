import {
    useEffect,
    useLayoutEffect,
    useMemo,
    useSyncExternalStore,
} from 'react';
import { readChatThreadMemory, saveChatThreadMemory } from './api';
import type { ChatSelectedFile } from './chat-selected-file';
import { ChatUploadCoordinator } from './chat-upload-coordinator';

export function useChatUploads(
    actorId: number,
    key: string,
    conversationId: number,
    files: ChatSelectedFile[],
    restored: boolean,
    permitted: (file: ChatSelectedFile) => boolean,
) {
    const coordinator = useMemo(
        () =>
            new ChatUploadCoordinator(
                actorId,
                conversationId,
                readChatThreadMemory(key).uploads ?? [],
                (uploads) => saveChatThreadMemory(key, { uploads }),
                () => false,
            ),
        [actorId, key, conversationId],
    );
    const rows = useSyncExternalStore(
        coordinator.subscribe,
        coordinator.snapshot,
        coordinator.snapshot,
    );
    useLayoutEffect(() => {
        coordinator.activate();
        return () => coordinator.dispose();
    }, [coordinator]);
    useLayoutEffect(() => {
        coordinator.setPermission(permitted);
    }, [coordinator, permitted]);
    useEffect(() => {
        if (restored) coordinator.reconcile(files);
    }, [coordinator, files, restored]);
    return {
        rows: rows.filter((row) => files.includes(row.file)),
        prepare: (file: ChatSelectedFile) => coordinator.prepare(file),
        remove: (file: ChatSelectedFile) => coordinator.remove(file),
    };
}
