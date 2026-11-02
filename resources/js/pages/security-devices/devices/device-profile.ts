import type { CctvNavigation } from '@/components/security-devices/cctv-navigation-actions';
import type { NativeMonitorManagement } from '@/components/security-devices/native-monitor-dialogs';
import type { ControlRoomAlertAccess } from '@/components/security-devices/permission-destinations';
import type { SshHostKeyState } from '@/components/security-devices/ssh-host-key-notice';
import type { RestartRequest } from '@/pages/it/remote-support/restart-request-state';
import type { SoftwareRequest } from '@/pages/it/remote-support/software-request-state';

export type DeviceProfileSectionKey =
    | 'health'
    | 'monitors'
    | 'topology'
    | 'interfaces-sensors'
    | 'configuration'
    | 'endpoint'
    | 'console'
    | 'management'
    | 'assignments'
    | 'tickets'
    | 'events'
    | 'maintenance'
    | 'history'
    | 'documents'
    | 'audit';

export type DeviceProfileLayout =
    | 'network'
    | 'camera'
    | 'alarm_panel'
    | 'door'
    | 'sensor'
    | 'tracker'
    | 'computer'
    | 'other';

export type DeviceGovernedQuickAction = {
    key: string;
    label: string;
    risk: 'low' | 'medium' | 'high' | 'critical' | string;
    available: boolean;
    allowed: boolean;
    adapterAvailable: boolean;
    state: string;
    reason: string;
    confirmationMode: 'none' | 'acknowledge_impact' | 'type_device_name' | string;
    requiresStepUp: boolean;
    requiresMfa: boolean;
    requiresApproval: boolean;
    impact: string;
    expectedResult: string;
};

export type DeviceNetworkNeighbourItem = {
    id: number;
    name: string;
    category: string | null;
    subcategory: string | null;
    ip_address: string | null;
    status: string;
    port: string | null;
    protocol: string;
    href: string;
};

export type DeviceTypeProfile =
    | {
          kind: 'network';
          neighbours: {
              upstream: DeviceNetworkNeighbourItem[];
              downstream: DeviceNetworkNeighbourItem[];
          };
          interfaceCount: number;
          configState: string;
          latestSnapshotAt: string | null;
          firmwareVersion: string | null;
          ipAddress: string | null;
          macAddress: string | null;
      }
    | {
          kind: 'camera';
          isRecorder: boolean;
          streamState: 'verified' | 'configured_unverified' | 'not_configured';
          sourceProvider: string | null;
          streamVerifiedAt: string | null;
          liveHref: string | null;
          setupHref: string | null;
          recordingReferencesHref: string | null;
          canViewMedia: boolean;
          hasIpAddress: boolean;
          snapshotUrl: string | null;
          ptzSupported: boolean;
          canOperatePtz: boolean;
          ptzUrl: string | null;
      }
    | {
          kind: 'alarm_panel';
          panelState: string;
          panelStateAt: string | null;
          zones: Array<{
              zone: string;
              label: string;
              state: string;
              observedAt: string | null;
          }>;
          commandStoreUrl: string;
          armAction: DeviceGovernedQuickAction | null;
          disarmAction: DeviceGovernedQuickAction | null;
      }
    | {
          kind: 'door';
          doorState: string;
          doorStateAt: string | null;
          recentEvents: Array<{
              id: number;
              eventType: string;
              severity: string;
              source: string | null;
              occurredAt: string | null;
          }>;
          commandStoreUrl: string;
          unlockAction: DeviceGovernedQuickAction | null;
          lockAction: DeviceGovernedQuickAction | null;
      }
    | {
          kind: 'sensor';
          batteryLevel: number | null;
          batteryUpdatedAt: string | null;
          batteryState: 'not_reported' | 'critical' | 'low' | 'good' | string;
          readings: Array<{
              id: string;
              label: string;
              value: number | null;
              unit: string | null;
              state: string | null;
              observedAt: string | null;
          }>;
          recentTriggers: Array<{
              id: number;
              eventType: string;
              severity: string;
              occurredAt: string | null;
          }>;
      }
    | {
          kind: 'tracker';
          position: QueclinkUnitConsoleData['last_position'];
          hasConsole: boolean;
          imeiMasked: string | null;
      }
    | {
          kind: 'computer';
          agentEnrolled: boolean;
          agentState: string;
          hostname: string | null;
          osVersion: string | null;
          scriptExecutionAvailable: boolean;
      }
    | {
          kind: 'other';
          domainLabel: string;
          categoryLabel: string;
          ipAddress: string | null;
          macAddress: string | null;
          serialNumber: string | null;
          firmwareVersion: string | null;
          notes: string | null;
      };

