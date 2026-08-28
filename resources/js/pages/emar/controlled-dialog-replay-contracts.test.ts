import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = readFileSync(
    resolve(process.cwd(), 'resources/js/pages/emar/_cd-dialogs.tsx'),
    'utf8',
);

const preciseQuantitySources = [
    'resources/js/pages/meds/today/components/record-dose-wizard.tsx',
    'resources/js/pages/meds/today/components/prn-wizard.tsx',
    'resources/js/pages/emar/components/cd-register-modal.tsx',
    'resources/js/pages/emar/components/guided-round-dialog.tsx',
].map((path) => readFileSync(resolve(process.cwd(), path), 'utf8'));
const prnWizardSource = preciseQuantitySources[1];
const guidedRoundSource = preciseQuantitySources[3];
const shiftMedicationSource = readFileSync(
    resolve(
        process.cwd(),
        'resources/js/components/operations/shift-medication-card.tsx',
    ),
    'utf8',
);
const prnSheetSource = readFileSync(
    resolve(process.cwd(), 'resources/js/components/prn-sheet.tsx'),
    'utf8',
);
const stockDialogsSource = readFileSync(
    resolve(process.cwd(), 'resources/js/pages/emar/_stock-dialogs.tsx'),
    'utf8',
);
const clientAdministrationSources = [
    'resources/js/components/clients/profile/emar-dialog.tsx',
    'resources/js/pages/clients/medical.tsx',
    'resources/js/pages/operations/clients/medical.tsx',
].map((path) => readFileSync(resolve(process.cwd(), path), 'utf8'));

function dialogSource(start: string, end: string): string {
    return source.slice(source.indexOf(start), source.indexOf(end));
}

