import { defaultNodeStyle, remapDiagramForImport } from './compatibility';
import type {
    DiagramNodeV2,
    DiagramPageV2,
    DiagramShapeKind,
    KnowledgeDiagramV2,
} from './contract';
import { bounds, descendants, editable } from './geometry';
import { assertDiagramSource } from './validation';

export const makeId = () => crypto.randomUUID();
export function blankPage(name = 'New page'): DiagramPageV2 {
    return {
        id: makeId(),
        name,
        subtitle: null,
        width: 1080,
        height: 680,
        orientation: 'landscape',
        paper: 'a3',
        scale: '1:1',
        background: '#ffffff',
        grid: { enabled: true, size: 10, snap: true },
        layers: [
            {
                id: makeId(),
                name: 'Diagram',
                visible: true,
                locked: false,
                print: true,
            },
        ],
        groups: [],
        nodes: [],
        edges: [],
    };
}
export function newNode(
    type: DiagramShapeKind,
    layerId: string,
    x: number,
    y: number,
    text: string | null = null,
): DiagramNodeV2 {
    return {
        id: makeId(),
        type,
        text,
        x,
        y,
        w: ['decision', 'diamond', 'gateway'].includes(type) ? 120 : 160,
        h: ['decision', 'diamond', 'gateway'].includes(type) ? 100 : 80,
        rotation: 0,
        layerId,
        parentId: null,
        locked: false,
        style: defaultNodeStyle(),
        properties: [],
        dataGraphic: null,
        points: [],
        imageFileId: null,
    };
}
export function changeDiagram(
    value: KnowledgeDiagramV2,
    update: (draft: KnowledgeDiagramV2) => void,
): KnowledgeDiagramV2 {
    const next = structuredClone(value);
    update(next);
    assertDiagramSource(next);
    return next;
}
export function selectedNodes(
    page: DiagramPageV2,
    ids: readonly string[],
): DiagramNodeV2[] {
    const members = new Set(descendants(page, ids));
    return page.nodes.filter((n) => members.has(n.id));
}
export function ensureEditable(
    page: DiagramPageV2,
    nodes: readonly DiagramNodeV2[],
): void {
    if (nodes.some((n) => !editable(n, page)))
        throw new Error(
            'Unlock and show every selected shape layer before changing this selection.',
        );
}
export function removeSelection(
    page: DiagramPageV2,
    ids: readonly string[],
    edgeId?: string,
): void {
    const selected = selectedNodes(page, ids);
    ensureEditable(page, selected);
    const removed = new Set(selected.map((n) => n.id.toLowerCase()));
    page.nodes = page.nodes.filter((n) => !removed.has(n.id.toLowerCase()));
    page.edges = page.edges.filter(
        (e) =>
            e.id !== edgeId &&
            !removed.has(e.from.toLowerCase()) &&
            !removed.has(e.to.toLowerCase()),
    );
    page.groups.forEach((g) => {
        if (g.parentId && removed.has(g.parentId.toLowerCase()))
            g.parentId = null;
    });
    page.nodes.forEach((n) => {
        if (n.parentId && removed.has(n.parentId.toLowerCase()))
            n.parentId = null;
    });
    pruneGroups(page);
}
function pruneGroups(page: DiagramPageV2): void {
    let changed = true;
    while (changed) {
        const parents = new Set(
            [...page.nodes, ...page.groups]
                .map((n) => n.parentId?.toLowerCase())
                .filter(Boolean),
        );
        const before = page.groups.length;
        page.groups = page.groups.filter((g) =>
            parents.has(g.id.toLowerCase()),
        );
        changed = page.groups.length !== before;
    }
}
export function groupSelection(
    page: DiagramPageV2,
    ids: readonly string[],
    container = false,
): string[] {
    const nodes = selectedNodes(page, ids);
    ensureEditable(page, nodes);
    if (nodes.length < 2 && !container)
        throw new Error('Select at least two shapes to group.');
    const box = bounds(nodes);
    if (!box) throw new Error('Select shapes first.');
    const selected = new Set(nodes.map((n) => n.id.toLowerCase()));
    const groups = page.groups.filter((g) => {
        const members = descendants(page, [g.id]);
        return (
            members.length > 0 &&
            members.every((id) => selected.has(id.toLowerCase()))
        );
    });
    groups.forEach((g) => selected.add(g.id.toLowerCase()));
    const roots = [...nodes, ...groups].filter(
        (n) => !n.parentId || !selected.has(n.parentId.toLowerCase()),
    );
    const parent = roots.every(
        (n) => n.parentId?.toLowerCase() === roots[0].parentId?.toLowerCase(),
    )
        ? roots[0].parentId
        : null;
    const id = makeId();
    if (container) {
        const shape = {
            ...newNode(
                'container',
                nodes[0].layerId,
                box.x - 22,
                box.y - 48,
                'Container',
            ),
            id,
            w: box.w + 44,
            h: box.h + 70,
            parentId: parent,
        };
        shape.style.fill = '#f5f1fc';
        page.nodes.unshift(shape);
    } else page.groups.push({ id, name: 'Group', parentId: parent });
    roots.forEach((n) => {
        n.parentId = id;
    });
    pruneGroups(page);
    return container ? [id] : nodes.map((n) => n.id);
}
export function ungroupSelection(
    page: DiagramPageV2,
    ids: readonly string[],
): void {
    const nodes = selectedNodes(page, ids);
    ensureEditable(page, nodes);
    const groups = new Set(
        nodes
            .map((n) => n.parentId?.toLowerCase())
            .filter((id): id is string =>
                page.groups.some((g) => g.id.toLowerCase() === id),
            ),
    );
    for (const id of groups) {
        const group = page.groups.find((g) => g.id.toLowerCase() === id)!;
        [...page.nodes, ...page.groups].forEach((n) => {
            if (n.parentId?.toLowerCase() === id) n.parentId = group.parentId;
        });
    }
    page.groups = page.groups.filter((g) => !groups.has(g.id.toLowerCase()));
    pruneGroups(page);
}
export type DiagramClipboard = { source: KnowledgeDiagramV2 };
export function copySelection(
    value: KnowledgeDiagramV2,
    page: DiagramPageV2,
    ids: readonly string[],
): DiagramClipboard {
    const nodes = selectedNodes(page, ids);
    ensureEditable(page, nodes);
    const kept = new Set(nodes.map((n) => n.id.toLowerCase()));
    const source = structuredClone(value);
    source.pages = [structuredClone(page)];
    const target = source.pages[0];
    target.nodes = target.nodes.filter((n) => kept.has(n.id.toLowerCase()));
    target.edges = target.edges.filter(
        (e) => kept.has(e.from.toLowerCase()) && kept.has(e.to.toLowerCase()),
    );
    target.nodes.forEach((n) => {
        if (
            n.parentId &&
            !kept.has(n.parentId.toLowerCase()) &&
            !target.groups.some(
                (g) => g.id.toLowerCase() === n.parentId?.toLowerCase(),
            )
        )
            n.parentId = null;
    });
    target.groups.forEach((g) => {
        if (
            g.parentId &&
            !kept.has(g.parentId.toLowerCase()) &&
            !target.groups.some(
                (p) => p.id.toLowerCase() === g.parentId?.toLowerCase(),
            )
        )
            g.parentId = null;
    });
    pruneGroups(target);
    assertDiagramSource(source);
    return { source };
}
export function pasteSelection(
    page: DiagramPageV2,
    clipboard: DiagramClipboard,
    layerId: string,
): string[] {
    const imageIds = clipboard.source.pages.flatMap((p) =>
        p.nodes.flatMap((n) => (n.imageFileId === null ? [] : [n.imageFileId])),
    );
    // Internal clipboard is scoped to one host record/session, never exposed as cross-document authorization.
    const copy = remapDiagramForImport(clipboard.source, {
        imageFileIds: new Map(imageIds.map((id) => [id, id])),
    }).pages[0];
    copy.nodes.forEach((n) => {
        n.x += 20;
        n.y += 20;
        n.layerId = layerId;
    });
    copy.edges.forEach((e) => {
        e.layerId = layerId;
    });
    page.nodes.push(...copy.nodes);
    page.edges.push(...copy.edges);
    page.groups.push(...copy.groups);
    return copy.nodes.map((n) => n.id);
}

