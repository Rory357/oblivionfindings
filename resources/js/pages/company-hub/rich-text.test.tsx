import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    HubFormattedText,
    HubRichTextEditor,
    readRichText,
    richTextPlainText,
    safeTextLink,
} from './_rich-text';

describe('guide formatting', () => {
    it.each(['ul', 'ol'])(
        'preserves %s lists created inside a paragraph by browser formatting commands',
        (tag) => {
            const editor = document.createElement('div');
            const paragraph = document.createElement('p');
            const list = document.createElement(tag);
            for (const text of [
                'Open the sample PDF.',
                'Keep the guide private.',
            ]) {
                const item = document.createElement('li');
                item.textContent = text;
                list.append(item);
            }
            // Construct the live editing DOM: parsing this through innerHTML can
            // repair the invalid paragraph nesting and miss the browser defect.
            paragraph.append('Before the list', list, 'After the list');
            editor.append(paragraph);
            const value = readRichText(editor);
            expect(value.blocks.map((block) => block.type)).toEqual([
                'paragraph',
                tag === 'ul' ? 'bullet_list' : 'ordered_list',
                'paragraph',
            ]);
            expect(richTextPlainText(value)).toBe(
                'Before the list\n\nOpen the sample PDF.\nKeep the guide private.\n\nAfter the list',
            );
            render(<HubFormattedText value={value} />);
            expect(screen.getAllByRole('listitem')).toHaveLength(2);
        },
    );
    it('preserves a table inserted inside a paragraph instead of flattening its cells', () => {
        const editor = document.createElement('div');
        const paragraph = document.createElement('p');
        const table = document.createElement('table');
        table.innerHTML =
            '<tr><th>Step</th><th>Owner</th></tr><tr><td>Preview</td><td><br></td></tr>';
        paragraph.append(table);
        editor.append(paragraph);
        const value = readRichText(editor);
        expect(value.blocks[0].type).toBe('table');
        expect(richTextPlainText(value)).toBe('Step\tOwner\nPreview\t');
        render(<HubFormattedText value={value} />);
        expect(screen.getAllByRole('columnheader')).toHaveLength(2);
        expect(screen.getAllByRole('cell')).toHaveLength(2);
    });
    it('adds and removes table columns without losing the other cells', () => {
        const onChange = vi.fn();
        render(
            <HubRichTextEditor
                body=""
                onChange={onChange}
                value={{
                    version: 1,
                    blocks: [
                        {
                            type: 'table',
                            header: true,
                            rows: [
                                [[{ text: 'Name' }], [{ text: 'Owner' }]],
                                [[{ text: 'Guide' }], [{ text: 'Team' }]],
                            ],
                        },
                    ],
                }}
            />,
        );
        const cell = screen.getByRole('cell', { name: 'Guide' });
        const range = document.createRange();
        range.selectNodeContents(cell);
        window.getSelection()?.removeAllRanges();
        window.getSelection()?.addRange(range);
        fireEvent.mouseUp(cell);
        fireEvent.click(screen.getByRole('button', { name: 'Add column' }));
        expect(screen.getAllByRole('columnheader')).toHaveLength(3);
        expect(onChange).toHaveBeenLastCalledWith(
            expect.any(Object),
            'Name\t\tOwner\nGuide\t\tTeam',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Remove column' }));
        expect(screen.getAllByRole('columnheader')).toHaveLength(2);
        expect(onChange).toHaveBeenLastCalledWith(
            expect.any(Object),
            '\tOwner\n\tTeam',
        );
    });
    it('keeps line boundaries inside list items and nested lists', () => {
        const element = document.createElement('div');
        element.innerHTML =
            '<ul><li><div>First line</div><div>Second line</div></li><li>Parent<ul><li>Child</li></ul></li></ul>';
        expect(richTextPlainText(readRichText(element))).toBe(
            'First line\nSecond line\nParent\nChild',
        );
    });
    it('preserves semantic formatting and a plain searchable projection', () => {
        const element = document.createElement('div');
        element.innerHTML =
            '<h2>Start <strong>here</strong></h2><div><p>First</p><p>Second <em>step</em></p></div><ul><li>A</li><li>B</li></ul><table><thead><tr><th>Name</th><th>Owner</th></tr></thead><tbody><tr><td>Guide</td><td>Team</td></tr></tbody></table>';
        const value = readRichText(element);
        expect(richTextPlainText(value)).toBe(
            'Start here\n\nFirst\n\nSecond step\n\nA\nB\n\nName\tOwner\nGuide\tTeam',
        );
        render(<HubFormattedText value={value} />);
        expect(
            screen.getByRole('heading', { name: 'Start here' }),
        ).toBeVisible();
        expect(screen.getAllByRole('columnheader')).toHaveLength(2);
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
    });
    it('rejects executable links and never renders HTML from a text run', () => {
        render(
            <HubFormattedText
                value={{
                    version: 1,
                    blocks: [
                        {
                            type: 'paragraph',
                            runs: [
                                {
                                    text: '<script>bad()</script>',
                                    href: 'javascript:alert(1)',
                                },
                            ],
                        },
                    ],
                }}
            />,
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(document.querySelector('script')).toBeNull();
        expect(safeTextLink('//outside.test')).toBeNull();
        expect(safeTextLink('HTTPS://example.com/guide')).toBe(
            'https://example.com/guide',
        );
    });
    it('retains an over-limit edit in the actual draft so validation cannot silently save earlier prose', () => {
        const change = vi.fn();
        render(<HubRichTextEditor body="Before" onChange={change} />);
        const editor = screen.getByRole('textbox', { name: 'Story' });
        editor.textContent = 'x'.repeat(20001);
        fireEvent.input(editor);
        expect(change).toHaveBeenLastCalledWith(
            expect.any(Object),
            'x'.repeat(20001),
        );
        expect(screen.getByRole('alert')).toHaveTextContent('20,000');
    });
    it('restores externally supplied text for editor undo without interpreting it as HTML', () => {
        const { rerender } = render(
            <HubRichTextEditor body="First" onChange={vi.fn()} />,
        );
        rerender(
            <HubRichTextEditor body="<b>Undo text</b>" onChange={vi.fn()} />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Story' }),
        ).toHaveTextContent('<b>Undo text</b>');
        expect(
            screen.getByRole('textbox', { name: 'Story' }).querySelector('b'),
        ).toBeNull();
    });
});
