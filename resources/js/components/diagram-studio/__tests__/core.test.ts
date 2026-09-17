import type { KnowledgeDiagramV2 } from '@/lib/diagram-studio/contract';
import {
    bounds,
    edgeGeometry,
    editable,
    selectionForNode,
} from '@/lib/diagram-studio/geometry';
import {
    changeDiagram,
    copySelection,
    groupSelection,
    makeId,
    newNode,
    parseCsv,
    pasteSelection,
    removeSelection,
} from '@/lib/diagram-studio/operations';
import { STARTERS, createStarterPage } from '@/lib/diagram-studio/templates';
import { validateDiagramSource } from '@/lib/diagram-studio/validation';
import { describe, expect, it } from 'vitest';
import source from '../../../../../tests/fixtures/it/knowledge-diagrams/v2-rich.json';

const rich = () => structuredClone(source) as KnowledgeDiagramV2;
describe('shared drawing transactions', () => {
    it('selects nested group members and moves no locked members', () => {
        const page = rich().pages[0],
            node = page.nodes[3];
        expect(selectionForNode(page, node)).toEqual(
            expect.arrayContaining([page.nodes[3].id, page.nodes[4].id]),
        );
        page.nodes[4].locked = true;
        const before = JSON.stringify(page);
        expect(() =>
            groupSelection(page, [page.nodes[3].id, page.nodes[4].id]),
        ).toThrow(/Unlock/);
        expect(JSON.stringify(page)).toBe(before);
        expect(editable(page.nodes[4], page)).toBe(false);
    });
    it('copies nested groups with all references remapped and leaves original source intact', () => {
        const value = rich(),
            page = value.pages[0],
            before = JSON.stringify(value);
        const clipboard = copySelection(value, page, [
            page.nodes[3].id,
            page.nodes[4].id,
        ]);
        const result = changeDiagram(value, (d) => {
            pasteSelection(d.pages[0], clipboard, d.pages[0].layers[0].id);
        });
        expect(JSON.stringify(value)).toBe(before);
        expect(validateDiagramSource(result).valid).toBe(true);
        expect(result.pages[0].nodes.length).toBe(page.nodes.length + 2);
        expect(new Set(result.pages[0].nodes.map((n) => n.id)).size).toBe(
            result.pages[0].nodes.length,
        );
    });
    it('removes contained shapes, affected connectors and empty groups atomically', () => {
        const value = rich(),
            page = value.pages[0];
        page.layers[2].locked = false;
        const result = changeDiagram(value, (d) =>
            removeSelection(d.pages[0], [page.nodes[0].id]),
        );
        expect(validateDiagramSource(result).valid).toBe(true);
        expect(
            result.pages[0].nodes.some((n) =>
                [page.nodes[0].id, page.nodes[3].id, page.nodes[4].id].includes(
                    n.id,
                ),
            ),
        ).toBe(false);
        expect(result.pages[0].groups).toHaveLength(0);
    });
    it('rejects transactions that exceed limits without changing the source', () => {
        const value = rich(),
            before = JSON.stringify(value);
        expect(() =>
            changeDiagram(value, (d) => {
                d.pages[0].nodes[0].w = -1;
            }),
        ).toThrow();
        expect(JSON.stringify(value)).toBe(before);
    });
    it('tracks all connector modes after rotation and movement with finite label positions', () => {
        const page = rich().pages[0],
            edge = page.edges[0],
            node = page.nodes.find((n) => n.id === edge.from)!;
        for (const route of ['straight', 'orthogonal', 'curved'] as const) {
            edge.route = route;
            const before = edgeGeometry(edge, page.nodes)!;
            node.x += 70;
            node.rotation += 30;
            const next = edgeGeometry(edge, page.nodes)!;
            expect(next.d).not.toBe(before.d);
            expect(
                Number.isFinite(next.label.x) && Number.isFinite(next.label.y),
            ).toBe(true);
        }
    });
    it('places a curve label at its actual endpoints for 0 and 1', () => {
        const page = rich().pages[0],
            edge = page.edges[0];
        edge.route = 'curved';
        edge.labelPos = 0;
        const first = edgeGeometry(edge, page.nodes)!;
        expect(first.label).toMatchObject({
            x: first.points[0].x,
            y: first.points[0].y,
        });
        edge.labelPos = 1;
        const last = edgeGeometry(edge, page.nodes)!;
        expect(last.label).toMatchObject({
            x: last.points.at(-1)!.x,
            y: last.points.at(-1)!.y,
        });
    });
    it('uses rotated bounds for selection and alignment', () => {
        const n = newNode('rectangle', makeId(), 10, 20);
        n.w = 160;
        n.h = 80;
        n.rotation = 90;
        const box = bounds([n])!;
        expect(box.x).toBeCloseTo(50);
        expect(box.y).toBeCloseTo(-20);
        expect(box.w).toBeCloseTo(80);
        expect(box.h).toBeCloseTo(160);
    });
    it('every starter creates fresh valid IDs and no descriptive sample properties', () => {
        for (const starter of STARTERS) {
            const a = createStarterPage(starter.id),
                b = createStarterPage(starter.id);
            expect(a.id).not.toBe(b.id);
            expect(
                validateDiagramSource({
                    schema_version: 2,
                    id: makeId(),
                    title: 'Chosen example',
                    pages: [a],
                }).valid,
            ).toBe(true);
            expect(a.nodes.every((n) => n.properties.length === 0)).toBe(true);
        }
    });
    it('parses quoted/multiline CSV and rejects malformed/inconsistent input', () => {
        expect(
            parseCsv(
                'label,notes\r\n"First, step","Line one\nLine two"\r\n"A ""quote""",Ready',
            ),
        ).toEqual({
            headers: ['label', 'notes'],
            rows: [
                ['First, step', 'Line one\nLine two'],
                ['A "quote"', 'Ready'],
            ],
        });
        expect(() => parseCsv('a,b\n"open,b')).toThrow(/quoted/);
        expect(() => parseCsv('a,b\nx,y,z')).toThrow(/same number/);
        expect(() => parseCsv('a,A\nx,y')).toThrow(/unique/);
    });
    it('requires whole-string formats but retains multiline scalar text', () => {
        const value = rich();
        value.pages[0].nodes[0].text = 'Line one\nLine two';
        expect(validateDiagramSource(value).valid).toBe(true);
        value.pages[0].scale = '1:1\n';
        expect(validateDiagramSource(value).valid).toBe(false);
        value.pages[0].scale = '1:1';
        value.pages[0].nodes[0].properties = [
            { key: 'progress\n', value: '50' },
        ];
        expect(validateDiagramSource(value).valid).toBe(false);
        value.pages[0].nodes[0].properties = [
            { key: 'progress', value: '50\n' },
        ];
        value.pages[0].nodes[0].dataGraphic = 'progress';
        expect(validateDiagramSource(value).valid).toBe(false);
    });
    it('retains mixed-case connector and group references when copying', () => {
        const value = rich(),
            page = value.pages[0];
        page.edges.forEach((e) => {
            e.from = e.from.toUpperCase();
            e.to = e.to.toUpperCase();
        });
        page.nodes.forEach((n) => {
            if (n.parentId) n.parentId = n.parentId.toUpperCase();
        });
        const selected = [page.nodes[3].id, page.nodes[4].id];
        const copy = copySelection(value, page, selected).source;
        expect(validateDiagramSource(copy).valid).toBe(true);
        expect(copy.pages[0].edges.length).toBe(1);
    });
    it('preserves the nested forest when grouping an existing container with its contents', () => {
        const value = rich(),
            page = value.pages[0];
        page.layers.forEach((l) => {
            l.locked = false;
        });
        const groupIds = page.groups.map((g) => g.id);
        const result = changeDiagram(value, (d) => {
            groupSelection(d.pages[0], [page.nodes[0].id]);
        });
        expect(validateDiagramSource(result).valid).toBe(true);
        expect(result.pages[0].groups.map((g) => g.id)).toEqual(
            expect.arrayContaining(groupIds),
        );
    });
});