export function parseCsv(text: string): {
    headers: string[];
    rows: string[][];
} {
    if (new TextEncoder().encode(text).byteLength > 262144)
        throw new Error('Use a CSV file smaller than 256 KiB.');
    const rows: string[][] = [];
    let row: string[] = [],
        cell = '',
        quoted = false,
        closed = false;
    for (let i = 0; i < text.length; i++) {
        const char = text[i];
        if (quoted) {
            if (char === '"' && text[i + 1] === '"') {
                cell += '"';
                i++;
            } else if (char === '"') {
                quoted = false;
                closed = true;
            } else cell += char;
        } else if (char === '"') {
            if (cell || closed) throw new Error('A CSV quote is out of place.');
            quoted = true;
        } else if (char === ',' || char === '\r' || char === '\n') {
            row.push(cell);
            cell = '';
            closed = false;
            if (char !== ',') {
                if (row.some((v) => v.length)) rows.push(row);
                row = [];
                if (char === '\r' && text[i + 1] === '\n') i++;
            }
        } else {
            if (closed)
                throw new Error('Unexpected text after a CSV closing quote.');
            cell += char;
        }
    }
    if (quoted) throw new Error('Close the quoted CSV field.');
    row.push(cell);
    if (row.some((v) => v.length)) rows.push(row);
    const headers =
        rows
            .shift()
            ?.map((h, i) => (i === 0 ? h.replace(/^\uFEFF/, '') : h).trim()) ??
        [];
    if (
        !headers.length ||
        !rows.length ||
        headers.length > 17 ||
        rows.length > 100 ||
        headers.some((h) => !h) ||
        new Set(headers.map((h) => h.toLowerCase())).size !== headers.length
    )
        throw new Error(
            'Use unique column headers and 1–100 rows, with at most 17 columns.',
        );
    if (rows.some((r) => r.length !== headers.length))
        throw new Error('Every CSV row must have the same number of columns.');
    return { headers, rows };
}
