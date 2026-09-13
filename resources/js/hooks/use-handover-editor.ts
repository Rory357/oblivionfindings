import type { HandoverWorkerNotes } from '@/components/handover-person-notes';
import {
    emptyHandoverWriteValue,
    type HandoverWriteValue,
} from '@/components/handover-write-form';
import { useEffect, useState } from 'react';

export type HandoverPerson = { id: number; name: string };
export type HandoverEditor = {
    people: HandoverPerson[];
    handover_id: number | null;
    review_url?: string | null;
    expected_version: number | null;
    status: string | null;
    saved_at?: string | null;
    worker_notes: HandoverWorkerNotes;
};

export function useHandoverEditor(shiftId: number | null, open: boolean) {
    const [editor, setEditor] = useState<HandoverEditor | null>(null);
    const [value, setValue] = useState<HandoverWriteValue>(
        emptyHandoverWriteValue,
    );
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        if (!open || !shiftId) return;
        const controller = new AbortController();
        setLoading(true);
        setError('');
        setEditor(null);
        fetch(`/attendance/shifts/${shiftId}/handover-draft`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        'Your shift notes could not be loaded. Retry before making changes.',
                    );
                const data: HandoverEditor = await response.json();
                if (controller.signal.aborted) return;
                setEditor(data);
                setValue({
                    ...emptyHandoverWriteValue,
                    expected_version: data.expected_version,
                    worker_notes: {
                        shared_notes: data.worker_notes.shared_notes,
                        people: data.people.map((person) => {
                            const saved = data.worker_notes.people.find(
                                (note) => note.client_id === person.id,
                            );
                            return {
                                client_id: person.id,
                                notes: saved?.notes ?? '',
                                no_updates: saved?.no_updates ?? false,
                                not_supported: saved?.not_supported ?? false,
                                follow_up_needed:
                                    saved?.follow_up_needed ?? false,
                            };
                        }),
                    },
                });
                setLoading(false);
            })
            .catch((reason: Error) => {
                if (!controller.signal.aborted) {
                    setError(reason.message);
                    setLoading(false);
                }
            });
        return () => controller.abort();
    }, [shiftId, open, attempt]);
    return {
        editor,
        setEditor,
        value,
        setValue,
        loading,
        error,
        retry: () => setAttempt((current) => current + 1),
    };
}
