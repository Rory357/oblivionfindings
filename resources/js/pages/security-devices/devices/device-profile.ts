import type { ControlRoomAlertAccess } from '@/components/security-devices/permission-destinations';

export type DeviceProfileSectionKey =
    | 'health'
    | 'monitors'
    | 'topology'
    | 'interfaces-sensors'
    | 'configuration'
    | 'management'
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
    access?: {
        state: 'available' | 'restricted';
        label: string;
    };
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
        id: number | null;
        reference: string | null;
        title: string | null;
        status: string | null;
        priority: string | null;
        workType: string | null;
        nextAction: string | null;
        updatedAt: string | null;
        href: string | null;
        access: {
            state: 'available' | 'restricted';
            label: string;
        };
    }>;
    controlRoomAlerts: Array<{
        id: number | null;
        reference: string | null;
        type: string | null;
        severity: string | null;
        status: string | null;
        triggeredAt: string | null;
        href: string | null;
        access: ControlRoomAlertAccess;
    }>;
    audit: Array<{
        id: number;
        action: string;
        actor: string | null;
        fields: string[];
        createdAt: string | null;
    }>;
    management: {
        visible: boolean;
        actions: Array<{
            key: string;
            label: string;
            domain: string;
            workspace: string;
            sensitivity: string;
            group: 'diagnostics' | 'standard_management' | 'high_risk_control';
            level: 'observe' | 'operate' | 'manage' | 'control' | 'admin';
            risk: 'low' | 'medium' | 'high' | 'critical';
            impact: string;
            expectedResult: string;
            confirmationMode:
                | 'none'
                | 'acknowledge_impact'
                | 'type_device_name';
            executionMode:
                | 'central_runtime'
                | 'collector_runtime'
                | 'unavailable';
            executionGuidance: string;
            allowed: boolean;
            adapterAvailable: boolean;
            available: boolean;
            state: string;
            reason: string;
            requiresStepUp: boolean;
            requiresMfa: boolean;
            requiresFreshObservation: boolean;
            freshness: {
                state: string;
                observedAt: string | null;
                staleAfterSeconds: number;
            };
            requiresApproval: boolean;
            requiresChange: boolean;
            allowsBreakGlass: boolean;
            expiresAfterSeconds: number;
            parameters: Array<{
                name: string;
                label: string;
                type: 'integer' | 'string' | 'date_time';
                min: number | null;
                max: number | null;
                options: string[];
                optionLabels?: Record<string, string>;
            }>;
        }>;
        history: Array<{
            id: number;
            uuid: string;
            capability: string;
            label: string;
            status: string;
            risk: string;
            confirmationMode:
                | 'none'
                | 'acknowledge_impact'
                | 'type_device_name'
                | null;
            impactAcknowledgedAt: string | null;
            reason: string;
            safeParameters: Record<string, string | number | boolean | null>;
            expectedState: Record<string, string | number | boolean | null>;
            requestedBy: string | null;
            approvedBy: string | null;
            isBreakGlass: boolean;
            breakGlass: {
                reviewer: string | null;
                emergencyReason: string | null;
                declaredAt: string | null;
                notificationSentAt: string | null;
                reviewDueAt: string | null;
                reviewedBy: string | null;
                reviewedAt: string | null;
                outcome: string | null;
                reviewSummary: string | null;
                overdue: boolean;
            } | null;
            requestedAt: string | null;
            expiresAt: string | null;
            reconciledAt: string | null;
            safeFailureReason: string | null;
            blockedReasonCode: string | null;
            blockedAt: string | null;
            change: {
                id: number;
                reference: string;
                title: string;
            } | null;
            nextAction: string;
            evidenceExportHref: string;
            executionRoute: string | null;
            latestAttempt: {
                number: number;
                status: string;
                runtime: string;
                safeResult: Record<string, unknown>;
                safeFailureReason: string | null;
                completedAt: string | null;
            } | null;
            latestReconciliation: {
                outcome: string;
                observedState: Record<string, unknown>;
                safeEvidenceSummary: string | null;
                observedAt: string | null;
            } | null;
            canDecide: boolean;
            canDispatch: boolean;
            dispatchPreconditionsCurrent: boolean;
            canReviewBreakGlass: boolean;
        }>;
        canObserve: boolean;
        canApprove: boolean;
        stepUpCurrent: boolean;
        changeOptions: Array<{
            id: number;
            reference: string;
            title: string;
            workflowState: string;
            maintenanceEndsAt: string | null;
        }>;
        breakGlassReviewers: Array<{
            id: number;
            name: string;
        }>;
        summary: {
            declared: number;
            available: number;
            awaitingApproval: number;
            uncertain: number;
            blocked: number;
            breakGlassReviewDue: number;
        };
    };
    capabilities: {
        registry: Capability;
        assignment: Capability;
        monitoring: Capability;
        maintenance: Capability;
        documents: Capability;
        control: Capability;
    };
};