export type DeviceHistoryTimelineItem = {
    id: string;
    kind: 'assignment' | 'event' | 'command' | 'maintenance' | 'audit' | string;
    title: string;
    detail: string | null;
    severity: 'critical' | 'warning' | 'success' | 'info' | 'neutral' | string;
    actor: string | null;
    occurredAt: string;
};

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

export type DeviceMaintenanceWindowSummary = {
    id: number;
    name: string;
    scopeType: string;
    startsAt: string | null;
    endsAt: string | null;
};

export type DeviceMaintenanceNotice = {
    activeWindow: DeviceMaintenanceWindowSummary | null;
    upcomingWindow: DeviceMaintenanceWindowSummary | null;
};

export type DevicePortActions = {
    bounceHref: string | null;
    enableHref: string | null;
    disableHref: string | null;
};

export type DeviceConfigurationSnapshot = {
    id: number;
    sourceKind: string;
    source: string;
    capturedAt: string | null;
    contentHash: string;
    configurationHash: string | null;
    contentSize: number;
    mimeType: string;
    firmwareVersion: string | null;
    storageState: string;
    previousSnapshotId: number | null;
    diff: {
        added: string[];
        removed: string[];
        changed: string[];
        count: number;
        truncated: boolean;
    };
    downloadHref: string | null;
    compareHref?: string | null;
};

export type ServiceCommandProjection = {
    serviceName: string | null;
    serviceLabel: string | null;
    operation: string | null;
    consentMode: string | null;
    state: string;
    beforeState: string | null;
    afterState: string | null;
    reasonCode: string | null;
    readbackState: string | null;
    readbackObservedAt: string | null;
};

export type DeviceEndpointPosture = {
    agent: {
        uuid: string | null;
        state:
            | 'not_enrolled'
            | 'pending_enrolment'
            | 'enrolment_expired'
            | 'enrolled_awaiting_heartbeat'
            | 'fresh'
            | 'stale'
            | 'assignment_drift'
            | 'revoked'
            | string;
        platform: string;
        agentVersion: string | null;
        enrolledAt: string | null;
        enrolmentExpiresAt: string | null;
        revokedAt: string | null;
        lastSeenAt: string | null;
        observedAt: string | null;
        sequence: number;
        serviceActionsReady: boolean;
        restartActionsReady: boolean;
        softwareActionsReady: boolean;
        scriptExecutionAvailable: boolean;
        canRevoke: boolean;
        revokeHref: string | null;
    };
    diagnosticsRestricted: boolean;
    inventory: {
        hostname: string | null;
        osVersion: string | null;
        architecture: string | null;
        memoryTotalBytes: number | null;
        memoryAvailableBytes: number | null;
        uptimeSeconds: number | null;
        cpuPercent: number | null;
        disks: Array<{
            name: string;
            totalBytes: number;
            freeBytes: number;
            usedBytes: number;
            usedPercent: number | null;
        }>;
        services: Array<{
            name: string;
            label: string | null;
            state: string;
            approved: boolean;
            allowedOperations: string[];
            manageHref: string | null;
        }>;
        servicesTotal: number;
        servicesTruncated: boolean;
        servicesComplete: boolean;
        software: Array<{
            name: string;
            version: string | null;
            publisher: string | null;
        }>;
        softwareTotal: number;
        softwareTruncated: boolean;
        softwareComplete: boolean;
        probeErrors: string[];
    } | null;
    policies: {
        service: {
            available: boolean;
            version: number;
            enabled: boolean;
            consentMode: string;
            catalogueCount: number;
            canManage: boolean;
        };
        restart: {
            available: boolean;
            version: number;
            enabled: boolean;
            consentMode: string;
            minimumWarningSeconds: number;
            canManage: boolean;
        };
        software: {
            available: boolean;
            version: number;
            enabled: boolean;
            consentMode: string;
            canManage: boolean;
        };
        script: {
            available: boolean;
            unavailableReason: string;
            version: number;
            enabled: boolean;
            consentMode: string;
            canManage: boolean;
        };
    };
    intune: {
        integrationEnabled: boolean;
        canManageLink: boolean;
        linkHref: string | null;
        linked: boolean;
        managedDeviceId: string | null;
        syncStatus: string | null;
        syncedAt: string | null;
        lastAttemptAt: string | null;
        snapshot: {
            deviceName: string | null;
            operatingSystem: string | null;
            osVersion: string | null;
            complianceState: string | null;
            isEncrypted: boolean | null;
            managementAgent: string | null;
            managedDeviceOwnerType: string | null;
            manufacturer: string | null;
            model: string | null;
            serialNumber: string | null;
            enrolledDateTime: string | null;
            lastSyncDateTime: string | null;
        } | null;
    } | null;
    links: {
        remoteSupportHref: string | null;
        approvedSoftwareHref: string | null;
        agentDownloadsHref: string | null;
    };
};

