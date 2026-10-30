import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Bold,
    Heading2,
    Heading3,
    Italic,
    Link2,
    List,
    ListOrdered,
    Table2,
    Type,
    Unlink,
} from 'lucide-react';
import { Fragment, useEffect, useRef, useState, type ReactNode } from 'react';

export type HubTextRun = {
    text: string;
    bold?: boolean;
    italic?: boolean;
    href?: string;
};
export type HubTextNode =
    | { type: 'paragraph'; runs: HubTextRun[] }
    | { type: 'heading'; level: 2 | 3 | 4; runs: HubTextRun[] }
    | { type: 'bullet_list' | 'ordered_list'; items: HubTextRun[][] }
    | { type: 'table'; header: boolean; rows: HubTextRun[][][] };
export type HubRichText = { version: 1; blocks: HubTextNode[] };

export function safeTextLink(value: string): string | null {
    if (
        !value ||
        value.length > 2000 ||
        Array.from(value).some(
            (char) => char.charCodeAt(0) <= 32 || char === '\\',
        ) ||
        value.startsWith('//')
    )
        return null;
    if (value.startsWith('/')) return value;
    try {
        const url = new URL(value);
        return url.protocol === 'https:' &&
            !url.username &&
            !url.password &&
            url.href.length <= 2000
            ? url.href
            : null;
    } catch {
        return null;
    }
}

export function richTextPlainText(value: HubRichText): string {
    const text = (runs: HubTextRun[]) => runs.map((run) => run.text).join('');
    return value.blocks
        .map((block) => {
            if ('runs' in block) return text(block.runs);
            if ('items' in block) return block.items.map(text).join('\n');
            return block.rows.map((row) => row.map(text).join('\t')).join('\n');
        })
        .join('\n\n');
}

function Runs({ runs }: { runs: HubTextRun[] }) {
    return runs.map((run, index) => {
        let child: ReactNode = run.text;
        if (run.bold) child = <strong>{child}</strong>;
        if (run.italic) child = <em>{child}</em>;
        const href = run.href ? safeTextLink(run.href) : null;
        if (href)
            child = (
                <a
                    href={href}
                    className="text-primary underline underline-offset-4"
                    rel="noopener noreferrer"
                >
                    {child}
                </a>
            );
        return <Fragment key={index}>{child}</Fragment>;
    });
}

