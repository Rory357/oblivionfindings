import type {
    KnowledgeDiagramSource,
    KnowledgeDiagramV2,
    LegacyKnowledgeDiagram,
} from './contract';
import { DIAGRAM_ENUMS as E, DIAGRAM_LIMITS as L } from './contract';
import type { WireRule } from './schema';
import {
    FORBIDDEN_TEXT_PATTERN,
    PERCENT_PATTERN,
    WIRE_OBJECTS,
} from './schema';

export type DiagramIssue = { path: string; code: string; message: string };
export type DiagramValidation = { valid: boolean; issues: DiagramIssue[] };
const folded = (id: string) => id.toLowerCase();
const own = (value: object, key: string) =>
    Object.prototype.hasOwnProperty.call(value, key);
const isRecord = (value: unknown): value is Record<string, unknown> =>
    value !== null &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    [Object.prototype, null].includes(Object.getPrototypeOf(value));

export function diagramJsonBytes(value: unknown): number {
    const json = JSON.stringify(value);
    if (json === undefined) throw new Error('Diagram source is not JSON.');
    return new TextEncoder().encode(json).byteLength;
}

export function validateDiagramSource(value: unknown): DiagramValidation {
    const issues: DiagramIssue[] = [];
    const issue = (path: string, code: string, message: string) => {
        if (issues.length < 100) issues.push({ path, code, message });
    };
    const fields = WIRE_OBJECTS as Record<string, Record<string, WireRule>>;
    const check = (v: unknown, rule: WireRule, path: string): void => {
        if (issues.length >= 100) return;
        if (v === null && rule.nullable) return;
        switch (rule.kind) {
            case 'string': {
                if (typeof v !== 'string') {
                    issue(path, 'string', 'Use plain text.');
                    return;
                }
                const characters = [...v];
                const length = characters.length;
                if (length < rule.min || length > rule.max)
                    issue(
                        path,
                        'length',
                        `Text length must be ${rule.min}..${rule.max} characters.`,
                    );
                if (rule.nonblank && v.trim().length === 0)
                    issue(path, 'blank', 'Enter a name.');
                if (rule.pattern && new RegExp(rule.pattern).exec(v)?.[0] !== v)
                    issue(
                        path,
                        'pattern',
                        'The value is not in the accepted format.',
                    );
                if (
                    characters.some(
                        (c) =>
                            c.length === 1 &&
                            c.charCodeAt(0) >= 0xd800 &&
                            c.charCodeAt(0) <= 0xdfff,
                    ) ||
                    new RegExp(FORBIDDEN_TEXT_PATTERN).test(v)
                )
                    issue(
                        path,
                        'text',
                        'Text contains unsupported control characters or invalid Unicode.',
                    );
                return;
            }
            case 'number':
                if (
                    typeof v !== 'number' ||
                    !Number.isFinite(v) ||
                    v < rule.min ||
                    v > rule.max ||
                    (rule.integer && !Number.isSafeInteger(v))
                )
                    issue(
                        path,
                        'number',
                        `Use a finite ${rule.integer ? 'integer' : 'number'} from ${rule.min} to ${rule.max}.`,
                    );
                return;
            case 'boolean':
                if (typeof v !== 'boolean')
                    issue(path, 'boolean', 'Use true or false.');
                return;
            case 'enum':
                if (!rule.values.includes(v as string | number))
                    issue(path, 'enum', 'Choose a supported value.');
                return;
            case 'array':
                if (!Array.isArray(v)) {
                    issue(path, 'array', 'Use an ordered array.');
                    return;
                }
                if (v.length < rule.min || v.length > rule.max) {
                    issue(
                        path,
                        'count',
                        `Use ${rule.min}..${rule.max} entries.`,
                    );
                    return;
                }
                for (let i = 0; i < v.length; i++)
                    check(v[i], rule.items, `${path}.${i}`);
                return;
            case 'object': {
                if (!isRecord(v)) {
                    issue(path, 'object', 'Use a plain object.');
                    return;
                }
                const shape = fields[rule.name];
                for (const key of Object.keys(v))
                    if (!own(shape, key))
                        issue(
                            `${path}.${key}`,
                            'unknown_field',
                            'This field is not accepted.',
                        );
                for (const [key, child] of Object.entries(shape)) {
                    if (!own(v, key))
                        issue(
                            `${path}.${key}`,
                            'required',
                            'This field must be present.',
                        );
                    else check(v[key], child, `${path}.${key}`);
                }
            }
        }
    };

    if (!isRecord(value))
        return {
            valid: false,
            issues: [
                {
                    path: 'diagram',
                    code: 'object',
                    message: 'Use a diagram object.',
                },
            ],
        };
    const v2 = own(value, 'schema_version');
    if (v2 && value.schema_version !== 2)
        return {
            valid: false,
            issues: [
                {
                    path: 'diagram.schema_version',
                    code: 'version',
                    message:
                        'This diagram version is not supported. The original source has been retained.',
                },
            ],
        };
    check(
        value,
        { kind: 'object', name: v2 ? 'diagramV2' : 'legacy' },
        'diagram',
    );
    if (issues.length) return { valid: false, issues };
    try {
        if (diagramJsonBytes(value) > L.diagramBytes)
            issue(
                'diagram',
                'bytes',
                `Diagram source exceeds ${L.diagramBytes} UTF-8 bytes.`,
            );
    } catch {
        issue('diagram', 'json', 'Diagram source cannot be serialized.');
    }

    if (!v2) {
        const d = value as LegacyKnowledgeDiagram;
        const nodes = new Set(d.nodes.map((n) => n.id));
        if (nodes.size !== d.nodes.length)
            issue(
                'diagram.nodes',
                'duplicate_id',
                'Shape identities must be unique.',
            );
        if (new Set(d.edges.map((e) => e.id)).size !== d.edges.length)
            issue(
                'diagram.edges',
                'duplicate_id',
                'Connector identities must be unique.',
            );
        d.edges.forEach((e, i) => {
            if (e.from === e.to || !nodes.has(e.from) || !nodes.has(e.to))
                issue(
                    `diagram.edges.${i}`,
                    'endpoint',
                    'Each connector must join two existing shapes.',
                );
        });
        return { valid: issues.length === 0, issues };
    }

    const d = value as KnowledgeDiagramV2;
    const allIds = new Set<string>();
    const identity = (id: string, path: string) => {
        const key = folded(id);
        if (allIds.has(key))
            issue(
                path,
                'duplicate_id',
                'Each diagram element must have its own identity.',
            );
        allIds.add(key);
    };
    identity(d.id, 'diagram.id');
    let nodesTotal = 0,
        edgesTotal = 0,
        groupsTotal = 0,
        pointsTotal = 0;
    d.pages.forEach((p, pageIndex) => {
        const path = `diagram.pages.${pageIndex}`;
        identity(p.id, `${path}.id`);
        p.layers.forEach((l, i) => identity(l.id, `${path}.layers.${i}.id`));
        p.groups.forEach((g, i) => identity(g.id, `${path}.groups.${i}.id`));
        p.nodes.forEach((n, i) => identity(n.id, `${path}.nodes.${i}.id`));
        p.edges.forEach((e, i) => identity(e.id, `${path}.edges.${i}.id`));
        const layers = new Set(p.layers.map((l) => folded(l.id)));
        const nodes = new Map(p.nodes.map((n) => [folded(n.id), n]));
        const parents = new Map(
            [...p.groups, ...p.nodes].map((n) => [
                folded(n.id),
                n.parentId === null ? null : folded(n.parentId),
            ]),
        );
        const containers = new Set([
            ...p.groups.map((g) => folded(g.id)),
            ...p.nodes
                .filter((n) =>
                    (E.containerShape as readonly string[]).includes(n.type),
                )
                .map((n) => folded(n.id)),
        ]);
        for (const [id, parent] of parents) {
            if (parent !== null && !containers.has(parent))
                issue(
                    path,
                    'parent',
                    'Each parent must be a group or supported container on this page.',
                );
            const visited = new Set([id]);
            let current = parent;
            while (current !== null && parents.has(current)) {
                if (visited.has(current)) {
                    issue(
                        path,
                        'cycle',
                        'Groups and containers cannot contain themselves or form cycles.',
                    );
                    break;
                }
                visited.add(current);
                current = parents.get(current)!;
            }
        }
        p.nodes.forEach((n, i) => {
            const np = `${path}.nodes.${i}`;
            if (!layers.has(folded(n.layerId)))
                issue(`${np}.layerId`, 'layer', 'Choose a layer on this page.');
            if (n.type === 'ink' ? n.points.length < 2 : n.points.length !== 0)
                issue(
                    `${np}.points`,
                    'points',
                    'Ink needs at least two points; other shapes must have no points.',
                );
            if (
                n.type === 'image'
                    ? n.imageFileId === null
                    : n.imageFileId !== null
            )
                issue(
                    `${np}.imageFileId`,
                    'image',
                    'Only images require a file reference.',
                );
            const keys = new Set<string>();
            for (const prop of n.properties) {
                const key = prop.key.toLowerCase();
                if (keys.has(key) || ['constructor', 'prototype'].includes(key))
                    issue(
                        `${np}.properties`,
                        'property_key',
                        'Use unique, supported field names.',
                    );
                keys.add(key);
            }
            if (n.dataGraphic !== null) {
                const prop = n.properties.find((p) => p.key === n.dataGraphic);
                if (
                    !prop ||
                    prop.value === null ||
                    new RegExp(PERCENT_PATTERN).exec(prop.value)?.[0] !==
                        prop.value ||
                    Number(prop.value) > 100
                )
                    issue(
                        `${np}.dataGraphic`,
                        'data_graphic',
                        'Choose a property containing a decimal percentage from 0 to 100.',
                    );
            }
            pointsTotal += n.points.length;
        });
        p.edges.forEach((e, i) => {
            if (!layers.has(folded(e.layerId)))
                issue(
                    `${path}.edges.${i}.layerId`,
                    'layer',
                    'Choose a layer on this page.',
                );
            if (
                folded(e.from) === folded(e.to) ||
                !nodes.has(folded(e.from)) ||
                !nodes.has(folded(e.to))
            )
                issue(
                    `${path}.edges.${i}`,
                    'endpoint',
                    'Each connector must join two different shapes on this page.',
                );
        });
        nodesTotal += p.nodes.length;
        edgesTotal += p.edges.length;
        groupsTotal += p.groups.length;
    });
    if (nodesTotal > L.nodesPerDiagram)
        issue(
            'diagram.pages',
            'nodes_total',
            `Use at most ${L.nodesPerDiagram} shapes across all pages.`,
        );
    if (edgesTotal > L.edgesPerDiagram)
        issue(
            'diagram.pages',
            'edges_total',
            `Use at most ${L.edgesPerDiagram} connectors across all pages.`,
        );
    if (groupsTotal > L.groupsPerDiagram)
        issue(
            'diagram.pages',
            'groups_total',
            `Use at most ${L.groupsPerDiagram} groups across all pages.`,
        );
    if (pointsTotal > L.pointsPerDiagram)
        issue(
            'diagram.pages',
            'points_total',
            `Use at most ${L.pointsPerDiagram} ink points across all pages.`,
        );
    return { valid: issues.length === 0, issues };
}

