import type {
    DiagramEdgeV2,
    DiagramGroupV2,
    DiagramLayerV2,
    DiagramNodeStyle,
    DiagramNodeV2,
    DiagramPageV2,
    KnowledgeDiagramV2,
    LegacyDiagramNode,
    LegacyKnowledgeDiagram,
} from './contract';
import { DIAGRAM_ENUMS as E, DIAGRAM_LIMITS as L } from './contract';

export type WireRule = (
    | {
          kind: 'string';
          min: number;
          max: number;
          pattern?: string;
          nonblank?: boolean;
      }
    | { kind: 'number'; min: number; max: number; integer?: boolean }
    | { kind: 'boolean' }
    | { kind: 'enum'; values: readonly (string | number)[] }
    | { kind: 'object'; name: string }
    | { kind: 'array'; min: number; max: number; items: WireRule }
) & { nullable?: boolean };

export const UUID_PATTERN =
    '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$';
export const COLOR_PATTERN = '^#[0-9a-fA-F]{6}$';
export const PAINT_PATTERN = '^(?:#[0-9a-fA-F]{6}|none)$';
export const PROPERTY_KEY_PATTERN = '^[A-Za-z][A-Za-z0-9 _.-]{0,63}$';
export const SCALE_PATTERN = '^[1-9][0-9]{0,10}:[1-9][0-9]{0,10}$';
export const PERCENT_PATTERN = '^(?:0|[1-9][0-9]?|100)(?:\\.[0-9]{1,6})?$';
export const FORBIDDEN_TEXT_PATTERN = '[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]';

const str = (max: number, nullable = false): WireRule => ({
    kind: 'string',
    min: 0,
    max,
    nullable,
});
const name = (max: number): WireRule => ({
    kind: 'string',
    min: 1,
    max,
    nonblank: true,
});
const num = (min: number, max: number, integer = false): WireRule => ({
    kind: 'number',
    min,
    max,
    integer,
});
const oneOf = (values: readonly (string | number)[]): WireRule => ({
    kind: 'enum',
    values,
});
const obj = (name: string): WireRule => ({ kind: 'object', name });
const arr = (name: string, min: number, max: number): WireRule => ({
    kind: 'array',
    items: obj(name),
    min,
    max,
});
const uuid: WireRule = {
    kind: 'string',
    min: 36,
    max: 36,
    pattern: UUID_PATTERN,
};
const nullableUuid: WireRule = { ...uuid, nullable: true };
const color: WireRule = {
    kind: 'string',
    min: 7,
    max: 7,
    pattern: COLOR_PATTERN,
};
const paint: WireRule = {
    kind: 'string',
    min: 4,
    max: 7,
    pattern: PAINT_PATTERN,
};
const bool: WireRule = { kind: 'boolean' };
const propertyKey: WireRule = {
    kind: 'string',
    min: 1,
    max: L.propertyKeyLength,
    pattern: PROPERTY_KEY_PATTERN,
};

