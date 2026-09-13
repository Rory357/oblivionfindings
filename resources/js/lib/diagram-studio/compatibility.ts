import type {
    DiagramNodeStyle,
    DiagramNodeV2,
    KnowledgeDiagramV2,
    LegacyKnowledgeDiagram,
} from './contract';
import { assertDiagramSource } from './validation';

export const defaultNodeStyle = (): DiagramNodeStyle => ({
    fill: '#ffffff',
    stroke: '#83749f',
    color: '#302b48',
    fontSize: 17,
    font: 'sans',
    bold: false,
    italic: false,
    underline: false,
    align: 'center',
    strokeWidth: 1.5,
    dash: 'solid',
    opacity: 1,
});

/** Pure, explicit upgrade for editing. Merely reading a legacy record does not call a save callback. */
export function upgradeLegacyDiagram(
    legacy: LegacyKnowledgeDiagram,
    makeId: () => string = () => crypto.randomUUID(),
): KnowledgeDiagramV2 {
    assertDiagramSource(legacy);
    const pageId = makeId(),
        layerId = makeId();
    const value: KnowledgeDiagramV2 = {
        schema_version: 2,
        id: legacy.id,
        title: legacy.title,
        pages: [
            {
                id: pageId,
                name: legacy.title,
                subtitle: null,
                width: 1000,
                height: 600,
                orientation: 'landscape',
                paper: 'custom',
                scale: '1:1',
                background: '#ffffff',
                grid: { enabled: true, size: 10, snap: true },
                layers: [
                    {
                        id: layerId,
                        name: 'Diagram',
                        visible: true,
                        locked: false,
                        print: true,
                    },
                ],
                groups: [],
                nodes: legacy.nodes.map(
                    (n): DiagramNodeV2 => ({
                        id: n.id,
                        type: n.shape,
                        text: n.text,
                        x: n.x,
                        y: n.y,
                        w: 160,
                        h: 80,
                        rotation: 0,
                        layerId,
                        parentId: null,
                        locked: false,
                        style: {
                            ...defaultNodeStyle(),
                            fontSize: 14,
                            strokeWidth: 2,
                        },
                        properties: [],
                        dataGraphic: null,
                        points: [],
                        imageFileId: null,
                    }),
                ),
                edges: legacy.edges.map((e) => ({
                    ...e,
                    layerId,
                    route: 'straight',
                    fromPort: 'auto',
                    toPort: 'auto',
                    arrow: 'triangle',
                    startArrow: 'none',
                    stroke: '#83749f',
                    width: 2,
                    dash: 'solid',
                    bend: 0,
                    labelPos: 0.5,
                })),
            },
        ],
    };
    // Old contracts allowed cross-kind identity collisions. Preserve their source and report the
    // conflict instead of silently changing existing identities or saving a lossy upgrade.
    assertDiagramSource(value);
    return value;
}

/** Imported source receives new identities atomically; image references need explicit host mapping. */
export function remapDiagramForImport(
    source: KnowledgeDiagramV2,
    options: {
        targetDiagramId?: string;
        imageFileIds?: ReadonlyMap<number, number>;
        makeId?: () => string;
    } = {},
): KnowledgeDiagramV2 {
    assertDiagramSource(source);
    const makeId = options.makeId ?? (() => crypto.randomUUID());
    const value = structuredClone(source);
    const ids = new Map<string, string>();
    ids.set(value.id.toLowerCase(), options.targetDiagramId ?? makeId());
    for (const page of value.pages) {
        for (const element of [
            page,
            ...page.layers,
            ...page.groups,
            ...page.nodes,
            ...page.edges,
        ])
            ids.set(element.id.toLowerCase(), makeId());
    }
    const ref = (oldId: string): string => {
        const id = ids.get(oldId.toLowerCase());
        if (!id)
            throw new Error('An imported reference has no mapped identity.');
        return id;
    };
    value.id = ref(value.id);
    for (const page of value.pages) {
        page.id = ref(page.id);
        for (const layer of page.layers) layer.id = ref(layer.id);
        for (const group of page.groups) {
            group.id = ref(group.id);
            group.parentId =
                group.parentId === null ? null : ref(group.parentId);
        }
        for (const node of page.nodes) {
            node.id = ref(node.id);
            node.layerId = ref(node.layerId);
            node.parentId = node.parentId === null ? null : ref(node.parentId);
            if (node.imageFileId !== null) {
                const fileId = options.imageFileIds?.get(node.imageFileId);
                if (fileId === undefined)
                    throw new Error(
                        'Resolve each imported image to an authorized file on the target document before importing.',
                    );
                node.imageFileId = fileId;
            }
        }
        for (const edge of page.edges) {
            edge.id = ref(edge.id);
            edge.from = ref(edge.from);
            edge.to = ref(edge.to);
            edge.layerId = ref(edge.layerId);
        }
    }
    assertDiagramSource(value);
    return value;
}
