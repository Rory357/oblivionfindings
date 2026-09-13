import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    readKnowledgeBuffer,
    saveKnowledgeBuffer,
} from '../knowledge-editor-buffer';

beforeEach(() => {
    readKnowledgeBuffer(-1, 1);
});
afterEach(() => {
    vi.useRealTimers();
    readKnowledgeBuffer(-1, 1);
});

it('binds unsaved data to one account and keeps at most three document buffers', () => {
    for (const id of [1, 2, 3, 4])
        saveKnowledgeBuffer(
            7,
            id,
            { body: `Private ${id}` },
            [{ audience: 'all_staff' }],
            false,
            'content',
        );
    expect(readKnowledgeBuffer(7, 1)).toBeNull();
    const copy = readKnowledgeBuffer<{ body: string }>(7, 4)!;
    copy.data.body = 'Changed caller copy';
    expect(readKnowledgeBuffer<{ body: string }>(7, 4)?.data.body).toBe(
        'Private 4',
    );
    expect(readKnowledgeBuffer(8, 4)).toBeNull();
    expect(readKnowledgeBuffer(7, 4)).toBeNull();
});

it('expires unsaved buffers after thirty minutes without browser storage', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-13T00:00:00Z'));
    saveKnowledgeBuffer(
        7,
        1,
        { body: 'Private expiring body' },
        [{ audience: 'specific_sites', site_scope: [9] }],
        true,
        'diagrams',
    );
    vi.setSystemTime(new Date('2026-09-13T00:31:00Z'));
    expect(readKnowledgeBuffer(7, 1)).toBeNull();
});
