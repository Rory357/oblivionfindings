/** Proposed production wire contract. Isolated here until the Knowledge source freeze is released. */
export const DIAGRAM_SCHEMA_VERSION = 2 as const;

export const DIAGRAM_LIMITS = {
    diagrams: 12,
    pages: 12,
    layersPerPage: 16,
    nodesPerDiagram: 80,
    edgesPerDiagram: 160,
    groupsPerDiagram: 80,
    pointsPerNode: 512,
    pointsPerDiagram: 4096,
    propertiesPerNode: 16,
    diagramBytes: 262144,
    collectionBytes: 2097152,
    titleLength: 160,
    pageNameLength: 160,
    subtitleLength: 240,
    layerNameLength: 80,
    groupNameLength: 80,
    nodeTextLength: 2000,
    edgeLabelLength: 80,
    propertyKeyLength: 64,
    propertyValueLength: 512,
    scaleLength: 24,
    pageDimensionMin: 100,
    pageDimensionMax: 10000,
    positionMin: -10000,
    positionMax: 10000,
    nodeDimensionMin: 1,
    nodeDimensionMax: 10000,
    rotationMin: -360,
    rotationMax: 360,
    fontSizeMin: 6,
    fontSizeMax: 144,
    strokeWidthMin: 0,
    strokeWidthMax: 20,
    gridSizeMin: 1,
    gridSizeMax: 200,
    bendMin: -10000,
    bendMax: 10000,
} as const;

export const DIAGRAM_ENUMS = {
    legacyShape: ['rectangle', 'ellipse', 'diamond'],
    shape: [
        'rectangle',
        'ellipse',
        'diamond',
        'process',
        'decision',
        'terminator',
        'document',
        'data',
        'database',
        'subprocess',
        'manual',
        'delay',
        'offpage',
        'note',
        'text',
        'lane',
        'container',
        'phase',
        'list',
        'event',
        'intermediate',
        'end',
        'task',
        'gateway',
        'parallel',
        'pool',
        'class',
        'interface',
        'actor',
        'usecase',
        'component',
        'package',
        'lifeline',
        'state',
        'entity',
        'server',
        'router',
        'switch',
        'firewall',
        'cloud',
        'laptop',
        'wifi',
        'rack',
        'person',
        'team',
        'position',
        'room',
        'wall',
        'door',
        'window',
        'desk',
        'chair',
        'stairs',
        'exit',
        'tree',
        'dimension',
        'resistor',
        'ground',
        'pump',
        'valve',
        'tank',
        'motor',
        'duct',
        'milestone',
        'taskbar',
        'funnel',
        'browser',
        'phone',
        'ink',
        'image',
    ],
    containerShape: ['container', 'lane', 'pool', 'list'],
    route: ['straight', 'orthogonal', 'curved'],
    port: ['auto', 'top', 'right', 'bottom', 'left'],
    arrow: ['none', 'triangle', 'open', 'diamond', 'circle'],
    dash: ['solid', 'dashed', 'dotted'],
    font: ['sans', 'serif', 'mono'],
    align: ['left', 'center', 'right'],
    paper: ['a3', 'a4', 'a5', 'letter', 'legal', 'custom'],
    orientation: ['landscape', 'portrait'],
    imageMime: ['image/png', 'image/jpeg'],
} as const;

export type DiagramShapeKind = (typeof DIAGRAM_ENUMS.shape)[number];
export type DiagramUUID = string; // Runtime: canonical 8-4-4-4-12 UUID, case-insensitive identity.
export type DiagramColor = string; // Runtime: exactly #RRGGBB, ASCII hex (either case).
export type DiagramPaint = DiagramColor | 'none';
export type DiagramText = string | null; // null is the empty wire value; compatible with Laravel middleware.

/** Existing unversioned Knowledge source, preserved on read. No V2 fields may be added to this branch. */
export type LegacyDiagramNode = {
    id: DiagramUUID;
    shape: (typeof DIAGRAM_ENUMS.legacyShape)[number];
    text: string;
    x: number;
    y: number;
};
export type LegacyKnowledgeDiagram = {
    id: DiagramUUID;
    title: string;
    nodes: LegacyDiagramNode[];
    edges: {
        id: DiagramUUID;
        from: DiagramUUID;
        to: DiagramUUID;
        label: DiagramText;
    }[];
};

