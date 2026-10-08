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
].map((path) => readFileSync(resolve(process.cwd(), path), 'utf8'));
const prnWizardSource = preciseQuantitySources[1];
const guidedRoundSource = readFileSync(
    resolve(
        process.cwd(),
        'resources/js/pages/emar/components/guided-round-dialog.tsx',
    ),
    'utf8',
);
const recordDoseSource = readFileSync(
    resolve(
        process.cwd(),
        'resources/js/components/emar/record-dose/record-dose-dialog.tsx',
    ),
    'utf8',
);
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
const clientEmarDialogSource = readFileSync(
    resolve(
        process.cwd(),
        'resources/js/components/clients/profile/emar-dialog.tsx',
    ),
    'utf8',
);

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
            // PIN-1: the witness types their own witness PIN, never a password.
            expect(dialog).toContain('<WitnessPinInput');
            expect(dialog).not.toContain('password or PIN');
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
            // The replay ref is seeded once from state (never read in render).
            expect(dialog).toContain('createMedicationMutationReplayState()');
            expect(dialog).toMatch(/useRef\(initial(Entry|Balance)Replay\)/);
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

        // Guided rounds use the shared recorder's decimal text inputs, which
        // allow hundredths without a browser number-input step restriction.
        expect(guidedRoundSource).toContain('<RecordDoseDialog');
        for (const id of ['rd-stockQuantity', 'rd-cdBalance']) {
            const inputStart = recordDoseSource.indexOf(`id="${id}"`);
            expect(inputStart).toBeGreaterThanOrEqual(0);
            const input = recordDoseSource.slice(
                inputStart,
                recordDoseSource.indexOf('/>', inputStart),
            );
            expect(input).toContain('inputMode="decimal"');
            expect(input).not.toMatch(/(?:min|step)=[{"]0\.(?:25|5)/);
        }
        expect(recordDoseSource).toContain('Number(f.stockQuantity)');
        expect(recordDoseSource).toContain('Number(f.cdBalance)');
        expect(recordDoseSource).toContain(
            String.raw`/^\d+(\.\d{1,2})?$/.test(f.cdBalance.trim())`,
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
        // A witnessed medicine, or a restricted worker's co-signed dose
        // (NF-03), needs a live credential and is never queued offline.
        expect(prnWizardSource).toContain(
            'const needsWitness = !!med?.requires_witness || cosignerOnly;',
        );
        expect(prnWizardSource).toContain(
            'allowQueueWhenOffline: !needsWitness',
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
        expect(guidedRoundSource).toContain(
            "import { RecordDoseDialog } from '@/components/emar/record-dose/record-dose-dialog'",
        );
        expect(guidedRoundSource).toContain(
            'orderId: recording.item.medication_id',
        );
        expect(guidedRoundSource).toContain(
            'scheduledFor: recording.item.scheduled_for',
        );
        expect(guidedRoundSource).toContain('entry="round"');
        expect(guidedRoundSource).toContain('roundId={round.id}');
        expect(recordDoseSource).toContain(
            'const replay = useRef(createMedicationMutationReplayState());',
        );
        expect(recordDoseSource).toContain(
            'prepareMedicationMutationReplayState(replay.current, material)',
        );
        expect(recordDoseSource).toContain(
            'delete material.witness_credential;',
        );
        expect(recordDoseSource).toContain(
            'client_request_uuid: replay.current.uuid',
        );
        expect(recordDoseSource).toContain(
            'submitEmarMutation<Record<string, unknown>>(',
        );
        const outcomeSource = recordDoseSource.slice(
            recordDoseSource.indexOf('function handleOutcome('),
            recordDoseSource.indexOf('function handleError('),
        );
        expect(outcomeSource).toContain("if (status === 'queued')");
        expect(outcomeSource).toContain(
            "onRecorded?.({ status: 'queued', administrationId: null });",
        );
        expect(outcomeSource).toMatch(
            /status === 'processed'\s*\|\|\s*status === 'synced'\s*\|\|\s*status === 'duplicate'/,
        );
        expect(outcomeSource).toContain("status: 'recorded'");
        for (const status of [
            'requires_connection',
            'storage_unavailable',
            'requires_authentication',
        ]) {
            expect(outcomeSource).toContain(`status === '${status}'`);
        }
        expect(outcomeSource).toContain("setPhase('uncertain');");
        expect(recordDoseSource).toMatch(
            /allowQueueWhenOffline:\s*!needsPin\s*&&\s*!body\.witness_override_id/,
        );
        expect(recordDoseSource).not.toContain(
            'client_request_uuid: crypto.randomUUID()',
        );
        expect(shiftMedicationSource).toContain(
            "import { RecordDoseDialog } from '@/components/emar/record-dose/record-dose-dialog'",
        );
        expect(shiftMedicationSource).toContain('<RecordDoseDialog');
        expect(shiftMedicationSource).toContain(
            'orderId: activeRow.medication.id',
        );
        expect(shiftMedicationSource).toContain(
            'scheduledFor: activeRow.scheduled_for!',
        );
        expect(shiftMedicationSource).toContain('entry="shift"');
        expect(shiftMedicationSource).toContain('shiftContext={{');
        expect(shiftMedicationSource).toContain('shiftId,');
        expect(shiftMedicationSource).toContain(
            'activeRow.medication.scan_verification',
        );
        expect(shiftMedicationSource).toContain(
            "if (result.status !== 'queued')",
        );
        expect(shiftMedicationSource).toContain(
            'router.reload({ preserveScroll: true })',
        );
        expect(shiftMedicationSource).not.toContain(
            'client_request_uuid: crypto.randomUUID()',
        );
        expect(shiftMedicationSource).not.toContain('password or PIN');
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

    it('binds the client profile administration dialog to a stable intent UUID', () => {
        expect(clientEmarDialogSource).toContain(
            'createMedicationMutationReplayState',
        );
        expect(clientEmarDialogSource).toContain(
            'prepareMedicationMutationReplayState',
        );
        expect(clientEmarDialogSource).toContain('client_request_uuid:');
        expect(clientEmarDialogSource).not.toContain('return `med-admin-');
    });

    it('keeps witness credentials ephemeral on the client profile administration dialog', () => {
        expect(clientEmarDialogSource).toContain(
            "const [witnessCredential, setWitnessCredential] = useState('');",
        );
        expect(clientEmarDialogSource).toContain(
            'witness_credential: _witnessCredential',
        );
        expect(clientEmarDialogSource).toContain('!navigator.onLine');
        expect(clientEmarDialogSource).toContain(
            'Witness credentials are never saved on this device.',
        );
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
});
