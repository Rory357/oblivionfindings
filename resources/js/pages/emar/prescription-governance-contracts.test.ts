import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
    clientsAllowedForPrescriberOrder,
    readBackWitnessesForClient,
} from './_prescription-dialogs';

const readSource = (path: string) =>
    readFileSync(resolve(process.cwd(), path), 'utf8');

const controller = readSource('app/Http/Controllers/Emar/EmarController.php');
const governanceScope = readSource(
    'app/Services/Medication/MedicationGovernanceScopeService.php',
);
const routes = readSource('routes/emar.php');
const bootstrap = readSource('bootstrap/app.php');
const page = readSource('resources/js/pages/emar/Prescriptions.tsx');
const dialogs = readSource('resources/js/pages/emar/_prescription-dialogs.tsx');
const detailDialog = readSource(
    'resources/js/components/emar/prescriptions/order-detail-dialog.tsx',
);

describe('prescriber order governance contracts', () => {
    it('consumes exact page and row capabilities without viewer mutation fallbacks', () => {
        for (const capability of [
            'can_confirm',
            'can_countersign',
            'can_dispense',
            'can_link',
            'can_cancel',
            'can_revoke',
        ]) {
            expect(controller).toContain(`'${capability}'`);
            expect(page).toContain(capability);
        }

        expect(controller).toContain("'manage_orders'");
        expect(controller).toContain("'verify_orders'");
        expect(controller).toContain("'can_create_manual_order'");
        expect(controller).toContain(
            "'can_create_manual_order' => $canCreateManualOrders",
        );
        expect(controller).toContain(
            "'can_classify_manual_orders' => $canClassifyManualOrders",
        );
        expect(controller).toContain("'can_create_covert'");
        expect(controller).toContain("'can_create_covert_authorisation'");
        expect(controller).toContain("'can_link_prescriber_order'");
        expect(page).toContain('can.manage_orders && canCreateManualOrder');
        expect(page).toContain('can.manage_orders && canCreateCovert');
        expect(page).toMatch(
            /can\.verify_orders\s*&&\s*order\.can_confirm\s*&&\s*order\.status === 'pending'/,
        );
        expect(page).toMatch(
            /can\.manage_orders\s*&&\s*can\.verify_orders\s*&&\s*order\.can_countersign/,
        );
        expect(page).toMatch(
            /can\.manage_orders\s*&&\s*order\.can_cancel\s*&&\s*order\.status === 'pending'/,
        );
        expect(page).not.toContain('can_classify_manual_orders');
        expect(dialogs).toContain('m.can_create_covert_authorisation');
        expect(dialogs).toContain('m.can_link_prescriber_order');
        expect(dialogs).toContain(
            'm.controlled_drug === order.controlled_drug_snapshot',
        );
    });

    it('matches covert authority by the canonical medication id only', () => {
        expect(controller).toContain("'client_medication_id'");
        expect(page).toContain(
            'c.client_medication_id === o.client_medication_id',
        );
        expect(page).not.toContain("(c.medication_name ?? '').toLowerCase()");
    });

    it('uses dedicated lifecycle routes and server-authoritative dispensing identity', () => {
        expect(routes).toContain("'/prescriptions/{order}/confirm'");
        expect(routes).toContain("'/prescriptions/{order}/countersign'");
        expect(routes).toContain("'/prescriptions/{order}/dispense'");
        expect(routes).toContain("'/prescriptions/{order}/cancel'");
        expect(routes).not.toContain("Route::delete('/prescriptions/{order}'");
        expect(page).toContain('`/emar/prescriptions/${o.id}/confirm`');
        expect(page).not.toContain("{ status: 'confirmed' }");
        expect(dialogs).toContain(
            'form.post(`/emar/prescriptions/${order.id}/dispense`',
        );
        expect(dialogs).toContain(
            'form.post(`/emar/prescriptions/${order.id}/cancel`',
        );
        expect(dialogs).not.toContain("status: 'dispensed'");
        expect(dialogs).not.toContain('dispensed_by');
        expect(dialogs).toContain("const form = useForm({ reason: '' });");
    });

    it('requires an explicit reason in a dedicated covert revoke dialog', () => {
        expect(page).toContain(
            "setModal({ type: 'revoke', authorisation: c })",
        );
        expect(page).toContain('<RevokeCovertDialog');
        expect(page).not.toContain(
            '`/emar/prescriptions/covert/${c.id}/revoke`',
        );
        expect(dialogs).toContain('export function RevokeCovertDialog');
        expect(dialogs).toContain(
            'form.transform((data) => ({ reason: data.reason.trim() }))',
        );
        expect(dialogs).toContain(
            '`/emar/prescriptions/covert/${authorisation.id}/revoke`',
        );
        expect(dialogs).toContain("form.reset('reason')");
        expect(dialogs).toContain('maxLength={500}');
        expect(dialogs).toContain(
            'disabled={!form.data.reason.trim() || form.processing}',
        );
        expect(controller).toContain("'reason' => 'required|string|max:500'");
        expect(controller).toContain("'reason' => trim($validated['reason'])");
    });

    it('posts a countersign declaration without collecting prescriber secrets', () => {
        expect(dialogs).toContain('prescriber_declaration: true');
        expect(dialogs).not.toMatch(/prescriber_(password|pin|credential)/);
        expect(dialogs).toContain('candidate.id !== currentUserId');
        expect(dialogs).toContain('!!form.data.read_back_witnessed_by');
    });

    it('uses a one-shot current-Site read-back witness credential', () => {
        expect(dialogs).toContain("read_back_witness_credential: ''");
        expect(dialogs).toContain('type="password"');
        expect(dialogs).toContain('autoComplete="off"');
        expect(dialogs).toContain('!!form.data.read_back_witness_credential');
        expect(dialogs).toContain(
            'VERBAL.includes(payload.order_type)\n                    ? { read_back_witness_credential }',
        );
        expect(dialogs).toContain('clearReadBackWitnessCredential');
        expect(dialogs).toContain(
            "form.setData('read_back_witness_credential', '')",
        );
        expect(dialogs).toContain('onBefore: clearReadBackWitnessCredential');
        expect(dialogs).toContain('onFinish: clearReadBackWitnessCredential');
        expect(dialogs).not.toMatch(/localStorage|sessionStorage|indexedDB/);

        expect(controller).toContain("'read_back_witness_credential'");
        expect(controller).toContain('Hash::check(');
        expect(controller).toContain(
            "unset($validated['read_back_witness_credential'])",
        );
        expect(controller).toContain(
            "'read_back_witness_method' => $readBackVerificationMethod",
        );
        expect(controller).toContain(
            'MedicationPrescriberOrder::READ_BACK_VERIFICATION_METHOD_PASSWORD',
        );
        expect(controller).toContain("'read_back_witnessed_at'");
        expect(bootstrap).toContain("'read_back_witness_credential'");
    });

    it('offers only non-self witnesses assigned to the selected client Site', () => {
        const clients = [
            {
                id: 101,
                first_name: 'Aroha',
                last_name: 'Ngata',
                site_id: 10,
                can_create_prescriber_order: true,
            },
            {
                id: 202,
                first_name: 'Wiremu',
                last_name: 'Rangi',
                site_id: 20,
                can_create_prescriber_order: false,
            },
        ];
        const staff = [
            { id: 1, name: 'Site ten worker', site_ids: [10] },
            { id: 2, name: 'Site twenty worker', site_ids: [20] },
            { id: 3, name: 'Multi-Site worker', site_ids: [10, 20] },
            { id: 4, name: 'Current worker', site_ids: [10, 20] },
            { id: 5, name: 'Unassigned worker', site_ids: [] },
        ];

        expect(
            readBackWitnessesForClient(clients, staff, '101', 4).map(
                (worker) => worker.id,
            ),
        ).toEqual([1, 3]);
        expect(
            readBackWitnessesForClient(clients, staff, '202', 4).map(
                (worker) => worker.id,
            ),
        ).toEqual([2, 3]);
        expect(readBackWitnessesForClient(clients, staff, '', 4)).toEqual([]);

        expect(dialogs).toContain('site_ids: number[]');
        expect(dialogs).toContain(
            'candidate.site_ids.includes(client.site_id)',
        );
        expect(controller).toContain("'site_id' => (int) $c->site_id");
        expect(controller).toContain('prescriptionWitnessStaffPicker(');
        expect(governanceScope).toContain("'site_ids' => $assignedSiteIds");
    });

    it('offers manual-order clients only on an exact server allow flag', () => {
        const clients = [
            {
                id: 101,
                first_name: 'Allowed',
                last_name: 'Client',
                site_id: 10,
                can_create_prescriber_order: true,
            },
            {
                id: 202,
                first_name: 'Denied',
                last_name: 'Client',
                site_id: 10,
                can_create_prescriber_order: false,
            },
            {
                id: 303,
                first_name: 'Legacy',
                last_name: 'Missing flag',
                site_id: 10,
            },
        ] as Parameters<typeof clientsAllowedForPrescriberOrder>[0];

        expect(
            clientsAllowedForPrescriberOrder(clients).map(
                (client) => client.id,
            ),
        ).toEqual([101]);
        expect(dialogs).toContain('can_create_prescriber_order: boolean');
        expect(page).toContain(
            'clients={clientsAllowedForPrescriberOrder(clients)}',
        );
        expect(page).toContain('<CovertDialog');
        expect(page).toContain('clients={clients}');
        expect(controller).toContain("'can_create_prescriber_order'");
        expect(controller).toContain(
            'isset($workScopedClientIdSet[(int) $c->id])',
        );
    });

    it('never derives dispense eligibility from confirmed status alone', () => {
        expect(page).toContain('order.can_dispense');
        expect(page).toContain("order.order_type !== 'cease'");
        expect(page).toMatch(
            /order\.can_dispense\s*&&\s*order\.status === 'confirmed'\s*&&\s*order\.order_type !== 'cease'/,
        );
        expect(page).toMatch(
            /order\.can_link\s*&&\s*order\.status === 'pending'/,
        );
        expect(detailDialog).toContain('canCountersign && awaiting');
        expect(detailDialog).toContain(
            "canDispense && order.status === 'confirmed'",
        );
        expect(detailDialog).toContain("canLink && order.status === 'pending'");
    });

    it('derives active lifecycle once on the server without erasing terminal outcomes', () => {
        expect(controller).toMatch(
            /\$isOpenLifecycleState = \$o->status === 'pending'\s*\|\| \(\$o->status === 'confirmed'\s*&& \$o->order_type !== 'cease'\s*&& \$o->dispensed_at === null\);/,
        );
        expect(controller).toContain(
            '$isOpenLifecycle = ! $isExpired && $isOpenLifecycleState;',
        );
        expect(controller).toContain(
            "'status' => $isExpired && $isOpenLifecycleState ? 'expired' : $o->status",
        );
        expect(controller).toContain("'is_open_lifecycle' => $isOpenLifecycle");
        expect(page).toContain('is_open_lifecycle: boolean;');
        expect(page).toContain(
            'active: orders.filter((o) => o.is_open_lifecycle).length',
        );
        expect(page).not.toContain(
            "['pending', 'confirmed'].includes(o.status)",
        );
    });

    it('keeps classification and covert overlap checks server bound', () => {
        expect(controller).toMatch(
            /\$controlledSnapshot\s*&&\s*\(! \$user->canDo\(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY\)\s*\|\|\s*! \$user->canDo\(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY\)\)/,
        );
        expect(controller).not.toContain(
            '($medication === null || $controlledSnapshot)',
        );
        expect(controller).toContain(
            "'client_medication_id' => 'nullable|integer|min:1'",
        );
        expect(controller).toContain(
            "$medication !== null && $validated['order_type'] === 'cease'",
        );
        expect(controller).toContain(
            "$validated['medication_name'] = $medication->name;",
        );
        expect(controller).toContain('assertCeaseOrderEffective(');
        expect(controller).toContain("'status' => 'prohibited'");
        expect(controller).toContain("->where('status', 'active')");
        expect(controller).toContain('->lockForUpdate()');
        expect(controller).toContain(
            "'authorised_date' => 'The authorisation date cannot be in the future.'",
        );
    });

    it('makes every governed transition audit failure fatal', () => {
        for (const action of [
            'medications.prescriber_order.created',
            'medications.prescriber_order.updated',
            'medications.prescriber_order.linked',
            'medications.prescriber_order.confirmed',
            'medications.prescriber_order.countersigned',
            'medications.prescriber_order.dispensed',
            'medications.prescriber_order.cancelled',
            'medications.covert_authorisation.created',
            'medications.covert_authorisation.revoked',
        ]) {
            expect(controller).toContain(`'${action}'`);
        }
        expect(controller).toContain('AuditLogger::logOrFail(');
    });
});
