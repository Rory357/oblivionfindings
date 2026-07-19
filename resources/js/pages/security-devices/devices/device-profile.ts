export type DeviceProfileSectionKey =
    | 'health'
    | 'monitors'
    | 'topology'
    | 'interfaces-sensors'
    | 'configuration'
    | 'assignments'
    | 'tickets'
    | 'events'
    | 'maintenance'
    | 'documents'
    | 'audit';

export type DeviceProfileGroupKey =
    | 'status'
    | 'technical'
    | 'operations'
    | 'records';

export type DeviceProfileSection = {
    key: DeviceProfileSectionKey;
    label: string;
    group: DeviceProfileGroupKey;
    count?: number;
};

type Location = {
    id: number;
    type: string;
    name: string;
    href: string | null;
};

type Capability = {
    supported: boolean;
    allowed: boolean;
    available: boolean;
    state: string;
    reason?: string;
};

export type DeviceProfile = {
    header: {
        identity: {
            id: number;
            name: string;
            uid: string;
            type: string;
            manufacturer: string | null;
            model: string | null;
        };
        location: Location | null;
        assignment: {
            type: string;
            name: string | null;
            assignedAt: string | null;
            expectedReturnAt: string | null;
        } | null;
        health: {
            state: string;
            label: string;
            deviceState: string | null;
            deviceStateLabel: string | null;
        };
        freshness: {
            state: string;
            observedAt: string | null;
            staleAfterSeconds: number;
        };
        providerObservation: {
            provider: string;
            label: string;
            observedAt: string | null;
            source: string;
        };
        requiredAction: {
            state: string;
            label: string;
            description: string;
            section: DeviceProfileSectionKey;
        };
    };
    sections: DeviceProfileSection[];
    health: {
        state: string;
        deviceState: string | null;
        lastSeenAt: string | null;
        lastSignalAt: string | null;
        batteryLevel: number | null;
        batteryUpdatedAt: string | null;
        monitoring: {
            enabled: number;
            healthy: number;
            attention: number;
            uncertain: number;
        } | null;
    };
    monitors: Array<{
        id: number;
        name: string;
        kind: string | null;
        kindLabel: string;
        state: string | null;
        enabled: boolean;
        affectsAvailability: boolean;
        lastObservationAt: string | null;
        lastStateChangedAt: string | null;
        suppressedUntil: string | null;
        profile: {
            name: string;
            intervalSeconds: number;
            staleAfterSeconds: number;
        } | null;
        collector: {
            name: string;
            status: string;
            lastSeenAt: string | null;
        } | null;
    }>;
    interfacesSensors: Array<{
        monitorId: number;
        name: string;
        kind: string | null;
        state: string | null;
        value: number | null;
        unit: string | null;
        index: number | null;
        adminStatus: string | null;
        operationalStatus: string | null;
        speedBps: number | null;
        inBps: number | null;
        outBps: number | null;
        inUtilisation: number | null;
        outUtilisation: number | null;
        errors: number | null;
        discards: number | null;
        observedAt: string | null;
    }>;
    configuration: {
        registry: {
            manufacturer: string | null;
            model: string | null;
            serialNumber: string | null;
            assetTag: string | null;
            ipAddress: string | null;
            macAddress: string | null;
            imei: string | null;
            commissionedAt: string | null;
            warrantyExpiresAt: string | null;
            nextServiceDue: string | null;
            expectedLifespanMonths: number | null;
            purchasePrice: string | number | null;
            notes: string | null;
            groups: Array<{ id: number; name: string }>;
            createdBy: { id: number; name: string } | null;
            createdAt: string | null;
        };
        configuration: {
            state: string;
            observedHash: string | null;
            desiredHash: string | null;
            observedAt: string | null;
        };
        firmware: {
            state: string;
            currentVersion: string | null;
            desiredVersion: string | null;
            observedAt: string | null;
        };
    };
    tickets: Array<{
        id: number;
        reference: string;
        title: string;
        status: string;
        priority: string;
        workType: string;
        nextAction: string | null;
        updatedAt: string | null;
        href: string;
    }>;
    controlRoomAlerts: Array<{
        id: number;
        reference: string;
        type: string;
        severity: string;
        status: string;
        triggeredAt: string | null;
        href: string;
    }>;
    audit: Array<{
        id: number;
        action: string;
        actor: string | null;
        fields: string[];
        createdAt: string | null;
    }>;
    capabilities: {
        registry: Capability;
        assignment: Capability;
        monitoring: Capability;
        maintenance: Capability;
        documents: Capability;
        control: Capability;
    };
};
