import type { ActiveVisit, Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DeviceProfile } from './device-profile';
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
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
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

const assetChoice = {
    id: 42,
    name: 'Spare gateway asset',
    asset_tag: 'ASSET-42',
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
        assignmentHistory: [],
        assignmentTargets: {
            sites: [{ id: 12, name: 'Harbour Site' }],
            rooms: [{ id: 14, site_id: 12, name: 'Study room' }],
            staff: [{ id: 15, name: 'Alex Worker' }],
            clients: [{ id: 16, first_name: 'Kai', last_name: 'Care' }],
            vehicles: [
                { id: 17, name: 'Support van', registration_number: 'ZZ1998' },
            ],
        },
        assetLinks: [],
        availableAssets: [assetChoice],
        linkTypes: [
            { value: 'primary', label: 'Primary' },
            { value: 'installed_in', label: 'Installed in' },
        ],
        relationshipTypes: [],
        otherDevices: [],
        documents: [],
        documentHistory: [],
        documentCategories: [{ value: 'other', label: 'Other' }],
        recentEvents: [],
        maintenanceRecords: [],
        relationships: { parents: [], children: [] },
        relationshipHistory: { parents: [], children: [] },
        can: {
            update: true,
            delete: true,
            assign: true,
            viewEvents: true,
            manageMaintenance: true,
        },
    };
}
function page(data: Props, flash: Record<string, unknown>): Page {
    return {
        component: 'security-devices/devices/show',
        url: '/security-devices/devices/9',
        version: null,
        clearHistory: false,
        encryptHistory: false,
        rememberedState: {},
        flash: {},
        props: { ...data, errors: {}, auth, flash },
    };
}
function options() {
    const value = vi.mocked(router.post).mock.calls.at(-1)?.[2];
    if (!value) throw new Error('Expected an actual router post callback');
    return value;
}
function finishedVisit(): ActiveVisit {
    const call = vi.mocked(router.post).mock.calls.at(-1);
    if (!call || typeof call[0] !== 'string')
        throw new Error('Expected an owned POST visit');
    const ignore = () => {};
    return {
        url: new URL(call[0], window.location.origin),
        data: call[1] ?? {},
        method: 'post',
        replace: false,
        preserveScroll: true,
        preserveState: true,
        only: [],
        except: [],
        headers: {},
        errorBag: '',
        forceFormData: false,
        queryStringArrayFormat: 'brackets',
        async: false,
        showProgress: true,
        prefetch: false,
        fresh: false,
        reset: [],
        preserveUrl: false,
        invalidateCacheTags: [],
        viewTransition: false,
        completed: true,
        cancelled: false,
        interrupted: false,
        onCancelToken: ignore,
        onBefore: ignore,
        onBeforeUpdate: ignore,
        onStart: ignore,
        onProgress: ignore,
        onFinish: ignore,
        onCancel: ignore,
        onSuccess: ignore,
        onError: ignore,
        onFlash: ignore,
        onPrefetched: ignore,
        onPrefetching: ignore,
    };
}
function respond(data: Props, flash: Record<string, unknown>) {
    const callbacks = options();
    act(() => {
        callbacks.onSuccess?.(page(data, flash));
        callbacks.onFinish?.(finishedVisit());
    });
}
async function choose(trigger: HTMLElement, name: string | RegExp) {
    fireEvent.keyDown(trigger, { key: 'Enter', code: 'Enter' });
    fireEvent.click(await screen.findByRole('option', { name }));
}
async function assetDraft() {
    fireEvent.click(screen.getByRole('button', { name: 'Link asset' }));
    const dialog = screen.getByRole('dialog', { name: 'Link asset' });
    await choose(
        within(dialog).getAllByRole('combobox')[0],
        /Spare gateway asset/,
    );
    await choose(within(dialog).getAllByRole('combobox')[1], 'Installed in');
    fireEvent.change(
        within(dialog).getByPlaceholderText(
            'e.g. Installed in dashboard console',
        ),
        { target: { value: 'Keep these operational notes' } },
    );
    return dialog;
}
async function assignmentDraft(type: string, target: string | RegExp) {
    fireEvent.click(screen.getAllByRole('button', { name: 'Assign' })[0]);
    const dialog = screen.getByRole('dialog', { name: 'Assign device' });
    if (type !== 'Site')
        await choose(within(dialog).getAllByRole('combobox')[0], type);
    await choose(within(dialog).getAllByRole('combobox')[1], target);
    fireEvent.change(
        within(dialog).getByPlaceholderText('Optional assignment notes...'),
        { target: { value: 'Retain this assignment context' } },
    );
    return dialog;
}
beforeEach(() => {
    vi.restoreAllMocks();
    vi.spyOn(router, 'post').mockImplementation(() => undefined);
    vi.spyOn(router, 'delete').mockImplementation(() => undefined);
    vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    window.history.replaceState({}, '', '/security-devices/devices/9');
});