describe('controlled mutation dialog replay contracts', () => {
    const credentialDialogs = [
        dialogSource(
            'export function RecordCdEntryDialog',
            'export function BalanceCheckDialog',
        ),
        dialogSource(
            'export function BalanceCheckDialog',
            'export function ResolveDiscrepancyDialog',
        ),
    ];
    const lossDialog = dialogSource(
        'export function ReportLossDialog',
        'export function LossActionDialog',
    );

    it('closes offline dialogs only after persistence actually succeeds', () => {
        expect(source).toContain("if (result.status === 'queued') onClose();");
    });

    it('keeps CD entry and balance checks online for ephemeral witness verification', () => {
        for (const dialog of credentialDialogs) {
            expect(dialog).toContain("witness_credential: ''");
            expect(dialog).toContain('Witness password or PIN');
            expect(dialog).toContain(
                'client_medication_id: Number(medicationId)',
            );
            expect(dialog).toContain('form.transform(() => payload)');
            expect(dialog).toMatch(/onClose,\s+true,/);
        }
        expect(source).toContain('requiresConnection = false');
        expect(source).toContain(
            'Witness credentials are never saved on this device.',
        );
    });

    it.each(credentialDialogs)(
        'keeps exact witnessed retries stable and rotates after material edits',
        (dialog) => {
            expect(dialog).toContain(
                'useRef(createMedicationMutationReplayState())',
            );
            expect(dialog).toContain('prepareMedicationMutationReplayState(');
            expect(dialog).toContain('witness_credential: _witnessCredential');
            if (dialog.includes('BalanceCheckDialog')) {
                expect(dialog).toContain(
                    'client_request_uuid: balanceReplay.current.uuid',
                );
            } else {
                expect(dialog).toContain(
                    'client_request_uuid: entryReplay.current.uuid',
                );
            }
            expect(dialog).toContain('queueIfOffline(');
            expect(dialog).toContain('form.post(');
        },
    );

    it('uses the two-decimal stock contract across controlled quantity inputs', () => {
        expect(source).not.toContain('step="0.5"');
        expect(
            source.match(/step="0\.01"/g)?.length ?? 0,
        ).toBeGreaterThanOrEqual(7);
        expect(source).not.toContain('parseFloat(form.data.on_hand_after)');
        expect(source).toContain('medicationStockQuantitiesEqual(');
    });

    it('binds destruction online and offline submissions to one payload-aware replay UUID', () => {
        const dialog = dialogSource(
            'export function RecordDestructionDialog',
            'export function VoidDestructionDialog',
        );

        expect(dialog).toContain('const destructionReplay = useRef({');
        expect(dialog).toContain('uuid: initialDestructionRequestUuid,');
        expect(dialog).toContain(
            'const materialFingerprint = JSON.stringify({',
        );
        expect(dialog).toContain(
            'destructionReplay.current.fingerprint !== materialFingerprint',
        );
        expect(dialog).toContain('submitEmarMutation(');
        expect(dialog).toContain("'/emar/destructions'");
        expect(dialog).toContain('allowQueueWhenOffline: !isCd');
        expect(dialog).toContain('emarMutationWasAccepted(result.status)');
        expect(dialog).toContain(
            'client_request_uuid: destructionReplay.current.uuid,',
        );
        expect(dialog).toContain(
            'client_medication_id: form.data.medication_id',
        );
        expect(dialog).toContain('const resetReplayAndClose = () => {');
        expect(dialog).toContain('onClose={resetReplayAndClose}');
        expect(dialog).toContain('Witness credentials are never saved on this');
        expect(dialog).toContain('device. Reconnect before recording this');
        expect(dialog).not.toContain(
            'delete offlinePayload.witness_1_credential;',
        );
        expect(dialog).not.toContain(
            'delete offlinePayload.witness_2_credential;',
        );
        expect(dialog).toContain('payload,');
    });

    it('binds loss-report retries to a payload-aware UUID and routes every attempt through the queue helper', () => {
        expect(lossDialog).toContain('const lossReplay = useRef({');
        expect(lossDialog).toContain(
            'const materialFingerprint = JSON.stringify',
        );
        expect(lossDialog).toContain(
            'lossReplay.current.fingerprint !== materialFingerprint',
        );
        expect(lossDialog).toContain(
            'client_request_uuid: lossReplay.current.uuid',
        );
        expect(lossDialog).toContain(
            'client_medication_id: medicationId ? Number(medicationId) : null',
        );
        expect(lossDialog).toContain('submitEmarMutation(');
        expect(lossDialog).toContain("action: 'cd_loss_report'");
        expect(lossDialog).toContain('emarMutationWasAccepted(result.status)');
        expect(lossDialog).toContain('onClose={resetReplayAndClose}');
    });

    it('binds stock-receipt retries to a payload-aware UUID until accepted or reset', () => {
        const receiptDialog = stockDialogsSource.slice(
            stockDialogsSource.indexOf('export function ReceiveStockDialog'),
            stockDialogsSource.indexOf(
                'export function ControlledPharmacyDeliveryDialog',
            ),
        );

        expect(receiptDialog).toContain('const receiptReplay = useRef({');
        expect(receiptDialog).toContain(
            'const materialFingerprint = JSON.stringify(materialPayload);',
        );
        expect(receiptDialog).toContain(
            'receiptReplay.current.fingerprint !== materialFingerprint',
        );
        expect(receiptDialog).toContain(
            'client_request_uuid: receiptReplay.current.uuid',
        );
        expect(receiptDialog).toContain('submitEmarMutation(');
        expect(receiptDialog).toContain(
            'if (!emarMutationWasAccepted(result.status)) return;',
        );
        expect(receiptDialog).toContain('onClose={resetReplayAndClose}');
    });

    it('accepts hundredth-unit controlled quantities and balances on every recording surface', () => {
        for (const quantitySource of preciseQuantitySources) {
            expect(quantitySource).not.toMatch(/(?:min|step)=[{"]0\.25/);
            expect(quantitySource).toMatch(/step=[{"]0\.01/);
        }

        expect(preciseQuantitySources[0]).toContain('step="0.01"');
        expect(preciseQuantitySources[2].match(/step="0\.01"/g)).toHaveLength(
            3,
        );
    });

    it('keeps one payload-aware PRN UUID for exact retries and rotates after material edits or reset', () => {
        expect(prnWizardSource).toContain('createOfflineRequestUuid');
        expect(prnWizardSource).toContain('const submissionReplay = useRef({');
        expect(prnWizardSource).toContain(
            'const materialFingerprint = JSON.stringify(materialPayload);',
        );
        expect(prnWizardSource).toContain(
            'submissionReplay.current.fingerprint !== materialFingerprint',
        );
        expect(prnWizardSource).toContain(
            'client_request_uuid: submissionReplay.current.uuid,',
        );
        expect(prnWizardSource).toContain('submitEmarMutation(');
        expect(prnWizardSource).toContain(
            'allowQueueWhenOffline: !med.requires_witness',
        );
        expect(prnWizardSource).toContain(
            'if (!emarMutationWasAccepted(result.status)) return;',
        );
        expect(prnWizardSource).toContain('const resetAndClose = () => {');
        expect(prnWizardSource).toContain('fingerprint: null,');
        expect(prnWizardSource).toContain('onClose={resetAndClose}');
        expect(prnWizardSource).not.toContain(
            'client_request_uuid: crypto.randomUUID()',
        );
    });

    it('keeps guided and shift administration UUIDs stable across uncertain retries', () => {
        for (const administrationSource of [
            guidedRoundSource,
            shiftMedicationSource,
        ]) {
            expect(administrationSource).toContain(
                'uuid: createOfflineRequestUuid()',
            );
            expect(administrationSource).toContain(
                'fingerprint: null as string | null',
            );
            expect(administrationSource).toContain('materialFingerprint');
            expect(administrationSource).toContain(
                'client_request_uuid: administrationReplay.current.uuid',
            );
            expect(administrationSource).not.toContain(
                'client_request_uuid: crypto.randomUUID()',
            );
        }
        expect(guidedRoundSource).toContain('witness_credential: undefined');
        expect(guidedRoundSource).toContain('submitEmarMutation(');
        expect(guidedRoundSource).toContain(
            'emarMutationWasAccepted(result.status)',
        );
        expect(shiftMedicationSource).toContain("witness_credential: ''");
        expect(shiftMedicationSource).toContain('Witness password or PIN');
        expect(shiftMedicationSource).toContain(
            'witness_credential: witnessCredential',
        );
        expect(shiftMedicationSource).toContain(
            'allowQueueWhenOffline: !needsWitness',
        );
        expect(shiftMedicationSource).toContain(
            'if (!emarMutationWasAccepted(result.status))',
        );
    });

    it('blocks witness-gated quick PRN actions and gives queueable actions a payload-aware UUID', () => {
        expect(prnSheetSource).toContain('const submissionReplay = useRef({');
        expect(prnSheetSource).toContain('materialFingerprint');
        expect(prnSheetSource).toContain(
            'client_request_uuid: submissionReplay.current.uuid',
        );
        expect(prnSheetSource).toContain('submitEmarMutation(');
        expect(prnSheetSource).toContain(
            'selected.requires_witness || selected.is_controlled',
        );
        expect(prnSheetSource).toContain('!selected.requires_witness &&');
        expect(prnSheetSource).toContain('!selected.is_controlled &&');
        expect(prnSheetSource).toContain(
            'Use the full MAR to record this witnessed dose.',
        );
        expect(prnSheetSource).toContain(
            'if (!emarMutationWasAccepted(result.status)) return;',
        );
    });

    it('binds every direct client-medical administration form to a stable intent UUID', () => {
        for (const administrationSource of clientAdministrationSources) {
            expect(administrationSource).toContain(
                'createMedicationMutationReplayState',
            );
            expect(administrationSource).toContain(
                'prepareMedicationMutationReplayState',
            );
            expect(administrationSource).toContain('client_request_uuid:');
            expect(administrationSource).not.toContain('return `med-admin-');
        }
    });

    it('keeps witness credentials ephemeral on the client medical administration page', () => {
        const clientMedicalSource = clientAdministrationSources[1];

        expect(clientMedicalSource).toContain("witness_credential: ''");
        expect(clientMedicalSource).toContain('Witness password / PIN');
        expect(clientMedicalSource).toContain(
            'witness_credential: _witnessCredential',
        );
        expect(clientMedicalSource).toContain('!navigator.onLine');
    });

    it('gives the Meds Today dose wizard a material-aware online-only UUID', () => {
        const doseWizard = preciseQuantitySources[0];

        expect(doseWizard).toContain('const doseReplay = useRef(');
        expect(doseWizard).toContain('prepareMedicationMutationReplayState(');
        expect(doseWizard).toContain('witness_credential: _witnessCredential');
        expect(doseWizard).toContain(
            'client_request_uuid: doseReplay.current.uuid',
        );
        expect(doseWizard).toContain('!navigator.onLine');
    });

    it('only exposes manual medication classification when both controlled capabilities are present', () => {
        for (const medicalPageSource of clientAdministrationSources.slice(1)) {
            expect(medicalPageSource).toContain(
                'can_edit && can_controlled_view && can_controlled_record',
            );
            expect(medicalPageSource).toContain('{canClassifyMedication &&');
        }
    });
});