export type DiagramNodeStyle = {
    fill: DiagramPaint;
    stroke: DiagramPaint;
    color: DiagramColor;
    fontSize: number;
    font: (typeof DIAGRAM_ENUMS.font)[number];
    bold: boolean;
    italic: boolean;
    underline: boolean;
    align: (typeof DIAGRAM_ENUMS.align)[number];
    strokeWidth: number;
    dash: (typeof DIAGRAM_ENUMS.dash)[number];
    opacity: number;
};

/** Ordered, bounded plain-text properties. Never spread these into objects or interpret as code/URLs. */
export type DiagramProperty = { key: string; value: DiagramText };
export type DiagramPoint = { x: number; y: number }; // Ink-local normalized coordinates, each 0..1.
export type DiagramNodeV2 = {
    id: DiagramUUID;
    type: DiagramShapeKind;
    text: DiagramText;
    x: number;
    y: number;
    w: number;
    h: number;
    rotation: number;
    layerId: DiagramUUID;
    parentId: DiagramUUID | null; // A group or supported container node on this page.
    locked: boolean;
    style: DiagramNodeStyle;
    properties: DiagramProperty[];
    dataGraphic: string | null; // Exact property key; value must be a decimal percentage in 0..100.
    points: DiagramPoint[]; // 2..512 for ink; exactly [] for all other kinds.
    imageFileId: number | null; // Positive safe integer for image; null for all other kinds. Host-authorized raster only.
};

export type DiagramEdgeV2 = {
    id: DiagramUUID;
    from: DiagramUUID;
    to: DiagramUUID;
    label: DiagramText;
    layerId: DiagramUUID;
    route: (typeof DIAGRAM_ENUMS.route)[number];
    fromPort: (typeof DIAGRAM_ENUMS.port)[number];
    toPort: (typeof DIAGRAM_ENUMS.port)[number];
    arrow: (typeof DIAGRAM_ENUMS.arrow)[number];
    startArrow: (typeof DIAGRAM_ENUMS.arrow)[number];
    stroke: DiagramColor;
    width: number;
    dash: (typeof DIAGRAM_ENUMS.dash)[number];
    bend: number;
    labelPos: number;
};

/** Membership is represented only by parentId: one acyclic forest, without duplicate member lists. */
export type DiagramGroupV2 = {
    id: DiagramUUID;
    name: string;
    parentId: DiagramUUID | null;
};
export type DiagramLayerV2 = {
    id: DiagramUUID;
    name: string;
    visible: boolean;
    locked: boolean;
    print: boolean;
};
export type DiagramPageV2 = {
    id: DiagramUUID;
    name: string;
    subtitle: DiagramText;
    width: number;
    height: number;
    orientation: (typeof DIAGRAM_ENUMS.orientation)[number];
    paper: (typeof DIAGRAM_ENUMS.paper)[number];
    scale: string; // Descriptive ratio only: positive ASCII integers, e.g. 1:100. Not site calibration.
    background: DiagramColor;
    grid: { enabled: boolean; size: number; snap: boolean };
    layers: DiagramLayerV2[];
    groups: DiagramGroupV2[];
    nodes: DiagramNodeV2[];
    edges: DiagramEdgeV2[];
};

/** Exact allowed root fields. Article ID, roles, sites, status, versions, comments and files are host-owned. */
export type KnowledgeDiagramV2 = {
    schema_version: typeof DIAGRAM_SCHEMA_VERSION;
    id: DiagramUUID;
    title: string;
    pages: DiagramPageV2[];
};
export type KnowledgeDiagramSource =
    | LegacyKnowledgeDiagram
    | KnowledgeDiagramV2;

/** Runtime-only capabilities; never accepted from saved diagram source as authorization. */
export type DiagramStudioCapabilities = {
    editable: boolean;
    allowedShapes: readonly DiagramShapeKind[];
    pages: boolean;
    importJson: boolean;
    exportJson: boolean;
    exportSvg: boolean;
    exportRaster: boolean;
    importImage: boolean; // User-approved; enable only when the host's authorized raster upload/resolution is available.
};
