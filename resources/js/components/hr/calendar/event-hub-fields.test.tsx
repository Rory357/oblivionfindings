import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    emptyEventHubContent,
    EventHubFields,
    removePhotoReference,
} from './event-hub-fields';

describe('Event photo selection', () => {
    afterEach(() => vi.restoreAllMocks());
    it('keeps the selected upload attached to the same photo after an earlier file is removed', () => {
        const content = {
            ...emptyEventHubContent(),
            cover_ref: 'upload:2',
            gallery_refs: ['file:5', 'upload:0', 'upload:2'],
        };
        const after = removePhotoReference(content, 'upload:0');
        expect(after.cover_ref).toBe('upload:1');
        expect(after.gallery_refs).toEqual(['file:5', 'upload:1']);
        expect(removePhotoReference(after, 'upload:1')).toMatchObject({
            cover_ref: '',
            gallery_refs: ['file:5'],
        });
        expect(removePhotoReference(after, 'file:5').gallery_refs).toEqual([
            'upload:1',
        ]);
    });
    it('uses the original multipart index for photos mixed with documents and releases preview URLs', async () => {
        const create = vi
            .spyOn(URL, 'createObjectURL')
            .mockReturnValue('blob:photo-preview');
        const revoke = vi
            .spyOn(URL, 'revokeObjectURL')
            .mockImplementation(() => {});
        const files = [
            new File(['notes'], 'notes.txt', { type: 'text/plain' }),
            new File(['photo'], 'team.png', { type: 'image/png' }),
        ];
        function Editor() {
            const [value, setValue] = useState(emptyEventHubContent);
            return (
                <>
                    <EventHubFields
                        value={value}
                        onChange={setValue}
                        files={files}
                        attachments={[]}
                        staff={[]}
                        stories={[]}
                    />
                    <output>{JSON.stringify(value)}</output>
                </>
            );
        }
        const view = render(<Editor />);
        fireEvent.click(
            await screen.findByRole('radio', { name: 'Use team.png as cover' }),
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Include team.png in recap' }),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            '"cover_ref":"upload:1"',
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            '"gallery_refs":["upload:1"]',
        );
        expect(create).toHaveBeenCalledTimes(1);
        view.unmount();
        expect(revoke).toHaveBeenCalledWith('blob:photo-preview');
    });
});