export function HubFormattedText({
    value,
    body = '',
}: {
    value?: HubRichText | null;
    body?: string;
}) {
    if (!value)
        return (
            <div className="hub-formatted-text">
                {body.split('\n\n').map((paragraph, i) => (
                    <p key={i}>{paragraph}</p>
                ))}
            </div>
        );
    return (
        <div className="hub-formatted-text">
            {value.blocks.map((block, index) => {
                if (block.type === 'paragraph')
                    return (
                        <p key={index}>
                            <Runs runs={block.runs} />
                        </p>
                    );
                if (block.type === 'heading') {
                    const Heading = `h${block.level}` as 'h2' | 'h3' | 'h4';
                    return (
                        <Heading key={index}>
                            <Runs runs={block.runs} />
                        </Heading>
                    );
                }
                if ('items' in block) {
                    const ListElement =
                        block.type === 'ordered_list' ? 'ol' : 'ul';
                    return (
                        <ListElement key={index}>
                            {block.items.map((item, i) => (
                                <li key={i}>
                                    <Runs runs={item} />
                                </li>
                            ))}
                        </ListElement>
                    );
                }
                return (
                    <div className="overflow-x-auto" key={index}>
                        <table>
                            {block.header && (
                                <thead>
                                    <tr>
                                        {block.rows[0]?.map((cell, i) => (
                                            <th key={i} scope="col">
                                                <Runs runs={cell} />
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                            )}
                            <tbody>
                                {block.rows
                                    .slice(block.header ? 1 : 0)
                                    .map((row, i) => (
                                        <tr key={i}>
                                            {row.map((cell, j) => (
                                                <td key={j}>
                                                    <Runs runs={cell} />
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                            </tbody>
                        </table>
                    </div>
                );
            })}
        </div>
    );
}

/** Only semantic text is persisted; pasted HTML, attributes and embedded content never travel to the server. */
export function readRichText(element: HTMLElement): HubRichText {
    function runs(
        node: Node,
        marks: Omit<HubTextRun, 'text'> = {},
    ): HubTextRun[] {
        if (node.nodeType === Node.TEXT_NODE)
            return node.textContent
                ? [{ text: node.textContent, ...marks }]
                : [];
        if (!(node instanceof HTMLElement)) return [];
        const tag = node.tagName.toLowerCase();
        if (
            ['td', 'th', 'p', 'div', 'li'].includes(tag) &&
            node.childNodes.length === 1 &&
            node.firstChild instanceof HTMLElement &&
            node.firstChild.tagName === 'BR'
        )
            return [];
        if (['script', 'style', 'iframe', 'object', 'img'].includes(tag))
            return [];
        if (tag === 'br') return [{ text: '\n', ...marks }];
        const next = { ...marks };
        if (['strong', 'b'].includes(tag)) next.bold = true;
        if (['em', 'i'].includes(tag)) next.italic = true;
        if (tag === 'a') {
            const href = safeTextLink(node.getAttribute('href') ?? '');
            if (href) next.href = href;
        }
        const result: HubTextRun[] = [];
        let previousBlock = false;
        for (const child of Array.from(node.childNodes)) {
            const block =
                child instanceof HTMLElement &&
                ['P', 'DIV', 'LI', 'UL', 'OL'].includes(child.tagName);
            const parts = runs(child, next);
            if (
                parts.length &&
                result.length &&
                (block || previousBlock) &&
                !result[result.length - 1].text.endsWith('\n')
            )
                result.push({ text: '\n', ...next });
            result.push(...parts);
            previousBlock = block;
        }
        return result;
    }
    const blocks: HubTextNode[] = [];
    let inline: HubTextRun[] = [];
    const flush = () => {
        if (inline.length) {
            blocks.push({ type: 'paragraph', runs: inline });
            inline = [];
        }
    };
    for (const node of Array.from(element.childNodes)) {
        if (!(node instanceof HTMLElement)) {
            inline.push(...runs(node));
            continue;
        }
        const tag = node.tagName.toLowerCase();
        if (['h2', 'h3', 'h4', 'p', 'div', 'ul', 'ol', 'table'].includes(tag))
            flush();
        if (['h2', 'h3', 'h4'].includes(tag))
            blocks.push({
                type: 'heading',
                level: Number(tag[1]) as 2 | 3 | 4,
                runs: runs(node),
            });
        else if (tag === 'ul' || tag === 'ol')
            blocks.push({
                type: tag === 'ul' ? 'bullet_list' : 'ordered_list',
                items: Array.from(node.children)
                    .filter((child) => child.tagName === 'LI')
                    .map((child) => runs(child)),
            });
        else if (tag === 'table') {
            const rows = Array.from(node.children).flatMap((child) =>
                child.tagName === 'TR'
                    ? [child]
                    : ['THEAD', 'TBODY', 'TFOOT'].includes(child.tagName)
                      ? Array.from(child.children).filter(
                            (row) => row.tagName === 'TR',
                        )
                      : [],
            );
            blocks.push({
                type: 'table',
                header: !!rows[0]?.querySelector(':scope > th'),
                rows: rows.map((row) =>
                    Array.from(row.children)
                        .filter((cell) => ['TH', 'TD'].includes(cell.tagName))
                        .map((cell) => runs(cell)),
                ),
            });
        } else if (
            // Browser formatting commands can leave a semantic block inside
            // a paragraph (for example <p><ul>…</ul></p>). Preserve the child
            // structure just as we do for block containers from Enter.
            (tag === 'div' || tag === 'p') &&
            Array.from(node.children).some((child) =>
                ['P', 'DIV', 'H2', 'H3', 'H4', 'UL', 'OL', 'TABLE'].includes(
                    child.tagName,
                ),
            )
        )
            blocks.push(...readRichText(node).blocks);
        else if (tag === 'p' || tag === 'div')
            blocks.push({ type: 'paragraph', runs: runs(node) });
        else inline.push(...runs(node));
    }
    flush();
    return { version: 1, blocks };
}

function writeRichText(element: HTMLElement, value: HubRichText) {
    function appendRuns(parent: HTMLElement, runs: HubTextRun[]) {
        runs.forEach((run) => {
            let content: Node = document.createTextNode(run.text);
            for (const tag of [run.bold && 'strong', run.italic && 'em']) {
                if (tag) {
                    const wrapper = document.createElement(tag);
                    wrapper.append(content);
                    content = wrapper;
                }
            }
            const href = run.href ? safeTextLink(run.href) : null;
            if (href) {
                const link = document.createElement('a');
                link.href = href;
                link.append(content);
                content = link;
            }
            parent.append(content);
        });
        if (!runs.length) parent.append(document.createElement('br'));
    }
    element.replaceChildren();
    value.blocks.forEach((block) => {
        if ('runs' in block) {
            const child = document.createElement(
                block.type === 'heading' ? `h${block.level}` : 'p',
            );
            appendRuns(child, block.runs);
            element.append(child);
        } else if ('items' in block) {
            const list = document.createElement(
                block.type === 'ordered_list' ? 'ol' : 'ul',
            );
            block.items.forEach((runs) => {
                const item = document.createElement('li');
                appendRuns(item, runs);
                list.append(item);
            });
            element.append(list);
        } else {
            const table = document.createElement('table');
            block.rows.forEach((row, index) => {
                const tr = document.createElement('tr');
                row.forEach((runs) => {
                    const cell = document.createElement(
                        block.header && index === 0 ? 'th' : 'td',
                    );
                    appendRuns(cell, runs);
                    tr.append(cell);
                });
                table.append(tr);
            });
            element.append(table);
        }
    });
}

export function HubRichTextEditor({
    body,
    value,
    onChange,
}: {
    body: string;
    value?: HubRichText | null;
    onChange: (value: HubRichText, body: string) => void;
}) {
    const editor = useRef<HTMLDivElement>(null);
    const emitted = useRef<string | null>(null);
    const selection = useRef<Range | null>(null);
    const activeCell = useRef<HTMLTableCellElement | null>(null);
    const [tableSize, setTableSize] = useState<{
        rows: number;
        columns: number;
    } | null>(null);
    const [linkOpen, setLinkOpen] = useState(false),
        [url, setUrl] = useState(''),
        [error, setError] = useState('');
    const current = JSON.stringify(
        value ?? {
            version: 1,
            blocks: body
                .split('\n\n')
                .map((text) => ({ type: 'paragraph', runs: [{ text }] })),
        },
    );
    useEffect(() => {
        if (editor.current && emitted.current !== current) {
            writeRichText(editor.current, JSON.parse(current) as HubRichText);
            emitted.current = current;
        }
    }, [current]);
    const publish = () => {
        if (!editor.current) return;
        const next = readRichText(editor.current),
            text = richTextPlainText(next);
        setError(
            text.length > 20000
                ? 'Keep this text block under 20,000 characters before saving.'
                : next.blocks.length > 100
                  ? 'Keep this text block to 100 paragraphs, headings, lists and tables.'
                  : next.blocks.some(
                          (block) =>
                              'items' in block && block.items.length > 100,
                      )
                    ? 'Keep each list to 100 items.'
                    : next.blocks.some(
                            (block) =>
                                'rows' in block &&
                                (block.rows.length > 20 ||
                                    block.rows.some((row) => row.length > 10)),
                        )
                      ? 'Keep tables to 20 rows and 10 columns.'
                      : next.blocks.reduce(
                              (count, block) =>
                                  count +
                                  ('runs' in block
                                      ? block.runs.length
                                      : 'items' in block
                                        ? block.items.flat().length
                                        : block.rows.flat(2).length),
                              0,
                          ) > 1000
                        ? 'Simplify the formatting in this text block before saving.'
                        : '',
        );
        emitted.current = JSON.stringify(next);
        onChange(next, text);
    };
    const remember = () => {
        const selected = window.getSelection();
        if (
            selected?.rangeCount &&
            editor.current?.contains(selected.anchorNode) &&
            editor.current?.contains(selected.focusNode)
        ) {
            selection.current = selected.getRangeAt(0).cloneRange();
            const node =
                selected.anchorNode instanceof Element
                    ? selected.anchorNode
                    : selected.anchorNode?.parentElement;
            const cell = node?.closest<HTMLTableCellElement>('td,th') ?? null;
            activeCell.current = cell;
            setTableSize(
                cell
                    ? {
                          rows: cell.closest('table')?.rows.length ?? 0,
                          columns: (cell.parentElement as HTMLTableRowElement)
                              .cells.length,
                      }
                    : null,
            );
        }
    };
    const changeTable = (
        action: 'row' | 'column' | 'remove-row' | 'remove-column',
    ) => {
        const tableCell = activeCell.current;
        if (!tableCell || !editor.current?.contains(tableCell)) return;
        const table = tableCell.closest('table'),
            row = tableCell.parentElement as HTMLTableRowElement;
        if (!table) return;
        const index = tableCell.cellIndex;
        if (action === 'row' && table.rows.length < 20) {
            const next = table.insertRow(row.rowIndex + 1);
            for (let i = 0; i < row.cells.length; i++)
                next.insertCell().append(document.createElement('br'));
        } else if (action === 'column' && row.cells.length < 10) {
            Array.from(table.rows).forEach((item) => {
                const cell = document.createElement(
                    item.cells[index].tagName.toLowerCase(),
                );
                cell.append(document.createElement('br'));
                item.cells[index].after(cell);
            });
        } else if (action === 'remove-row') {
            if (table.rows.length === 1) table.remove();
            else row.remove();
            activeCell.current = null;
            setTableSize(null);
        } else if (action === 'remove-column') {
            if (row.cells.length === 1) table.remove();
            else
                Array.from(table.rows).forEach((item) =>
                    item.deleteCell(index),
                );
            activeCell.current = null;
            setTableSize(null);
        }
        if (activeCell.current)
            setTableSize({
                rows: table.rows.length,
                columns: table.rows[0]?.cells.length ?? 0,
            });
        publish();
    };
    const restore = () => {
        editor.current?.focus();
        const selected = window.getSelection();
        if (
            selection.current &&
            editor.current?.contains(selection.current.commonAncestorContainer)
        ) {
            selected?.removeAllRanges();
            selected?.addRange(selection.current);
        } else if (editor.current) {
            const range = document.createRange();
            range.selectNodeContents(editor.current);
            range.collapse(false);
            selected?.removeAllRanges();
            selected?.addRange(range);
        }
    };
    const command = (name: string, argument?: string) => {
        restore();
        document.execCommand(name, false, argument);
        remember();
        publish();
    };
    const tools = [
        ['Bold', Bold, 'bold'],
        ['Italic', Italic, 'italic'],
        ['Paragraph', Type, 'formatBlock', 'p'],
        ['Heading', Heading2, 'formatBlock', 'h2'],
        ['Subheading', Heading3, 'formatBlock', 'h3'],
        ['Bullet list', List, 'insertUnorderedList'],
        ['Numbered list', ListOrdered, 'insertOrderedList'],
        ['Add link', Link2, 'link'],
        ['Remove link', Unlink, 'unlink'],
    ] as const;
    return (
        <div className="hub-rich-editor">
            <div
                role="toolbar"
                aria-label="Text formatting"
                className="flex flex-wrap items-center gap-1 border-b bg-muted/40 p-2"
            >
                {tools.map(([label, Icon, action, argument]) => (
                    <Button
                        key={label}
                        type="button"
                        size="icon"
                        variant="ghost"
                        title={label}
                        aria-label={label}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => {
                            if (action === 'link') {
                                remember();
                                setUrl('');
                                setLinkOpen(true);
                            } else command(action, argument);
                        }}
                    >
                        <Icon className="size-4" />
                    </Button>
                ))}
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    title="Insert table"
                    aria-label="Insert table"
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={() => {
                        restore();
                        const selected = window.getSelection();
                        const range = selected?.rangeCount
                            ? selected.getRangeAt(0)
                            : null;
                        if (
                            !range ||
                            !editor.current?.contains(
                                range.commonAncestorContainer,
                            )
                        )
                            return;
                        const holder = document.createElement('div');
                        writeRichText(holder, {
                            version: 1,
                            blocks: [
                                {
                                    type: 'table',
                                    header: true,
                                    rows: [
                                        [
                                            [{ text: 'Column 1' }],
                                            [{ text: 'Column 2' }],
                                        ],
                                        [[{ text: '' }], [{ text: '' }]],
                                    ],
                                },
                                { type: 'paragraph', runs: [] },
                            ],
                        });
                        let paragraph: Node | null = range.startContainer;
                        while (
                            paragraph?.parentNode &&
                            paragraph.parentNode !== editor.current
                        )
                            paragraph = paragraph.parentNode;
                        if (
                            paragraph instanceof HTMLElement &&
                            paragraph.parentElement === editor.current
                        )
                            paragraph.after(...Array.from(holder.childNodes));
                        else {
                            range.collapse(false);
                            const fragment = document.createDocumentFragment();
                            fragment.append(...Array.from(holder.childNodes));
                            range.insertNode(fragment);
                        }
                        publish();
                    }}
                >
                    <Table2 className="size-4" />
                </Button>
            </div>
            {tableSize && (
                <div
                    role="toolbar"
                    aria-label="Table editing"
                    className="flex flex-wrap gap-1 border-b p-2"
                >
                    {(
                        [
                            ['row', 'Add row'],
                            ['column', 'Add column'],
                            ['remove-row', 'Remove row'],
                            ['remove-column', 'Remove column'],
                        ] as const
                    ).map(([action, label]) => (
                        <Button
                            key={action}
                            type="button"
                            variant="ghost"
                            size="sm"
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => changeTable(action)}
                            disabled={
                                action === 'row'
                                    ? tableSize.rows >= 20
                                    : action === 'column'
                                      ? tableSize.columns >= 10
                                      : false
                            }
                        >
                            {label}
                        </Button>
                    ))}
                </div>
            )}
            {linkOpen && (
                <form
                    className="flex items-center gap-2 border-b p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const safe = safeTextLink(url.trim());
                        if (!safe) {
                            setError(
                                'Use an https:// web address or an internal / link.',
                            );
                            return;
                        }
                        command('createLink', safe);
                        setLinkOpen(false);
                    }}
                >
                    <Input
                        autoFocus
                        aria-label="Link address"
                        placeholder="https://…"
                        value={url}
                        onChange={(event) => setUrl(event.target.value)}
                    />
                    <Button type="submit" size="sm">
                        Apply link
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setLinkOpen(false);
                            restore();
                        }}
                    >
                        Cancel
                    </Button>
                </form>
            )}
            <div
                ref={editor}
                role="textbox"
                aria-label="Story"
                aria-multiline="true"
                contentEditable
                suppressContentEditableWarning
                className="hub-formatted-text min-h-56 p-4 outline-none focus-visible:ring-2 focus-visible:ring-primary"
                onInput={publish}
                onKeyUp={remember}
                onMouseUp={remember}
                onPaste={(event) => {
                    event.preventDefault();
                    remember();
                    command(
                        'insertText',
                        event.clipboardData.getData('text/plain'),
                    );
                }}
                onDrop={(event) => {
                    event.preventDefault();
                    remember();
                    command(
                        'insertText',
                        event.dataTransfer.getData('text/plain'),
                    );
                }}
            />
            <div className="flex justify-between gap-3 border-t px-4 py-2 text-xs text-muted-foreground">
                <span>
                    Select text to format it. Paste brings in plain text.
                </span>
                <span>{body.length.toLocaleString()} / 20,000</span>
            </div>
            {error && (
                <p role="alert" className="p-3 text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}