describe('Device profile asset-link outcomes and current choices', () => {
    it('keeps the valid canonical payload and clears the draft only after successful linking', async () => {
        const data = props();
        render(<DeviceShow {...data} />);
        const dialog = await assetDraft();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Link asset' }),
        );
        expect(router.post).toHaveBeenCalledWith(
            '/security-devices/devices/9/asset-links',
            {
                asset_id: 42,
                link_type: 'installed_in',
                notes: 'Keep these operational notes',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        respond(data, {
            success: 'Device linked to asset Spare gateway asset.',
        });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Link asset' }));
        const reopened = screen.getByRole('dialog', { name: 'Link asset' });
        expect(
            within(reopened).getByPlaceholderText(
                'e.g. Installed in dashboard console',
            ),
        ).toHaveValue('');
        expect(within(reopened).getAllByRole('combobox')[0]).toHaveTextContent(
            'Select an asset',
        );
        expect(within(reopened).getAllByRole('combobox')[1]).toHaveTextContent(
            'Primary',
        );
    });
    it('retains the asset, link type and notes when an operational flash.error arrives through onSuccess', async () => {
        const data = props();
        render(<DeviceShow {...data} />);
        const dialog = await assetDraft();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Link asset' }),
        );
        respond(data, {
            error: 'Device INTERNAL-IDENTIFIER is already actively linked to asset #42.',
        });
        expect(
            screen.getByRole('dialog', { name: 'Link asset' }),
        ).toBeVisible();
        expect(within(dialog).getByRole('alert')).toHaveTextContent(
            'The asset was not linked. Review the current asset choices and try again.',
        );
        expect(within(dialog).getByRole('alert')).not.toHaveTextContent(
            'INTERNAL-IDENTIFIER',
        );
        expect(within(dialog).getAllByRole('combobox')[0]).toHaveTextContent(
            'Spare gateway asset',
        );
        expect(within(dialog).getAllByRole('combobox')[1]).toHaveTextContent(
            'Installed in',
        );
        expect(
            within(dialog).getByPlaceholderText(
                'e.g. Installed in dashboard console',
            ),
        ).toHaveValue('Keep these operational notes');
        expect(router.post).toHaveBeenCalledTimes(1);
    });
    it('preserves validation errors and permits correction without losing the selected asset', async () => {
        const data = props();
        render(<DeviceShow {...data} />);
        const dialog = await assetDraft();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Link asset' }),
        );
        const callbacks = options();
        act(() => {
            callbacks.onError?.({ notes: 'Shorten the notes.' });
            callbacks.onFinish?.(finishedVisit());
        });
        expect(within(dialog).getByRole('alert')).toHaveTextContent(
            'Shorten the notes.',
        );
        expect(within(dialog).getAllByRole('combobox')[0]).toHaveTextContent(
            'Spare gateway asset',
        );
        fireEvent.change(
            within(dialog).getByPlaceholderText(
                'e.g. Installed in dashboard console',
            ),
            { target: { value: 'Short corrected note' } },
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Link asset' }),
        );
        expect(router.post).toHaveBeenCalledTimes(2);
        expect(vi.mocked(router.post).mock.calls[1][1]).toEqual({
            asset_id: 42,
            link_type: 'installed_in',
            notes: 'Short corrected note',
        });
        respond(data, {
            success: 'Device linked to asset Spare gateway asset.',
        });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it.each(['removed', 'already linked'] as const)(
        'blocks a selected asset that is now %s in the same current device projection',
        async (change) => {
            const data = props();
            const view = render(<DeviceShow {...data} />);
            const dialog = await assetDraft();
            const updated =
                change === 'removed'
                    ? { ...data, availableAssets: [] }
                    : {
                          ...data,
                          assetLinks: [
                              {
                                  id: 31,
                                  asset_id: 42,
                                  asset_name: assetChoice.name,
                                  asset_tag: assetChoice.asset_tag,
                                  href: null,
                                  access: {
                                      state: 'restricted' as const,
                                      label: 'Asset destination access required',
                                  },
                                  link_type: 'primary',
                                  linked_at: at,
                                  notes: null,
                              },
                          ],
                      };
            view.rerender(<DeviceShow {...updated} />);
            const submit = within(dialog).getByRole('button', {
                name: 'Link asset',
            });
            expect(submit).toBeDisabled();
            expect(within(dialog).getByRole('alert')).toHaveTextContent(
                'This asset is no longer available to link. Choose a current asset.',
            );
            fireEvent.click(submit);
            expect(router.post).not.toHaveBeenCalled();
            expect(
                within(dialog).getByPlaceholderText(
                    'e.g. Installed in dashboard console',
                ),
            ).toHaveValue('Keep these operational notes');
        },
    );
    it('keeps the draft recoverable when an eligible choice returns in a same-actor refresh', async () => {
        const data = props();
        const view = render(<DeviceShow {...data} />);
        const dialog = await assetDraft();
        view.rerender(<DeviceShow {...data} availableAssets={[]} />);
        expect(
            within(dialog).getByRole('button', { name: 'Link asset' }),
        ).toBeDisabled();
        view.rerender(<DeviceShow {...data} />);
        expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument();
        const submit = within(dialog).getByRole('button', {
            name: 'Link asset',
        });
        expect(submit).toBeEnabled();
        fireEvent.click(submit);
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({
            asset_id: 42,
            link_type: 'installed_in',
            notes: 'Keep these operational notes',
        });
    });
});

describe('Device profile current assignment targets', () => {
    it('keeps the canonical assignment payload and successful close unchanged', async () => {
        const data = props();
        render(<DeviceShow {...data} />);
        const dialog = await assignmentDraft('Site', 'Harbour Site');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Assign' }));
        expect(router.post).toHaveBeenCalledWith(
            '/security-devices/devices/9/assign',
            {
                assignable_type: 'site',
                assignable_id: '12',
                assignment_type: 'permanent',
                expected_return_at: null,
                notes: 'Retain this assignment context',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        respond(data, { success: 'Device assigned successfully.' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('retains the current target and notes after validation and allows a corrected retry', async () => {
        const data = props();
        render(<DeviceShow {...data} />);
        const dialog = await assignmentDraft('Site', 'Harbour Site');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Assign' }));
        const callbacks = options();
        act(() => {
            callbacks.onError?.({
                assignable_type: 'Review the assignment details.',
            });
            callbacks.onFinish?.(finishedVisit());
        });
        expect(within(dialog).getByRole('alert')).toHaveTextContent(
            'Review the assignment details.',
        );
        expect(
            within(dialog).getByPlaceholderText('Optional assignment notes...'),
        ).toHaveValue('Retain this assignment context');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Assign' }));
        expect(router.post).toHaveBeenCalledTimes(2);
        respond(data, { success: 'Device assigned successfully.' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it.each([
        { type: 'Site', target: 'Harbour Site', key: 'sites' as const },
        { type: 'Room', target: 'Study room', key: 'rooms' as const },
        { type: 'Staff', target: 'Alex Worker', key: 'staff' as const },
        { type: 'Client', target: 'Kai Care', key: 'clients' as const },
        {
            type: 'Vehicle',
            target: 'Support van (ZZ1998)',
            key: 'vehicles' as const,
        },
    ])(
        'blocks a $type target removed by the current same-actor choices',
        async ({ type, target, key }) => {
            const data = props();
            const view = render(<DeviceShow {...data} />);
            const dialog = await assignmentDraft(type, target);
            view.rerender(
                <DeviceShow
                    {...data}
                    assignmentTargets={{ ...data.assignmentTargets, [key]: [] }}
                />,
            );
            const submit = within(dialog).getByRole('button', {
                name: 'Assign',
            });
            expect(submit).toBeDisabled();
            expect(within(dialog).getByRole('alert')).toHaveTextContent(
                'This assignment target is no longer available. Choose a current target.',
            );
            fireEvent.click(submit);
            expect(router.post).not.toHaveBeenCalled();
            expect(
                within(dialog).getByPlaceholderText(
                    'Optional assignment notes...',
                ),
            ).toHaveValue('Retain this assignment context');
        },
    );
    it('allows a newly selected current Site while retaining the remaining assignment draft', async () => {
        const data = props();
        const view = render(<DeviceShow {...data} />);
        const dialog = await assignmentDraft('Site', 'Harbour Site');
        view.rerender(
            <DeviceShow
                {...data}
                assignmentTargets={{
                    ...data.assignmentTargets,
                    sites: [{ id: 18, name: 'Current Site' }],
                }}
            />,
        );
        expect(
            within(dialog).getByRole('button', { name: 'Assign' }),
        ).toBeDisabled();
        await choose(
            within(dialog).getAllByRole('combobox')[1],
            'Current Site',
        );
        expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Assign' }));
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({
            assignable_type: 'site',
            assignable_id: '18',
            assignment_type: 'permanent',
            expected_return_at: null,
            notes: 'Retain this assignment context',
        });
    });
});

describe('Unchanged accepted document lifecycle outcomes', () => {
    it('continues to accept a safely staged upload warning without claiming verified storage', () => {
        const data = props('documents');
        render(<DeviceShow {...data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Upload document' }),
        );
        const dialog = screen.getByRole('dialog', { name: 'Upload document' });
        fireEvent.change(
            within(dialog).getByPlaceholderText('e.g. UVC-G4 Install Manual'),
            { target: { value: 'Gateway manual' } },
        );
        const input =
            dialog.querySelector<HTMLInputElement>('input[type="file"]');
        if (!input) throw new Error('Expected the actual upload input');
        const file = new File(['retained draft'], 'gateway.pdf', {
            type: 'application/pdf',
        });
        fireEvent.change(input, { target: { files: [file] } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Upload' }));
        expect(vi.mocked(router.post).mock.calls[0][1]).toBeInstanceOf(
            FormData,
        );
        respond(data, {
            warning:
                'The document is safely staged but not yet available. Automatic storage recovery will retry it.',
        });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Document uploaded and verified'),
        ).not.toBeInTheDocument();
    });
});
