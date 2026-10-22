import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DeviceProfile } from './device-profile';
import {
    DeviceAssetRecords,
    DeviceAssignmentRecords,
    DeviceControlRoomRecords,
    DeviceDocumentHistoryRecords,
    DeviceDocumentRecords,
    DeviceEventRecords,
    DeviceMaintenanceRecords,
    DeviceRelationshipHistoryRecords,
    DeviceRelationshipRecords,
} from './device-profile-records';
import DeviceShow, { type Props } from './show';

const auth = vi.hoisted(() => ({
    user: { id: 7 },
    can: {
        securityDevices: {
            viewAny: true,
            devicesView: true,
            devicesUpdate: true,
            devicesDelete: true,
            devicesAssign: true,
            eventsView: true,
            maintenanceManage: true,
            commandsAdmin: false,
        },
    },
}));
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ props: { auth } }),
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: ReactNode;
        breadcrumbs: Array<{ title: string; href?: string }>;
    }) => (
        <>
            <nav aria-label="Breadcrumb">
                {breadcrumbs.map((item) =>
                    item.href ? (
                        <a key={item.title} href={item.href}>
                            {item.title}
                        </a>
                    ) : (
                        <span key={item.title}>{item.title}</span>
                    ),
                )}
            </nav>
            {children}
        </>
    ),
}));