export function validateDiagramCollection(value: unknown): DiagramValidation {
    if (!Array.isArray(value) || value.length > L.diagrams)
        return {
            valid: false,
            issues: [
                {
                    path: 'diagrams',
                    code: 'count',
                    message: `Use at most ${L.diagrams} diagrams.`,
                },
            ],
        };
    const issues: DiagramIssue[] = [];
    const ids = new Set<string>();
    value.forEach((d, i) => {
        const result = validateDiagramSource(d);
        issues.push(
            ...result.issues.map((e) => ({
                ...e,
                path: e.path.replace(/^diagram/, `diagrams.${i}`),
            })),
        );
        if (result.valid) {
            const key = folded((d as KnowledgeDiagramSource).id);
            if (ids.has(key))
                issues.push({
                    path: `diagrams.${i}.id`,
                    code: 'duplicate_id',
                    message: 'Diagram identities must be unique.',
                });
            ids.add(key);
        }
    });
    try {
        if (diagramJsonBytes(value) > L.collectionBytes)
            issues.push({
                path: 'diagrams',
                code: 'bytes',
                message: `Combined source exceeds ${L.collectionBytes} UTF-8 bytes.`,
            });
    } catch {
        issues.push({
            path: 'diagrams',
            code: 'json',
            message: 'Diagram source cannot be serialized.',
        });
    }
    return { valid: issues.length === 0, issues };
}

export function assertDiagramSource(
    value: unknown,
): asserts value is KnowledgeDiagramSource {
    const result = validateDiagramSource(value);
    if (!result.valid)
        throw new Error(
            result.issues.map((i) => `${i.path}: ${i.message}`).join('\n'),
        );
}

export function referencedImageFileIds(value: KnowledgeDiagramV2): number[] {
    return [
        ...new Set(
            value.pages.flatMap((p) =>
                p.nodes.flatMap((n) =>
                    n.type === 'image' && n.imageFileId !== null
                        ? [n.imageFileId]
                        : [],
                ),
            ),
        ),
    ];
}
