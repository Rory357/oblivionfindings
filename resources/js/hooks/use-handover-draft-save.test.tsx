import {
    emptyHandoverWriteValue,
    type HandoverWriteValue,
} from '@/components/handover-write-form';
import { TaskRequestError } from '@/pages/my-day/lib/task-api';
import { act, renderHook, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useHandoverDraftSave } from './use-handover-draft-save';
import type { HandoverEditor } from './use-handover-editor';

const api = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('@/pages/my-day/lib/task-api', async (original) => ({
    ...(await original<object>()),
    taskRequest: api.request,
}));
const initialNotes = {
    shared_notes: '',
    people: [
        { client_id: 1, notes: '', no_updates: false, follow_up_needed: false },
    ],
};
const metadata = (version = 5) => ({
    handover_id: 8,
    expected_version: version,
    status: 'draft',
    saved_at: '2026-09-13T00:00:00Z',
});
function mount() {
    return renderHook(() => {
        const [editor, setEditor] = useState<HandoverEditor | null>({
            ...metadata(4),
            people: [{ id: 1, name: 'Mere' }],
            worker_notes: initialNotes,
        });
        const [value, setValue] = useState<HandoverWriteValue>({
            ...emptyHandoverWriteValue,
            worker_notes: initialNotes,
            expected_version: 4,
        });
        const draft = useHandoverDraftSave({
            shiftId: 7,
            editor,
            value,
            setValue,
            setEditor,
            loading: false,
            readOnly: false,
        });
        return {
            ...draft,
            value,
            change: (notes: string) =>
                setValue((previous) => ({
                    ...previous,
                    worker_notes: {
                        ...initialNotes,
                        people: [{ ...initialNotes.people[0], notes }],
                    },
                })),
        };
    });
}
beforeEach(() => api.request.mockReset());
describe('private handover autosave', () => {
    it('saves typing automatically without sending a handover', async () => {
        api.request.mockResolvedValue(metadata());
        const { result } = mount();
        act(() => result.current.change('Mere note'));
        await waitFor(() => expect(result.current.dirty).toBe(false), {
            timeout: 1800,
        });
        expect(result.current.dirty).toBe(false);
        expect(api.request).toHaveBeenCalledExactlyOnceWith(
            '/attendance/handover',
            'POST',
            expect.objectContaining({ shift_id: 7, expected_version: 4 }),
        );
    });
    it('serializes a newer edit behind an in-flight save using the returned revision', async () => {
        let resolveFirst!: (value: ReturnType<typeof metadata>) => void;
        api.request
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        resolveFirst = resolve;
                    }),
            )
            .mockResolvedValue(metadata(6));
        const { result } = mount();
        act(() => result.current.change('First note'));
        let completion!: Promise<boolean>;
        act(() => {
            completion = result.current.flush();
        });
        act(() => result.current.change('Newer note'));
        await act(async () => {
            resolveFirst(metadata(5));
            await completion;
        });
        expect(api.request).toHaveBeenNthCalledWith(
            2,
            '/attendance/handover',
            'POST',
            expect.objectContaining({
                expected_version: 5,
                worker_notes: expect.objectContaining({
                    people: [expect.objectContaining({ notes: 'Newer note' })],
                }),
            }),
        );
        expect(result.current.value.worker_notes?.people[0].notes).toBe(
            'Newer note',
        );
        expect(result.current.value.expected_version).toBe(6);
        expect(result.current.dirty).toBe(false);
    });
    it('does not overwrite another window after a conflict, including after more typing', async () => {
        api.request
            .mockRejectedValueOnce(
                new TaskRequestError('Changed elsewhere', {
                    handover: 'Changed elsewhere',
                }),
            )
            .mockResolvedValue({
                ...metadata(6),
                worker_notes: {
                    ...initialNotes,
                    shared_notes: 'Another window',
                },
            });
        const { result } = mount();
        act(() => result.current.change('Keep my answers'));
        await act(async () => {
            await result.current.flush();
        });
        act(() => result.current.change('Keep my newer answers'));
        await act(async () => {
            await result.current.retrySave();
        });
        expect(result.current.state).toBe('conflict');
        expect(result.current.value.worker_notes?.people[0].notes).toBe(
            'Keep my newer answers',
        );
        expect(
            api.request.mock.calls.filter((call) => call[1] === 'POST'),
        ).toHaveLength(1);
    });
    it('recovers a lost save response by checking the persisted content before advancing the version', async () => {
        api.request
            .mockRejectedValueOnce(
                new TaskRequestError('Connection interrupted', {}, true),
            )
            .mockResolvedValueOnce({
                ...metadata(5),
                worker_notes: {
                    ...initialNotes,
                    people: [
                        { ...initialNotes.people[0], notes: 'Saved on server' },
                    ],
                },
            });
        const { result } = mount();
        act(() => result.current.change('Saved on server'));
        await act(async () => {
            await result.current.flush();
        });
        await act(async () => {
            await result.current.retrySave();
        });
        expect(result.current.dirty).toBe(false);
        expect(result.current.value.expected_version).toBe(5);
        expect(
            api.request.mock.calls.filter((call) => call[1] === 'POST'),
        ).toHaveLength(1);
    });
    it('can save after an earlier flush that had no changes', async () => {
        api.request.mockResolvedValue(metadata());
        const { result } = mount();
        await act(async () => {
            await result.current.flush();
        });
        act(() => result.current.change('New note'));
        await act(async () => {
            await result.current.flush();
        });
        expect(api.request).toHaveBeenCalledOnce();
        expect(result.current.dirty).toBe(false);
    });
});
