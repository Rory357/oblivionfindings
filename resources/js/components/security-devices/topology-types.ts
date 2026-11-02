export type TopologyFreshness = {
    state: 'current' | 'stale' | 'unknown';
    observedAt: string | null;
    staleAfterSeconds: number | null;
    basis?: string;
};
export type TopologyNode = {
    id: number;
    name: string;
    site: string | null;
    siteId?: number | null;
    href: string;
    exploreHref?: string | null;
    health: string | null;
    category?: string;
    subcategory?: string | null;
    currentState?: boolean;
    topologyEvidence?: 'retained_snapshot' | 'not_observed';
};
export type TopologyEdge = {
    type?: string | null;
    id: number | string;
    parentId: number;
    parentName: string;
    childId: number;
    childName: string;
    label: string;
    port: string | null;
    source?: string;
    confidence?: number;
    reviewState?: string;
    evidenceState?: string;
    evidenceLabel?: string;
    firstSeenAt?: string | null;
    lastSeenAt?: string | null;
    capturedAt?: string | null;
    snapshotId?: number;
    freshness?: TopologyFreshness;
};
export type TopologySnapshot = {
    id: number;
    site: { id: number; name: string; href: string } | null;
    source: string;
    capturedAt: string | null;
    nodeCount: number;
    edgeCount: number;
    changeCount: number;
    freshness?: TopologyFreshness;
};
export type TopologyHistoryEntry = TopologySnapshot & {
    nodes: TopologyNode[];
    edges: TopologyEdge[];
    healthBasis?: string;
    changes?: { added: number; removed: number; changed: number };
    comparison: {
        previousSnapshotId: number | null;
        state: 'compared' | 'baseline' | 'unavailable';
        addedEdges: TopologyEdge[];
        removedEdges: TopologyEdge[];
        changedEdges: { before: TopologyEdge; after: TopologyEdge }[];
    };
};
export type TopologyInventory = {
    pageKey: string;
    siteId: number | null;
    sites: { id: number; name: string }[];
    search: string;
    shown: number;
    limit: number;
    total: number;
    hasMore?: boolean;
    previousHref: string | null;
    nextHref: string | null;
    resetHref: string;
    positionReset: boolean;
    isPartial: boolean;
};
export type TopologyExploration = {
    anchorId: number;
    siteId: number;
    pageKey: string;
    neighborLimit: number;
    neighborsShown: number;
    neighborsTotal: number;
    previousHref: string | null;
    nextHref: string | null;
    refreshHref: string;
    inventoryHref: string;
    positionReset: boolean;
    isPartial: true;
    sourcesTruncated: boolean;
    edgesTruncated: boolean;
};
export type TopologyMapData = {
    exploration?: TopologyExploration;
    inventory?: TopologyInventory;
    visibleDeviceCount?: number;
    currentSnapshotLimit?: number;
    currentSnapshotsTruncated?: boolean;
    source?: string;
    nodes: TopologyNode[];
    edges: TopologyEdge[];
    snapshots?: TopologySnapshot[];
    changes?: { added: number; removed: number; changed: number };
    history?: {
        limit: number;
        truncated: boolean;
        entries: TopologyHistoryEntry[];
        pageKey?: string;
        isOlderPage?: boolean;
        previousHref?: string | null;
        nextHref?: string | null;
        latestHref?: string;
        positionReset?: boolean;
    };
};

/** The Site network tree (gateway → switches → access points → devices), from SiteTopologyMapService::buildSiteTree. */
export type SiteTopologyTreeNode = {
    id: number;
    device_uid: string;
    name: string;
    tier: 'gateway' | 'switch' | 'access_point' | 'device' | string;
    domain: string | null;
    category: string | null;
    subcategory: string | null;
    manufacturer: string | null;
    model: string | null;
    ip_address: string | null;
    mac_address: string | null;
    status: string;
    health: string;
    href: string;
    uplink: {
        protocol: string;
        label: string;
        port: string | null;
    } | null;
    children: SiteTopologyTreeNode[];
};

export type SiteTopologyTree = {
    tier_counts: {
        gateways: number;
        switches: number;
        access_points: number;
        devices: number;
    };
    sources: string[];
    last_captured_at: string | null;
    mapped_count: number;
    roots: SiteTopologyTreeNode[];
    unplaced: SiteTopologyTreeNode[];
};