/** Every listed field is required, including nullable fields. Every unknown field is rejected. */
export const WIRE_OBJECTS = {
    diagramV2: {
        schema_version: oneOf([2]),
        id: uuid,
        title: name(L.titleLength),
        pages: arr('page', 1, L.pages),
    } satisfies Record<keyof KnowledgeDiagramV2, WireRule>,
    page: {
        id: uuid,
        name: name(L.pageNameLength),
        subtitle: str(L.subtitleLength, true),
        width: num(L.pageDimensionMin, L.pageDimensionMax),
        height: num(L.pageDimensionMin, L.pageDimensionMax),
        orientation: oneOf(E.orientation),
        paper: oneOf(E.paper),
        scale: {
            kind: 'string',
            min: 3,
            max: L.scaleLength,
            pattern: SCALE_PATTERN,
        },
        background: color,
        grid: obj('grid'),
        layers: arr('layer', 1, L.layersPerPage),
        groups: arr('group', 0, L.groupsPerDiagram),
        nodes: arr('node', 0, L.nodesPerDiagram),
        edges: arr('edge', 0, L.edgesPerDiagram),
    } satisfies Record<keyof DiagramPageV2, WireRule>,
    grid: {
        enabled: bool,
        size: num(L.gridSizeMin, L.gridSizeMax),
        snap: bool,
    },
    layer: {
        id: uuid,
        name: name(L.layerNameLength),
        visible: bool,
        locked: bool,
        print: bool,
    } satisfies Record<keyof DiagramLayerV2, WireRule>,
    group: {
        id: uuid,
        name: name(L.groupNameLength),
        parentId: nullableUuid,
    } satisfies Record<keyof DiagramGroupV2, WireRule>,
    node: {
        id: uuid,
        type: oneOf(E.shape),
        text: str(L.nodeTextLength, true),
        x: num(L.positionMin, L.positionMax),
        y: num(L.positionMin, L.positionMax),
        w: num(L.nodeDimensionMin, L.nodeDimensionMax),
        h: num(L.nodeDimensionMin, L.nodeDimensionMax),
        rotation: num(L.rotationMin, L.rotationMax),
        layerId: uuid,
        parentId: nullableUuid,
        locked: bool,
        style: obj('nodeStyle'),
        properties: arr('property', 0, L.propertiesPerNode),
        dataGraphic: { ...propertyKey, nullable: true },
        points: arr('point', 0, L.pointsPerNode),
        imageFileId: {
            ...num(1, Number.MAX_SAFE_INTEGER, true),
            nullable: true,
        },
    } satisfies Record<keyof DiagramNodeV2, WireRule>,
    nodeStyle: {
        fill: paint,
        stroke: paint,
        color,
        fontSize: num(L.fontSizeMin, L.fontSizeMax),
        font: oneOf(E.font),
        bold: bool,
        italic: bool,
        underline: bool,
        align: oneOf(E.align),
        strokeWidth: num(L.strokeWidthMin, L.strokeWidthMax),
        dash: oneOf(E.dash),
        opacity: num(0, 1),
    } satisfies Record<keyof DiagramNodeStyle, WireRule>,
    property: { key: propertyKey, value: str(L.propertyValueLength, true) },
    point: { x: num(0, 1), y: num(0, 1) },
    edge: {
        id: uuid,
        from: uuid,
        to: uuid,
        label: str(L.edgeLabelLength, true),
        layerId: uuid,
        route: oneOf(E.route),
        fromPort: oneOf(E.port),
        toPort: oneOf(E.port),
        arrow: oneOf(E.arrow),
        startArrow: oneOf(E.arrow),
        stroke: color,
        width: num(L.strokeWidthMin, L.strokeWidthMax),
        dash: oneOf(E.dash),
        bend: num(L.bendMin, L.bendMax),
        labelPos: num(0, 1),
    } satisfies Record<keyof DiagramEdgeV2, WireRule>,
    legacy: {
        id: uuid,
        title: name(160),
        nodes: arr('legacyNode', 0, 80),
        edges: arr('legacyEdge', 0, 160),
    } satisfies Record<keyof LegacyKnowledgeDiagram, WireRule>,
    legacyNode: {
        id: uuid,
        shape: oneOf(E.legacyShape),
        text: str(160),
        x: num(0, 840),
        y: num(0, 520),
    } satisfies Record<keyof LegacyDiagramNode, WireRule>,
    legacyEdge: { id: uuid, from: uuid, to: uuid, label: str(80, true) },
} satisfies Record<string, Record<string, WireRule>>;

export const CONTRACT_INVENTORY = {
    schema_version: 2,
    limits: L,
    enums: E,
    objects: WIRE_OBJECTS,
    everyFieldRequired: true,
    additionalProperties: false,
    nullTextIsEmpty: true,
    stringLength:
        'Unicode code points; well-formed Unicode; reject forbiddenTextPattern',
    forbiddenTextPattern: FORBIDDEN_TEXT_PATTERN,
    percentPattern: PERCENT_PATTERN,
    byteMeasurement:
        'UTF-8 compact JSON; unescaped Unicode, slashes and U+2028/U+2029; no insignificant whitespace',
    graphRules: [
        'V2 identity fields are unique ignoring ASCII case throughout a diagram, including the root.',
        'References compare UUIDs ignoring ASCII case; preserve the original stored spelling.',
        'Each node and edge layerId refers to a layer on the same page.',
        'Each edge connects two different existing nodes on the same page.',
        'Each parentId is null, a same-page group, or a same-page node of containerShape kind.',
        'The combined node/group parentId graph must be acyclic.',
        'Nodes, edges, groups and ink points are also capped in aggregate per diagram.',
        'Property keys are unique under ASCII case folding; the keys constructor and prototype are forbidden.',
        'dataGraphic is null or the exact key of a property whose non-null decimal value matches percentPattern and is at most 100.',
        'Ink nodes have 2..512 normalized points. Every other shape has zero points.',
        'Only image nodes have a non-null imageFileId. Every image node must have an imageFileId.',
        'Host validates imageFileId against ready PNG/JPEG files on the canonical article, referenced file_ids and current actor/revision scope.',
    ],
    excludedSource: [
        'CSS',
        'HTML',
        'SVG markup',
        'URL fields',
        'base64',
        'article metadata',
        'review states',
        'comments',
        'binary files',
    ],
} as const;