export type DeviceProfile = {
    profileLayout?: DeviceProfileLayout;
    typeProfile?: DeviceTypeProfile;
    historyTimeline?: DeviceHistoryTimelineItem[];
    cctv?: CctvNavigation;
    header: {
        identity: {
            id: number;
            name: string;
            uid: string;
            /** The device type in words, e.g. "Card reader". */
            type: string;
            /** Stored category key; chooses the header icon only. */
            category?: string | null;
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
        maintenance?: DeviceMaintenanceNotice;
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
        collectorId?: number | null;
        collectorName?: string | null;
        runsFrom?: string;
        collector: {
            name: string;
            status: string;
            lastSeenAt: string | null;
        } | null;
        maintenance?: DeviceMaintenanceNotice;
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
        portActions?: DevicePortActions | null;
    }>;
    configuration: {
        canViewSnapshots?: boolean;
        backupActionHref?: string | null;
        /** Phase A, item A6: the trusted SSH key and any key waiting to be checked. */
        sshHostKey?: SshHostKeyState | null;
        latestSnapshot?: DeviceConfigurationSnapshot | null;
        snapshotHistory?: DeviceConfigurationSnapshot[];
        snapshotHistoryTruncated?: boolean;
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
        actorId?: number;
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
                | 'endpoint_runtime'
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
            protectedInterfaces?: Record<string, string | null>;
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
            service?: ServiceCommandProjection | null;
            restart?: RestartRequest | null;
            software?: SoftwareRequest | null;
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
    endpointPosture?: DeviceEndpointPosture | null;
    ping?: DevicePingSummary;
    offlineAlert?: DeviceOfflineAlertSummary;
    monitorManagement?: NativeMonitorManagement;
    queclinkConsole?: QueclinkUnitConsoleData | null;
    capabilities: {
        registry: Capability;
        assignment: Capability;
        monitoring: Capability;
        maintenance: Capability;
        documents: Capability;
        control: Capability;
    };
};

export type DevicePingSummary = {
    url: string;
    target: string | null;
    canPing: boolean;
    missingReason: string | null;
};

export type DeviceOfflineAlertSummary = {
    url: string;
    enabled: boolean;
    canToggle: boolean;
    monitorId: number | null;
    state: string | null;
    target: string | null;
    missingReason: string | null;
};

export type QueclinkConsoleCommandSpec = {
    key: string;
    label: string;
    description: string;
    at_preview: string;
    spec_ref: string;
    risky: boolean;
    enabled: boolean;
};

export type QueclinkUnitConsoleData = {
    fetch_url: string;
    command_url: string;
    protocol_spec: string;
    family: string;
    unit: {
        id: number;
        imei_masked: string;
        status: string;
        connection_state: string;
        model_hint: string | null;
        protocol_version: string | null;
        firmware_version: string | null;
        first_seen_at: string | null;
        last_seen_at: string | null;
        last_frame_at: string | null;
    } | null;
    last_position: {
        state: string;
        message: string;
        latitude: number | null;
        longitude: number | null;
        accuracy_m: number | null;
        speed_kph: number | null;
        heading_deg: number | null;
        altitude_m: number | null;
        battery_pct: number | null;
        occurred_at: string | null;
        address: string | null;
        map_url: string | null;
    };
    settings: {
        available: boolean;
        received_at: string | null;
        sections: string[];
        summary: {
            server: Record<string, unknown> | null;
            global: Record<string, unknown> | null;
            watchdog: Record<string, unknown> | null;
            non_movement: Record<string, unknown> | null;
            firmware: {
                model: string | null;
                firmware_version: string | null;
                protocol_version: string | null;
                configuration_version?: string | null;
            };
        };
    };
    raw_messages: Array<{
        id: number;
        direction: string;
        frame_type: string | null;
        command_word: string | null;
        parse_ok: boolean;
        parse_error: string | null;
        raw_frame: string;
        created_at: string | null;
    }>;
    recent_commands: Array<{
        id: number;
        command_word: string;
        serial_number: string;
        status: string;
        raw_command: string;
        ack_response: string | null;
        failed_reason: string | null;
        created_by: string | null;
        created_at: string | null;
        sent_at: string | null;
        acked_at: string | null;
        fulfilled_at: string | null;
        expires_at: string | null;
    }>;
    commands: {
        tracker_ready: boolean;
        unavailable_reason: string | null;
        can_send_safe: boolean;
        can_send_risky: boolean;
        supported: QueclinkConsoleCommandSpec[];
        sections: string[];
    };
};
