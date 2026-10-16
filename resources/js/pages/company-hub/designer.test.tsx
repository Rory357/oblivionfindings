import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { HeroSettings, defaultHero } from './_hero-settings';
import { blockSpan, duplicateSection, moveBlock } from './_layout';
import type { HubSection } from './_types';

const sections: HubSection[] = [
    {
        id: 'first',
        columns: 2,
        layout: 'wide-left',
        blocks: [
            { id: 'a', type: 'text', title: 'First' },
            { id: 'b', type: 'news', title: 'News' },
        ],
    },
    { id: 'empty', columns: 1, blocks: [] },
];

describe('shared visual designer', () => {
    it('moves a block into an empty section without mutating the saved snapshot', () => {
        const moved = moveBlock(sections, 'a', 'empty', 0);
        expect(moved[0].blocks.map((b) => b.id)).toEqual(['b']);
        expect(moved[1].blocks.map((b) => b.id)).toEqual(['a']);
        expect(sections[0].blocks.map((b) => b.id)).toEqual(['a', 'b']);
    });
    it('reorders in place and ignores stale drag targets', () => {
        expect(
            moveBlock(sections, 'a', 'first', 1)[0].blocks.map((b) => b.id),
        ).toEqual(['b', 'a']);
        expect(moveBlock(sections, 'missing', 'first', 0)).toBe(sections);
        expect(moveBlock(sections, 'a', 'missing', 0)).toBe(sections);
    });
    it('does not silently drop a block when its destination has reached the limit', () => {
        const full = {
            id: 'full',
            columns: 1,
            blocks: Array.from({ length: 12 }, (_, i) => ({
                id: `f${i}`,
                type: 'text',
                title: 'Text',
            })),
        };
        const source = [...sections, full];
        expect(moveBlock(source, 'a', 'full', 0)).toBe(source);
    });
    it('creates independent block ids when duplicating a section with interactive content', () => {
        const copy = duplicateSection(sections[0]);
        expect(copy.id).not.toBe(sections[0].id);
        expect(copy.blocks[0].id).not.toBe('a');
        copy.blocks[0].title = 'Changed';
        expect(sections[0].blocks[0].title).toBe('First');
    });
    it('respects explicit resizing over the section column balance', () => {
        expect(blockSpan(sections[0], sections[0].blocks[0], 0)).toBe(8);
        expect(blockSpan(sections[0], sections[0].blocks[1], 1)).toBe(4);
        expect(
            blockSpan(sections[0], { ...sections[0].blocks[0], width: 12 }, 0),
        ).toBe(12);
    });
    it('keeps image settings intact when changing the hero height', () => {
        const change = vi.fn();
        render(
            <HeroSettings
                value={{ ...defaultHero, style: 'image', focal_y: 30 }}
                onChange={change}
            />,
        );
        fireEvent.click(screen.getByRole('radio', { name: 'Comfortable' }));
        expect(change).toHaveBeenCalledWith({
            ...defaultHero,
            style: 'image',
            focal_y: 30,
            height: 'comfortable',
        });
    });
});