const at = '2026-10-14T01:00:00Z';
const capability = {
    supported: true,
    allowed: true,
    available: true,
    state: 'available',
};
const profile: DeviceProfile = {
    header: {
        identity: {
            id: 9,
            name: 'Reception gateway',
            uid: 'GW-9',
            type: 'Network gateway',
            manufacturer: null,
            model: null,
        },
        location: {
            id: 12,
            type: 'site',
            name: 'Harbour Site',
            href: '/sites/12',
        },
        assignment: null,
        health: {
            state: 'unknown',
            label: 'Health not measured',
            deviceState: 'active',
            deviceStateLabel: 'Active',
        },
        freshness: {
            state: 'unknown',
            observedAt: null,
            staleAfterSeconds: 900,
        },
        providerObservation: {
            provider: 'manual',
            label: 'Registry observation',
            observedAt: null,
            source: 'registry',
        },
        requiredAction: {
            state: 'unknown',
            label: 'Health not measured',
            description: 'No monitor reading',
            section: 'assignments',
        },
    },
    sections: [
        { key: 'assignments', label: 'Assignments', group: 'operations' },
        { key: 'events', label: 'Events', group: 'operations' },
        { key: 'maintenance', label: 'Maintenance', group: 'operations' },
        { key: 'topology', label: 'Topology', group: 'technical' },
        { key: 'documents', label: 'Documents', group: 'records' },
    ],
    health: {
        state: 'unknown',
        deviceState: 'active',
        lastSeenAt: null,
        lastSignalAt: null,
        batteryLevel: null,
        batteryUpdatedAt: null,
        monitoring: null,
    },
    monitors: [],
    interfacesSensors: [],
    tickets: [],
    controlRoomAlerts: [],
    audit: [],
    configuration: {
        registry: {
            manufacturer: null,
            model: null,
            serialNumber: null,
            assetTag: null,
            ipAddress: null,
            macAddress: null,
            imei: null,
            commissionedAt: null,
            warrantyExpiresAt: null,
            nextServiceDue: null,
            expectedLifespanMonths: null,
            purchasePrice: null,
            notes: null,
            groups: [],
            createdBy: null,
            createdAt: null,
        },
        configuration: {
            state: 'unknown',
            observedHash: null,
            desiredHash: null,
            observedAt: null,
        },
        firmware: {
            state: 'unknown',
            currentVersion: null,
            desiredVersion: null,
            observedAt: null,
        },
    },
    management: {
        visible: false,
        actions: [],
        history: [],
        canObserve: false,
        canApprove: false,
        stepUpCurrent: false,
        changeOptions: [],
        breakGlassReviewers: [],
        summary: {
            declared: 0,
            available: 0,
            awaitingApproval: 0,
            uncertain: 0,
            blocked: 0,
            breakGlassReviewDue: 0,
        },
    },
    capabilities: {
        registry: capability,
        assignment: capability,
        monitoring: capability,
        maintenance: capability,
        documents: capability,
        control: capability,
    },
};
const assignment: Props['assignmentHistory'][number] = {
    id: 21,
    assignable_type: 'site',
    assignable_name: 'Harbour Site',
    assignment_type: 'loan',
    assigned_at: '2026-10-10T01:02:03Z',
    expected_return_at: '2026-10-12T01:02:03Z',
    released_at: null,
    assigned_by: 'Alex Registrar',
    released_by: null,
    is_active: true,
    is_overdue: true,
    notes: 'Retain the complete assignment note\nincluding its second line.',
};
const asset: Props['assetLinks'][number] = {
    id: 31,
    asset_id: 41,
    asset_name: 'Gateway asset',
    asset_tag: 'ASSET-41',
    href: '/fleet-assets/assets/41?tab=technology',
    access: { state: 'available', label: 'Asset technology available' },
    link_type: 'primary',
    linked_at: '2026-10-11T03:04:05Z',
    notes: 'Canonical asset evidence retained',
};
const relation: Props['relationships']['parents'][number] = {
    id: 51,
    device_id: 61,
    device_name: 'Upstream switch',
    type: 'connected_to',
    port: 'PoE-04',
};
const historical: Props['relationshipHistory']['parents'][number] = {
    ...relation,
    id: 52,
    created_at: '2026-10-01T04:05:06Z',
    created_by: 'Morgan Engineer',
    unlinked_at: '2026-10-13T06:07:08Z',
    unlinked_by: 'Taylor Reviewer',
    unlink_reason: 'Approved network path replacement',
};
const document: Props['documents'][number] = {
    id: 71,
    title: 'Gateway certificate',
    category: 'compliance_cert',
    version: '3.1',
    effective_date: '2026-09-01',
    expiry_date: '2026-10-10',
    original_name: 'certificate.pdf',
    mime_type: 'application/pdf',
    size_bytes: 2345,
    notes: 'Complete certificate notes',
    uploaded_at: '2026-10-01T07:08:09Z',
    download_url: '/security-devices/devices/9/documents/71',
};
const lifecycle: Props['documentHistory'][number] = {
    id: 72,
    title: 'Previous certificate',
    category: 'compliance_cert',
    version: '2.0',
    original_name: 'previous.pdf',
    size_bytes: 1024,
    uploaded_at: '2026-09-01T01:02:03Z',
    uploaded_by: 'Alex Registrar',
    state: 'removal_pending',
    status_label: 'Removal pending storage verification',
    needs_attention: true,
    storage_verified_at: '2026-09-01T02:03:04Z',
    integrity_sha256: 'a1'.repeat(32),
    removal_requested_at: '2026-10-12T03:04:05Z',
    removed_at: null,
    removed_by: null,
    removal_reason: 'Superseded by current verified certificate',
    storage_deleted_at: null,
};
const event: Props['recentEvents'][number] = {
    id: 81,
    event_type: 'link_observation',
    severity: 'critical',
    occurred_at: '2026-10-13T08:09:10Z',
    source: 'device observation',
};
const maintenance: Props['maintenanceRecords'][number] = {
    id: 91,
    type: 'scheduled_service',
    status: 'scheduled',
    description: 'Inspect gateway mounting',
    scheduled_for: '2026-10-13T09:10:11Z',
    completed_at: null,
};
const alert: DeviceProfile['controlRoomAlerts'][number] = {
    id: 101,
    reference: 'AL-101',
    type: 'device.link',
    severity: 'warning',
    status: 'open',
    triggeredAt: '2026-10-13T10:11:12Z',
    href: '/control-room/alerts/101',
    access: { state: 'available', label: 'Alert available' },
};
function props(
    section: DeviceProfile['sections'][number]['key'] = 'assignments',
): Props {
    return {
        actor_id: 7,
        as_of: at,
        device: { id: 9, name: 'Reception gateway', status: 'active' },
        profile: {
            ...profile,
            sections: [...profile.sections].sort((a, b) =>
                a.key === section ? -1 : b.key === section ? 1 : 0,
            ),
        },
        activeAssignment: null,
        assignmentHistory: [assignment],
        assignmentTargets: {
            sites: [{ id: 12, name: 'Harbour Site' }],
            rooms: [],
            staff: [],
            clients: [],
            vehicles: [],
        },
        assetLinks: [asset],
        availableAssets: [
            { id: 42, name: 'Spare gateway asset', asset_tag: 'ASSET-42' },
        ],
        linkTypes: [{ value: 'primary', label: 'Primary' }],
        relationshipTypes: [{ value: 'connected_to', label: 'Connected to' }],
        otherDevices: [
            {
                id: 61,
                name: 'Upstream switch',
                device_uid: 'SW-61',
                category: 'switch',
            },
        ],
        documents: [document],
        documentHistory: [lifecycle],
        documentCategories: [{ value: 'other', label: 'Other' }],
        recentEvents: [event],
        maintenanceRecords: [maintenance],
        relationships: { parents: [relation], children: [] },
        relationshipHistory: { parents: [historical], children: [] },
        can: {
            update: true,
            delete: true,
            assign: true,
            viewEvents: true,
            manageMaintenance: true,
        },
    };
}
beforeEach(() => {
    vi.restoreAllMocks();
    auth.user.id = 7;
    Object.assign(auth.can.securityDevices, {
        viewAny: true,
        devicesView: true,
        devicesUpdate: true,
        devicesDelete: true,
        devicesAssign: true,
        eventsView: true,
        maintenanceManage: true,
        commandsAdmin: false,
    });
    vi.spyOn(router, 'post').mockImplementation(() => undefined);
    vi.spyOn(router, 'delete').mockImplementation(() => undefined);
    vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    window.history.replaceState({}, '', '/security-devices/devices/9');
});
function context(element: HTMLElement) {
    fireEvent.contextMenu(element, { clientX: 20, clientY: 30 });
}
function openEvidence(name: string) {
    fireEvent.click(
        screen.getByRole('button', { name: `View evidence for ${name}` }),
    );
    return screen.getByRole('dialog', { name: `Evidence for ${name}` });
}
const readers: Array<{
    name: string;
    element: ReactElement;
    labels: string[];
    /** Identifiers and raw recorded values, inside the collapsed Technical details. */
    technical?: string[];
    values: string[];
    /** Developer values that must not appear anywhere in the reader. */
    absent?: string[];
}> = [
    {
        name: 'Harbour Site',
        element: <DeviceAssignmentRecords rows={[assignment]} view="table" />,
        labels: [
            'Target type',
            'Assigned to',
            'Assignment type',
            'Assigned at',
            'Expected return',
            'Released at',
            'Assigned by',
            'Released by',
            'Active assignment',
            'Return overdue',
            'Notes',
        ],
        technical: ['Assignment ID'],
        values: [
            'Alex Registrar',
            assignment.notes!,
            formatDateTime(assignment.assigned_at),
            formatDateTime(assignment.expected_return_at),
        ],
        absent: [assignment.assigned_at, assignment.expected_return_at!],
    },
    {
        name: 'Gateway asset',
        element: (
            <DeviceAssetRecords
                rows={[asset]}
                view="table"
                canUpdate
                unlinkingId={null}
                onUnlink={vi.fn()}
            />
        ),
        labels: [
            'Asset name',
            'Asset tag',
            'Asset destination',
            'Destination access',
            'Link type',
            'Linked at',
            'Notes',
        ],
        technical: ['Link ID', 'Asset ID'],
        values: [
            'ASSET-41',
            asset.notes!,
            formatDateTime(asset.linked_at),
            asset.access.label,
        ],
        absent: [asset.linked_at, 'available ·'],
    },
    {
        name: 'Upstream switch',
        element: (
            <DeviceRelationshipRecords
                rows={{ parents: [relation], children: [] }}
                view="table"
                canUpdate
                unlinkingId={null}
                onUnlink={vi.fn()}
            />
        ),
        labels: [
            'Related device name',
            'Relationship type',
            'Port',
            'Direction from this device',
        ],
        technical: ['Relationship ID', 'Related device ID'],
        values: ['PoE-04', 'Upstream', 'connected to'],
        absent: ['connected_to'],
    },
    {
        name: 'Upstream switch',
        element: (
            <DeviceRelationshipHistoryRecords
                rows={{ parents: [], children: [historical] }}
                view="table"
            />
        ),
        labels: [
            'Created at',
            'Created by',
            'Removed at',
            'Removed by',
            'Removal reason',
            'Port',
            'Direction from this device',
        ],
        technical: ['Relationship ID', 'Related device ID'],
        values: [
            'Downstream',
            'Morgan Engineer',
            'Taylor Reviewer',
            historical.unlink_reason,
            formatDateTime(historical.created_at),
        ],
        absent: [historical.created_at!],
    },
    {
        name: 'Gateway certificate',
        element: (
            <DeviceDocumentRecords
                rows={[document]}
                view="table"
                asOf={at}
                canUpdate
                deletingId={null}
                onRemove={vi.fn()}
            />
        ),
        labels: [
            'Title',
            'Category',
            'Version',
            'Effective date',
            'Expiry date',
            'Original file name',
            'File type',
            'File size in bytes',
            'Notes',
            'Uploaded at',
            'Private download',
        ],
        technical: ['Document ID'],
        values: [
            'application/pdf',
            '2345',
            document.notes!,
            '1 Sep 2026',
            '10 Oct 2026',
            formatDateTime(document.uploaded_at),
            'compliance cert',
        ],
        absent: [document.uploaded_at!, 'compliance_cert'],
    },
    {
        name: 'Previous certificate',
        element: (
            <DeviceDocumentHistoryRecords rows={[lifecycle]} view="table" />
        ),
        labels: [
            'Title',
            'Category',
            'Version',
            'Original file name',
            'File size in bytes',
            'Uploaded at',
            'Uploaded by',
            'Lifecycle status',
            'Storage needs attention',
            'Storage verified at',
            'Removal requested at',
            'Removed at',
            'Removed by',
            'Removal reason',
            'Storage deleted at',
        ],
        technical: [
            'Document ID',
            'Recorded lifecycle state',
            'File checksum (SHA-256)',
        ],
        values: [
            lifecycle.integrity_sha256!,
            formatDateTime(lifecycle.storage_verified_at),
            lifecycle.status_label,
            lifecycle.removal_reason!,
        ],
        absent: ['fingerprint', lifecycle.storage_verified_at!],
    },
    {
        name: 'link observation',
        element: <DeviceEventRecords rows={[event]} view="table" />,
        labels: ['Event type', 'Recorded severity', 'Occurred at', 'Source'],
        technical: ['Event ID'],
        values: [
            'link observation',
            'critical',
            formatDateTime(event.occurred_at),
            'device observation',
        ],
        absent: ['link_observation', event.occurred_at],
    },
    {
        name: 'scheduled service',
        element: (
            <DeviceMaintenanceRecords
                rows={[maintenance]}
                view="table"
                asOf={at}
                canManage
                onComplete={vi.fn()}
            />
        ),
        labels: [
            'Maintenance type',
            'Recorded status',
            'Description',
            'Scheduled for',
            'Completed at',
        ],
        technical: ['Maintenance ID'],
        values: [
            'scheduled service',
            'scheduled',
            maintenance.description,
            formatDateTime(maintenance.scheduled_for),
        ],
        absent: ['scheduled_service', maintenance.scheduled_for!],
    },
    {
        name: 'AL-101',
        element: <DeviceControlRoomRecords rows={[alert]} view="table" />,
        labels: [
            'Reference',
            'Alert type',
            'Recorded severity',
            'Recorded status',
            'Triggered at',
            'Control Room destination',
            'Destination access',
        ],
        technical: ['Alert ID'],
        values: [
            'device link',
            'warning',
            'open',
            formatDateTime(alert.triggeredAt),
            alert.access.label,
        ],
        absent: ['device.link', alert.triggeredAt!, 'available ·'],
    },
];
describe('Device profile shared records', () => {
    it.each(readers)(
        'keeps all retained facts in the $name reader',
        ({ element, name, labels, technical = [], values, absent = [] }) => {
            render(element);
            const reader = openEvidence(name);
            for (const field of labels)
                expect(within(reader).getByText(field)).toBeVisible();
            for (const field of technical) {
                const fact = within(reader).getByText(field);
                expect(fact).not.toBeVisible();
                expect(fact.closest('details')).toHaveTextContent(
                    'Technical details',
                );
            }
            for (const value of values)
                expect(reader.textContent).toContain(value);
            for (const value of absent)
                expect(reader.textContent).not.toContain(value);
            expect(
                within(reader).getAllByRole('button', { name: 'Close' })[0],
            ).toBeVisible();
        },
    );
    it('keeps full notes when a card reader opens from the keyboard', () => {
        render(<DeviceAssignmentRecords rows={[assignment]} view="cards" />);
        const card = screen.getByRole('article', {
            name: 'Assignment history: Harbour Site',
        }).firstElementChild as HTMLElement;
        card.focus();
        fireEvent.keyDown(card, { key: 'Enter' });
        const reader = screen.getByRole('dialog');
        expect(reader.textContent).toContain(assignment.notes);
        fireEvent.click(
            within(reader).getAllByRole('button', { name: 'Close' })[0],
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('uses the same governed unlink action from card context and table menus', () => {
        const onUnlink = vi.fn();
        const view = render(
            <DeviceAssetRecords
                rows={[asset]}
                view="cards"
                canUpdate
                unlinkingId={null}
                onUnlink={onUnlink}
            />,
        );
        context(
            screen.getByRole('article', {
                name: 'Linked assets: Gateway asset',
            }).firstElementChild as HTMLElement,
        );
        expect(
            screen.getByRole('menuitem', { name: 'View evidence' }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Unlink asset' }));
        expect(onUnlink).toHaveBeenLastCalledWith(asset);
        view.rerender(
            <DeviceAssetRecords
                rows={[asset]}
                view="table"
                canUpdate
                unlinkingId={null}
                onUnlink={onUnlink}
            />,
        );
        context(screen.getByRole('row', { name: /Gateway asset/ }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Unlink asset' }));
        expect(onUnlink).toHaveBeenCalledTimes(2);
    });
    it('retains the canonical asset destination only when returned access permits it', () => {
        const denied = {
            ...asset,
            href: '/forged-private-destination',
            access: {
                state: 'restricted' as const,
                label: 'Fleet & Assets technology access required',
            },
        };
        render(
            <DeviceAssetRecords
                rows={[denied]}
                view="table"
                canUpdate={false}
                unlinkingId={null}
                onUnlink={vi.fn()}
            />,
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByText(denied.access.label)).toBeVisible();
        const reader = openEvidence('Gateway asset');
        expect(reader.textContent).not.toContain('/forged-private-destination');
        expect(reader.textContent).toContain(denied.access.label);
        expect(reader.textContent).not.toContain('restricted ·');
    });
    it.each([
        // 2 pm NZ on the expiry day: the document is still current.
        ['2026-10-10T01:00:00Z', false],
        // 11:59 pm NZ on the expiry day.
        ['2026-10-10T10:59:00Z', false],
        // 12:30 am NZ the next day: the expiry date has now passed.
        ['2026-10-10T11:30:00Z', true],
    ])(
        'treats a date-only expiry as a New Zealand calendar day (read at %s)',
        (readAt, passed) => {
            render(
                <DeviceDocumentRecords
                    rows={[document]}
                    view="table"
                    asOf={readAt}
                    canUpdate={false}
                    deletingId={null}
                    onRemove={vi.fn()}
                />,
            );
            expect(screen.getByText('10 Oct 2026')).toBeVisible();
            if (passed)
                expect(
                    screen.getAllByText('Expiry date passed')[0],
                ).toBeVisible();
            else
                expect(
                    screen.queryByText('Expiry date passed'),
                ).not.toBeInTheDocument();
        },
    );
    it('withholds restricted Control Room details and preserves permitted alert destinations', () => {
        render(
            <DeviceControlRoomRecords
                rows={[
                    alert,
                    {
                        ...alert,
                        id: 102,
                        reference: 'PRIVATE-102',
                        href: '/control-room/alerts/private',
                        access: {
                            state: 'restricted',
                            label: 'Control Room access required',
                        },
                    },
                ]}
                view="table"
            />,
        );
        expect(screen.getByRole('link', { name: 'AL-101' })).toHaveAttribute(
            'href',
            '/control-room/alerts/101',
        );
        expect(screen.getByText('Control Room access required')).toBeVisible();
        expect(screen.queryByText('PRIVATE-102')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /private/i }),
        ).not.toBeInTheDocument();
    });
    it('does not expose update actions when current record permission denies them', () => {
        render(
            <DeviceDocumentRecords
                rows={[document]}
                view="table"
                asOf={at}
                canUpdate={false}
                deletingId={null}
                onRemove={vi.fn()}
            />,
        );
        context(screen.getByRole('row', { name: /Gateway certificate/ }));
        expect(
            screen.getByRole('menuitem', { name: 'Download document' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('menuitem', { name: 'Remove document' }),
        ).not.toBeInTheDocument();
    });
    it('keeps a pending removal visible but inert', () => {
        const onRemove = vi.fn();
        render(
            <DeviceRelationshipRecords
                rows={{ parents: [relation], children: [] }}
                view="table"
                canUpdate
                unlinkingId={relation.id}
                onUnlink={onRemove}
            />,
        );
        context(screen.getByRole('row', { name: /Upstream switch/ }));
        const blocked = screen.getByRole('menuitem', {
            name: 'Remove relationship',
        });
        expect(blocked).toHaveAttribute('aria-disabled', 'true');
        expect(blocked).toHaveAccessibleDescription(
            'Removal request in progress',
        );
        fireEvent.click(blocked);
        expect(onRemove).not.toHaveBeenCalled();
    });
    it('never offers maintenance completion for completed records or a read-only actor', () => {
        const onComplete = vi.fn();
        const view = render(
            <DeviceMaintenanceRecords
                rows={[
                    { ...maintenance, status: 'completed', completed_at: at },
                ]}
                view="table"
                asOf={at}
                canManage
                onComplete={onComplete}
            />,
        );
        context(screen.getByRole('row', { name: /scheduled service/ }));
        expect(
            screen.queryByRole('menuitem', { name: 'Complete maintenance' }),
        ).not.toBeInTheDocument();
        view.rerender(
            <DeviceMaintenanceRecords
                rows={[maintenance]}
                view="table"
                asOf={at}
                canManage={false}
                onComplete={onComplete}
            />,
        );
        context(screen.getByRole('row', { name: /scheduled service/ }));
        expect(
            screen.queryByRole('menuitem', { name: 'Complete maintenance' }),
        ).not.toBeInTheDocument();
    });
    it('shows honest empty loaded records without invented health or storage completion', () => {
        render(
            <>
                <DeviceAssignmentRecords rows={[]} view="cards" />
                <DeviceAssetRecords
                    rows={[]}
                    view="table"
                    canUpdate={false}
                    unlinkingId={null}
                    onUnlink={vi.fn()}
                />
                <DeviceRelationshipRecords
                    rows={{ parents: [], children: [] }}
                    view="cards"
                    canUpdate={false}
                    unlinkingId={null}
                    onUnlink={vi.fn()}
                />
                <DeviceRelationshipHistoryRecords
                    rows={{ parents: [], children: [] }}
                    view="cards"
                />
                <DeviceDocumentRecords
                    rows={[]}
                    view="cards"
                    asOf={at}
                    canUpdate={false}
                    deletingId={null}
                    onRemove={vi.fn()}
                />
                <DeviceDocumentHistoryRecords rows={[]} view="table" />
                <DeviceEventRecords rows={[]} view="cards" />
                <DeviceMaintenanceRecords
                    rows={[]}
                    view="cards"
                    asOf={at}
                    canManage={false}
                    onComplete={vi.fn()}
                />
            </>,
        );
        for (const title of [
            'No assignment history returned',
            'No linked assets returned',
            'No active device relationships returned',
            'No relationship history returned',
            'No active documents returned',
            'No document lifecycle history returned',
            'No recent events returned',
            'No maintenance records returned',
        ])
            expect(screen.getByText(title)).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /View evidence/ }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Healthy')).not.toBeInTheDocument();
    });
    it('clears an open reader when its returned records change', () => {
        const view = render(
            <DeviceDocumentHistoryRecords rows={[lifecycle]} view="table" />,
        );
        openEvidence('Previous certificate');
        view.rerender(<DeviceDocumentHistoryRecords rows={[]} view="table" />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText(lifecycle.integrity_sha256!),
        ).not.toBeInTheDocument();
    });
    it('formats observations with the shared NZ datetime and distinguishes storage-pending facts', () => {
        render(
            <DeviceDocumentHistoryRecords rows={[lifecycle]} view="table" />,
        );
        expect(
            screen.getByText(formatDateTime(lifecycle.uploaded_at!)),
        ).toBeVisible();
        const reader = openEvidence('Previous certificate');
        // The plain lifecycle wording is shown; the raw recorded state only
        // appears inside the collapsed Technical details.
        expect(within(reader).getByText(lifecycle.status_label)).toBeVisible();
        const rawState = within(reader).getByText('removal_pending');
        expect(rawState).not.toBeVisible();
        expect(rawState.closest('details')).not.toHaveAttribute('open');
        const deletion =
            within(reader).getByText('Storage deleted at').parentElement;
        expect(deletion).toHaveTextContent('Not recorded');
    });
});
describe('DeviceShow records integration and preserved canonical actions', () => {
    it('keeps the Home trail, grouped section search and header-controlled cards/table view', () => {
        render(<DeviceShow {...props()} />);
        expect(
            within(
                screen.getByRole('navigation', { name: 'Breadcrumb' }),
            ).getByRole('link', { name: 'Home' }),
        ).toHaveAttribute('href', '/dashboard');
        expect(
            screen.getByRole('radiogroup', { name: 'Device record view' }),
        ).toBeVisible();
        expect(
            screen.getByRole('article', {
                name: 'Linked assets: Gateway asset',
            }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(
            screen.getByRole('row', { name: /Gateway asset/ }),
        ).toBeVisible();
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Find a section' })[0],
        );
        expect(screen.getByRole('dialog')).toBeVisible();
    });
    it('keeps unlink behind the existing named confirmation and exact device link route', () => {
        render(<DeviceShow {...props()} />);
        context(
            screen.getByRole('article', {
                name: 'Linked assets: Gateway asset',
            }).firstElementChild as HTMLElement,
        );
        fireEvent.click(screen.getByRole('menuitem', { name: 'Unlink asset' }));
        expect(router.delete).not.toHaveBeenCalled();
        const confirm = screen.getByRole('alertdialog', {
            name: 'Unlink asset from device?',
        });
        fireEvent.click(
            within(confirm).getByRole('button', { name: 'Unlink asset' }),
        );
        expect(router.delete).toHaveBeenCalledWith(
            '/security-devices/devices/9/asset-links/31',
            expect.objectContaining({ preserveScroll: true }),
        );
    });
    it('preserves relationship removal reason and canonical owned endpoint', () => {
        render(<DeviceShow {...props('topology')} />);
        context(
            screen.getByRole('article', {
                name: 'Device relationships: Upstream switch',
            }).firstElementChild as HTMLElement,
        );
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'Remove relationship' }),
        );
        const dialog = screen.getByRole('dialog', {
            name: 'Remove device relationship?',
        });
        fireEvent.change(
            within(dialog).getByLabelText(
                'Reason for removing this relationship',
            ),
            { target: { value: '  Approved replacement  ' } },
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Remove relationship' }),
        );
        expect(router.delete).toHaveBeenCalledWith(
            '/security-devices/devices/9/relationships/51',
            expect.objectContaining({
                data: { reason: 'Approved replacement' },
                preserveScroll: true,
            }),
        );
    });
    it('preserves private document removal reason without claiming storage deletion', () => {
        render(<DeviceShow {...props('documents')} />);
        context(
            screen.getByRole('article', {
                name: 'Documents: Gateway certificate',
            }).firstElementChild as HTMLElement,
        );
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'Remove document' }),
        );
        const dialog = screen.getByRole('dialog', {
            name: 'Remove device document?',
        });
        expect(dialog.textContent).toContain('recoverable quarantine');
        fireEvent.change(
            within(dialog).getByLabelText('Reason for removing this document'),
            { target: { value: 'Superseded certificate' } },
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Remove document' }),
        );
        expect(router.delete).toHaveBeenCalledWith(
            '/security-devices/devices/9/documents/71',
            expect.objectContaining({
                data: { reason: 'Superseded certificate' },
                preserveScroll: true,
            }),
        );
        expect(
            screen.getByText('Removal pending storage verification'),
        ).toBeVisible();
    });
    it('confirms maintenance completion before sending the unchanged payload and route', () => {
        render(<DeviceShow {...props('maintenance')} />);
        context(
            screen.getByRole('article', {
                name: 'Maintenance records: scheduled service',
            }).firstElementChild as HTMLElement,
        );
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'Complete maintenance' }),
        );
        expect(router.post).not.toHaveBeenCalled();
        const confirm = screen.getByRole('alertdialog', {
            name: 'Mark maintenance as complete?',
        });
        expect(confirm).toHaveTextContent('Reception gateway');
        fireEvent.click(
            within(confirm).getByRole('button', {
                name: 'Complete maintenance',
            }),
        );
        expect(router.post).toHaveBeenCalledWith(
            '/security-devices/maintenance/91/complete',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(
            within(confirm).getByRole('button', { name: 'Completing…' }),
        ).toBeDisabled();
        expect(screen.getByText('scheduled')).toBeVisible();
    });
    it('cancels maintenance completion without sending anything', () => {
        render(<DeviceShow {...props('maintenance')} />);
        context(
            screen.getByRole('article', {
                name: 'Maintenance records: scheduled service',
            }).firstElementChild as HTMLElement,
        );
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'Complete maintenance' }),
        );
        fireEvent.click(
            within(
                screen.getByRole('alertdialog', {
                    name: 'Mark maintenance as complete?',
                }),
            ).getByRole('button', { name: 'Cancel' }),
        );
        expect(router.post).not.toHaveBeenCalled();
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });
    it('clears retained private readers on a paired actor-only partial replacement and uses full recovery', () => {
        const data = props('documents');
        const view = render(<DeviceShow {...data} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        openEvidence('Previous certificate');
        auth.user.id = 8;
        view.rerender(<DeviceShow {...data} actor_id={8} />);
        expect(
            screen.getByRole('heading', { name: 'Device access required' }),
        ).toBeVisible();
        expect(
            screen.queryByText('Gateway certificate'),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh current record' }),
        );
        expect(router.visit).toHaveBeenCalledWith(
            '/security-devices/devices/9',
            { preserveState: false, preserveScroll: true },
        );
    });
    it('clears open private document drafts on current permission loss', () => {
        const data = props('documents');
        const view = render(<DeviceShow {...data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Upload document' }),
        );
        const dialog = screen.getByRole('dialog', { name: 'Upload document' });
        fireEvent.change(
            within(dialog).getByPlaceholderText('e.g. UVC-G4 Install Manual'),
            { target: { value: 'PRIVATE DRAFT' } },
        );
        auth.can.securityDevices.devicesUpdate = false;
        view.rerender(<DeviceShow {...data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('PRIVATE DRAFT'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Device access required' }),
        ).toBeVisible();
    });
    it('preserves same-actor source ownership and withholds current-role actions', () => {
        const data = props();
        data.can.update = false;
        data.can.assign = false;
        const view = render(<DeviceShow {...data} />);
        context(
            screen.getByRole('article', {
                name: 'Linked assets: Gateway asset',
            }).firstElementChild as HTMLElement,
        );
        expect(
            screen.queryByRole('menuitem', { name: 'Unlink asset' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'View evidence' }),
        );
        fireEvent.click(
            within(screen.getByRole('dialog')).getAllByRole('button', {
                name: 'Close',
            })[0],
        );
        view.rerender(
            <DeviceShow
                {...data}
                as_of="2026-10-14T02:00:00Z"
                assetLinks={[{ ...asset, notes: 'Updated permitted note' }]}
            />,
        );
        expect(
            screen.getByRole('article', {
                name: 'Linked assets: Gateway asset',
            }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Assign' }),
        ).not.toBeInTheDocument();
    });
});

describe('Device record destinations and retained-context boundaries', () => {
    it('keeps the canonical private download visible in the complete document reader', () => {
        render(
            <DeviceDocumentRecords
                rows={[document]}
                view="table"
                asOf={at}
                canUpdate={false}
                deletingId={null}
                onRemove={vi.fn()}
            />,
        );
        const reader = openEvidence('Gateway certificate');
        expect(
            within(reader).getByRole('link', { name: document.download_url }),
        ).toHaveAttribute('href', document.download_url);
        expect(reader.textContent).toContain(document.original_name);
    });
    it('retains the same action from the shared kebab and right-click menu', () => {
        const onUnlink = vi.fn();
        render(
            <DeviceAssetRecords
                rows={[asset]}
                view="table"
                canUpdate
                unlinkingId={null}
                onUnlink={onUnlink}
            />,
        );
        const row = screen.getByRole('row', { name: /Gateway asset/ });
        fireEvent.pointerDown(
            within(row).getByRole('button', {
                name: 'Actions for Gateway asset',
            }),
            { button: 0, ctrlKey: false, pointerType: 'mouse' },
        );
        fireEvent.click(screen.getByRole('menuitem', { name: 'Unlink asset' }));
        expect(onUnlink).toHaveBeenCalledWith(asset);
        context(row);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Unlink asset' }));
        expect(onUnlink).toHaveBeenCalledTimes(2);
    });
    it('clears retained record readers when only the device identifier is partially replaced', () => {
        const data = props('documents');
        const view = render(<DeviceShow {...data} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        openEvidence('Gateway certificate');
        view.rerender(
            <DeviceShow {...data} device={{ ...data.device, id: 10 }} />,
        );
        expect(
            screen.getByRole('heading', { name: 'Device access required' }),
        ).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Gateway certificate'),
        ).not.toBeInTheDocument();
    });
    it('does not display supplied old event details without current event permission', () => {
        auth.can.securityDevices.eventsView = false;
        render(<DeviceShow {...props('events')} />);
        expect(screen.getByText('Device event access required')).toBeVisible();
        expect(screen.queryByText('link observation')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'View all events' }),
        ).not.toBeInTheDocument();
    });
});
