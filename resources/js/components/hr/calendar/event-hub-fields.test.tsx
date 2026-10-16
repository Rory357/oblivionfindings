import { Button } from '@/components/ui/button';
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
    it('allows a styled story menu to link and then explicitly unlink a story', async () => {
        const changed = vi.fn();
        function Editor() {
            const [value, setValue] = useState(emptyEventHubContent);
            return (
                <EventHubFields
                    value={value}
                    onChange={(next) => {
                        setValue(next);
                        changed(next);
                    }}
                    files={[]}
                    attachments={[]}
                    staff={[]}
                    stories={[{ id: 31, title: 'Team learning story' }]}
                />
            );
        }
        render(<Editor />);
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Related company story' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Team learning story',
            }),
        );
        expect(changed).toHaveBeenLastCalledWith(
            expect.objectContaining({ story_id: 31 }),
        );
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Related company story' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'No linked story',
            }),
        );
        expect(changed).toHaveBeenLastCalledWith(
            expect.objectContaining({ story_id: null }),
        );
    });
    it('keeps the active editor attached to the same File after an earlier upload is removed', async () => {
        vi.spyOn(URL, 'createObjectURL').mockImplementation(
            (file) => `blob:${(file as File).name}`,
        );
        vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
        const uploads = ['A', 'B', 'C'].map(
            (name) =>
                new File(['image'], `${name}.jpg`, { type: 'image/jpeg' }),
        );
        function Editor() {
            const [files, setFiles] = useState(uploads);
            const [value, setValue] = useState(emptyEventHubContent);
            return (
                <>
                    <Button
                        onClick={() => {
                            setValue((previous) =>
                                removePhotoReference(previous, 'upload:0'),
                            );
                            setFiles((previous) => previous.slice(1));
                        }}
                    >
                        Remove first upload
                    </Button>
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
        render(<Editor />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Edit details for B.jpg',
            }),
        );
        fireEvent.change(screen.getByLabelText('Photo title'), {
            target: { value: 'Photo B' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove first upload' }),
        );
        expect(
            screen.getByRole('region', { name: 'Photo details for B.jpg' }),
        ).toBeVisible();
        fireEvent.change(screen.getByLabelText('Caption'), {
            target: { value: 'Still editing B' },
        });
        expect(
            JSON.parse(screen.getByRole('status').textContent!).photo_details,
        ).toEqual([
            {
                ref: 'upload:0',
                title: 'Photo B',
                caption: 'Still editing B',
                alt: '',
                album: '',
            },
        ]);
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove first upload' }),
        );
        expect(screen.queryByLabelText('Photo title')).not.toBeInTheDocument();
    });
    it('keeps the selected upload attached to the same photo after an earlier file is removed', () => {
        const content = {
            ...emptyEventHubContent(),
            cover_ref: 'upload:2',
            gallery_refs: ['file:5', 'upload:0', 'upload:2'],
            photo_details: [
                {
                    ref: 'upload:0',
                    title: 'Removed',
                    alt: '',
                    caption: '',
                    album: '',
                },
                {
                    ref: 'upload:2',
                    title: 'Retained',
                    alt: 'Description',
                    caption: 'Context',
                    album: 'Team',
                },
            ],
        };
        const after = removePhotoReference(content, 'upload:0');
        expect(after.cover_ref).toBe('upload:1');
        expect(after.gallery_refs).toEqual(['file:5', 'upload:1']);
        expect(after.photo_details).toEqual([
            {
                ref: 'upload:1',
                title: 'Retained',
                alt: 'Description',
                caption: 'Context',
                album: 'Team',
            },
        ]);
        expect(removePhotoReference(after, 'upload:1')).toMatchObject({
            cover_ref: '',
            gallery_refs: ['file:5'],
        });
        expect(removePhotoReference(after, 'file:5').gallery_refs).toEqual([
            'upload:1',
        ]);
    });
    it('retains details by image identity when ordering, deselecting and reselecting photos', () => {
        const attachments = [3, 5].map((id) => ({
            id,
            name: `Photo ${id}`,
            url: `/photos/${id}`,
            mime: 'image/jpeg',
            size: 10,
        }));
        function Editor() {
            const [value, setValue] = useState(() => ({
                ...emptyEventHubContent(),
                gallery_refs: ['file:3', 'file:5'],
            }));
            return (
                <>
                    <EventHubFields
                        value={value}
                        onChange={setValue}
                        files={[]}
                        attachments={attachments}
                        staff={[]}
                        stories={[]}
                    />
                    <output>{JSON.stringify(value)}</output>
                </>
            );
        }
        render(<Editor />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit details for Photo 3' }),
        );
        fireEvent.change(screen.getByLabelText('Photo title'), {
            target: { value: 'Planting together' },
        });
        fireEvent.change(screen.getByLabelText('Caption'), {
            target: { value: 'A shared afternoon.' },
        });
        fireEvent.change(screen.getByLabelText('Alt text'), {
            target: { value: 'Colleagues planting' },
        });
        fireEvent.change(screen.getByLabelText('Album'), {
            target: { value: 'Community' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Move photo 1 later' }),
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Include Photo 3 in recap' }),
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Include Photo 3 in recap' }),
        );
        fireEvent.change(screen.getByLabelText('Recap title'), {
            target: { value: 'An afternoon together' },
        });
        expect(
            JSON.parse(screen.getByRole('status').textContent!),
        ).toMatchObject({
            gallery_refs: ['file:5', 'file:3'],
            recap_title: 'An afternoon together',
            photo_details: [
                {
                    ref: 'file:3',
                    title: 'Planting together',
                    caption: 'A shared afternoon.',
                    alt: 'Colleagues planting',
                    album: 'Community',
                },
            ],
        });
        fireEvent.change(screen.getByLabelText('Caption'), {
            target: { value: '' },
        });
        expect(
            JSON.parse(screen.getByRole('status').textContent!).photo_details[0]
                .caption,
        ).toBe('');
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
