import { ConfirmDialog } from '@/components/confirm-dialog';
import type { ControlledWitness } from '@/components/emar/controlled/product-types';
import {
    emptyWitness,
    witnessErrors,
    WitnessField,
} from '@/components/emar/controlled/product-ui';
import InputError from '@/components/input-error';
import { WitnessPinInput } from '@/components/medications/witness-pin-input';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    Camera,
    Check,
    ClipboardCheck,
    Package,
    Plus,
    Truck,
    Users,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { csrfHeaders, useStockCommand } from './_requests';
import type { StockItem, SupplyOrder } from './_types';

type ReceiptPack = {
    key: string;
    quantity: string;
    batch_number: string;
    batch_not_printed: boolean;
    expiry_month: string;
    expiry_not_printed: boolean;
    short_expiry_reason: string;
};
const blankPack = (key: string): ReceiptPack => ({
    key,
    quantity: '',
    batch_number: '',
    batch_not_printed: false,
    expiry_month: '',
    expiry_not_printed: false,
    short_expiry_reason: '',
});
const baseSteps = [
    {
        key: 'source',
        label: 'What arrived',
        blurb: 'Source and label',
        icon: Truck,
    },
    {
        key: 'packs',
        label: 'Count the packs',
        blurb: 'Each batch and expiry',
        icon: Package,
    },
    {
        key: 'photo',
        label: 'Pack photo',
        blurb: 'Optional identification',
        icon: Camera,
    },
    {
        key: 'review',
        label: 'Review and receive',
        blurb: 'Check what will be saved',
        icon: ClipboardCheck,
    },
];
export function ReceiveWizard({
    item,
    order,
    onClose,
    onSaved,
}: {
    item: StockItem & {
        witnesses?: ControlledWitness[];
        current_user_id?: number;
    };
    order?: SupplyOrder;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [witness, setWitness] = useState(emptyWitness);
    const candidates = (item.witnesses ?? []).filter(
        (person) => person.id !== item.current_user_id,
    );
    const witnessValid =
        !item.controlled ||
        Object.keys(witnessErrors(witness, candidates)).length === 0;
    const steps = item.controlled
        ? [
              ...baseSteps.slice(0, 3),
              {
                  key: 'witness',
                  label: 'Witness',
                  blurb: 'Check the delivery together',
                  icon: Users,
              },
              baseSteps[3],
          ]
        : baseSteps;
    const reviewIndex = steps.length - 1;
    const [step, setStep] = useState(0);
    const [source, setSource] = useState(order ? 'pharmacy' : '');
    const [reference, setReference] = useState(
        order ? `Pharmacy order #${order.id}` : '',
    );
    const [checked, setChecked] = useState(false);
    const [packs, setPacks] = useState<ReceiptPack[]>([
        {
            ...blankPack('first'),
            batch_number: order?.batch_number ?? '',
            expiry_month: order?.batch_expiry
                ? `${order.batch_expiry.slice(5, 7)}/${order.batch_expiry.slice(0, 4)}`
                : '',
        },
    ]);
    const [unit, setUnit] = useState(item.unit ?? '');
    const [outcome, setOutcome] = useState<
        '' | 'still_to_come' | 'closed_short'
    >('');
    const [closureReason, setClosureReason] = useState('');
    const [notes, setNotes] = useState('');
    const [photo, setPhoto] = useState<File | null>(null);
    const [photoPack, setPhotoPack] = useState('first');
    const [looksDifferent, setLooksDifferent] = useState(false);
    const [photoError, setPhotoError] = useState('');
    const [uploading, setUploading] = useState(false);
    const [savedLots, setSavedLots] = useState<number[]>([]);
    const [photoSaved, setPhotoSaved] = useState(false);
    const [discard, setDiscard] = useState(false);
    const camera = useRef<HTMLInputElement>(null);
    const photoUuid = useRef(crypto.randomUUID());
    const command = useStockCommand();
    const saving = command.saving || uploading;
    const snapshot = JSON.stringify({
        source,
        reference,
        checked,
        packs,
        unit,
        outcome,
        closureReason,
        notes,
        looksDifferent,
        witnessId: witness.id,
    });
    const initial = useRef(snapshot);
    const dirty = snapshot !== initial.current || photo !== null;
    const allCounted = packs.every(
        (pack) =>
            /^\d+(?:\.\d{1,2})?$/.test(pack.quantity) &&
            Number.isFinite(Number(pack.quantity)) &&
            Number(pack.quantity) > 0,
    );
    const totalCents = allCounted
        ? packs.reduce(
              (sum, pack) => sum + Math.round(Number(pack.quantity) * 100),
              0,
          )
        : 0;
    const total = (totalCents / 100).toFixed(2);
    const expectedCents = order
        ? Math.round(
              (Number(order.quantity_dispensed ?? order.quantity_ordered) -
                  Number(order.quantity_received ?? 0)) *
                  100,
          )
        : null;
    const shortBy =
        allCounted && expectedCents !== null && totalCents < expectedCents
            ? (expectedCents - totalCents) / 100
            : 0;
    const overDelivery =
        allCounted && expectedCents !== null && totalCents > expectedCents;
    const sourceValid = !!source && !!reference.trim() && checked;
    const packsValid =
        allCounted &&
        !!unit.trim() &&
        !overDelivery &&
        packs.every(
            (pack) =>
                (pack.batch_not_printed || !!pack.batch_number.trim()) &&
                (pack.expiry_not_printed || !!pack.expiry_month.trim()),
        ) &&
        (!shortBy ||
            (!!outcome &&
                (outcome !== 'closed_short' || !!closureReason.trim())));
    const navigate = (index: number) => {
        if (
            saving ||
            (index > 0 && !sourceValid) ||
            (index > 1 && !packsValid) ||
            (index === reviewIndex && !witnessValid)
        )
            return;
        setStep(index);
    };
    const close = () => {
        if (!saving) {
            if (dirty && savedLots.length === 0) setDiscard(true);
            else onClose();
        }
    };
    const updatePack = (key: string, patch: Partial<ReceiptPack>) =>
        setPacks((current) =>
            current.map((pack) =>
                pack.key === key ? { ...pack, ...patch } : pack,
            ),
        );
    const removePack = (key: string) => {
        const remaining = packs.filter((pack) => pack.key !== key);
        setPacks(remaining);
        if (photoPack === key) setPhotoPack(remaining[0].key);
    };
    const choosePhoto = (files: File[]) => {
        const file = files[0];
        if (!file) return;
        if (
            !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) ||
            file.size > 8 * 1024 * 1024
        ) {
            setPhotoError('Choose a JPEG, PNG or WebP photo up to 8 MB.');
            return;
        }
        setPhoto(file);
        photoUuid.current = crypto.randomUUID();
        setPhotoError('');
    };
    const upload = async (lotId: number) => {
        if (!photo) return;
        setUploading(true);
        setPhotoError('');
        const body = new FormData();
        body.set('photo', photo);
        body.set('request_uuid', photoUuid.current);
        try {
            const response = await fetch(`/emar/stock/packs/${lotId}/photos`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: csrfHeaders(),
                body,
            });
            const result = await response.json();
            if (!response.ok || result.success !== true) {
                setPhotoError(
                    result.errors?.photo?.[0] ??
                        result.message ??
                        'Stock is received. The photo was not saved; keep it here and try again.',
                );
                return;
            }
            setPhotoSaved(true);
        } catch {
            setPhotoError(
                'Stock is received. The photo save could not be confirmed; keep it here and try again.',
            );
        } finally {
            setUploading(false);
        }
    };
    const receive = async () => {
        if (!sourceValid || !packsValid || !witnessValid) return;
        const result = await command.run({
            action: 'receive',
            client_medication_id: item.id,
            unit,
            source,
            source_reference: reference,
            label_checked: checked,
            packs: packs.map((pack) => ({
                quantity: pack.quantity,
                batch_number: pack.batch_not_printed ? null : pack.batch_number,
                batch_not_printed: pack.batch_not_printed,
                expiry_month: pack.expiry_not_printed
                    ? null
                    : pack.expiry_month,
                expiry_not_printed: pack.expiry_not_printed,
                short_expiry_reason: pack.short_expiry_reason,
            })),
            delivery_outcome: shortBy ? outcome : null,
            closure_reason:
                shortBy && outcome === 'closed_short' ? closureReason : null,
            notes,
            pharmacy_order_id: order?.id ?? null,
            ...(item.controlled
                ? {
                      witnessed_by: Number(witness.id),
                      witness_credential: witness.pin,
                  }
                : {}),
        });
        const lotIds =
            result?.lot_ids ?? (result?.lot_id ? [result.lot_id] : []);
        if (!lotIds.length) return;
        setWitness(emptyWitness());
        setSavedLots(lotIds);
        onSaved();
        await upload(
            lotIds[
                Math.max(
                    0,
                    packs.findIndex((pack) => pack.key === photoPack),
                )
            ],
        );
    };
    const photoLotId =
        savedLots[
            Math.max(
                0,
                packs.findIndex((pack) => pack.key === photoPack),
            )
        ];
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close}
                title="Receive a delivery"
                description="Count each supplied batch and check its printed label."
                railIcon={Truck}
                railTitle="Receive a delivery"
                railSub={`${item.client_name} · ${item.name}`}
                steps={steps.map((entry, index) => ({
                    ...entry,
                    disabled:
                        saving ||
                        (index > 0 && !sourceValid) ||
                        (index > 1 && !packsValid) ||
                        (index === reviewIndex && !witnessValid),
                }))}
                stepIndex={step}
                onStepClick={navigate}
                pct={((step + 1) / steps.length) * 100}
                footerStart={
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        disabled={saving}
                        onClick={close}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            disabled={step === 0 || saving}
                            onClick={() => setStep(step - 1)}
                        >
                            Back
                        </Button>
                        <Button
                            className="frontline-tap"
                            disabled={
                                saving ||
                                !sourceValid ||
                                (step > 0 && !packsValid) ||
                                (step >= 3 && !witnessValid)
                            }
                            onClick={() =>
                                step < reviewIndex
                                    ? navigate(step + 1)
                                    : void receive()
                            }
                        >
                            {saving
                                ? 'Saving…'
                                : step === reviewIndex
                                  ? 'Receive stock'
                                  : step === 2 && !photo
                                    ? 'Continue without a photo'
                                    : 'Continue'}
                        </Button>
                    </>
                }
                success={
                    savedLots.length ? (
                        <WizardSuccessPane
                            title="Stock received"
                            blurb={
                                <>
                                    {total} {unit} received across{' '}
                                    {packs.length}{' '}
                                    {packs.length === 1
                                        ? 'pack record'
                                        : 'pack records'}{' '}
                                    for {item.client_name}.{' '}
                                    {shortBy > 0 && outcome === 'still_to_come'
                                        ? `${shortBy} ${unit} still to come. `
                                        : shortBy > 0
                                          ? 'The order is closed short. '
                                          : ''}
                                    {photoSaved
                                        ? 'The selected pack photo is saved.'
                                        : photo
                                          ? 'The photo still needs to be saved.'
                                          : 'Received without a photo.'}
                                    {photoError && (
                                        <SettingsNotice>
                                            {photoError}
                                        </SettingsNotice>
                                    )}
                                </>
                            }
                            actions={
                                <>
                                    {photo && !photoSaved && (
                                        <Button
                                            disabled={uploading}
                                            onClick={() =>
                                                void upload(photoLotId)
                                            }
                                        >
                                            {uploading
                                                ? 'Saving photo…'
                                                : 'Try photo again'}
                                        </Button>
                                    )}
                                    <Button
                                        disabled={uploading}
                                        onClick={onClose}
                                    >
                                        Done
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <fieldset
                        disabled={saving || command.uncertain}
                        className="min-w-0 space-y-4"
                    >
                        {Object.keys(command.errors).length > 0 && (
                            <SettingsNotice>
                                {Object.entries(command.errors).map(
                                    ([key, error]) => (
                                        <InputError key={key} message={error} />
                                    ),
                                )}
                            </SettingsNotice>
                        )}
                        <div className="space-y-4">
                            {step === 0 && (
                                <>
                                    <p className="text-section-title">
                                        {item.name}
                                    </p>
                                    <p>
                                        {item.client_name} · {item.site_name}
                                    </p>
                                    {order && (
                                        <SettingsNotice role="note">
                                            Order #{order.id}: the pharmacy sent{' '}
                                            {expectedCents !== null
                                                ? expectedCents / 100
                                                : 'an unrecorded quantity'}{' '}
                                            {unit} still to receive. Count what
                                            actually arrived.
                                        </SettingsNotice>
                                    )}
                                    <Label>Where did it come from?</Label>
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                        {[
                                            ['pharmacy', 'Pharmacy'],
                                            ['family', 'Family'],
                                            ['hospital', 'Hospital'],
                                            ['respite', 'Respite'],
                                            ['other', 'Other source'],
                                        ].map(([key, label]) => (
                                            <Button
                                                key={key}
                                                type="button"
                                                variant="outline"
                                                aria-pressed={source === key}
                                                onClick={() => setSource(key)}
                                            >
                                                <Truck className="size-4" />
                                                {label}
                                                {source === key && (
                                                    <Check className="size-4" />
                                                )}
                                            </Button>
                                        ))}
                                    </div>
                                    <Label htmlFor="source_reference">
                                        Source or delivery reference
                                    </Label>
                                    <Input
                                        id="source_reference"
                                        value={reference}
                                        onChange={(event) =>
                                            setReference(event.target.value)
                                        }
                                    />
                                    <label className="frontline-tap flex items-center gap-2">
                                        <Checkbox
                                            checked={checked}
                                            onCheckedChange={(value) =>
                                                setChecked(value === true)
                                            }
                                        />
                                        I checked the person's name, medicine
                                        and instructions against the label.
                                    </label>
                                </>
                            )}
                            {step === 1 && (
                                <>
                                    {!item.stock_id && (
                                        <>
                                            <Label htmlFor="unit">
                                                Counted unit printed on the pack
                                            </Label>
                                            <Input
                                                id="unit"
                                                value={unit}
                                                onChange={(event) =>
                                                    setUnit(event.target.value)
                                                }
                                            />
                                            <p className="text-caption">
                                                Record the supplied unit, such
                                                as tablets or mL.
                                            </p>
                                        </>
                                    )}
                                    {packs.map((pack, index) => (
                                        <ReviewCard
                                            key={pack.key}
                                            icon={Package}
                                            title={`Pack ${index + 1}`}
                                        >
                                            <Label
                                                htmlFor={`packs.${index}.quantity`}
                                            >
                                                Counted quantity ({unit})
                                            </Label>
                                            <Input
                                                id={`packs.${index}.quantity`}
                                                type="number"
                                                min="0.01"
                                                step="0.01"
                                                value={pack.quantity}
                                                onChange={(event) =>
                                                    updatePack(pack.key, {
                                                        quantity:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            <p className="text-caption">
                                                Count these packs; leave the
                                                quantity blank until counted.
                                            </p>
                                            <Label
                                                htmlFor={`packs.${index}.batch_number`}
                                            >
                                                Batch printed on the pack
                                            </Label>
                                            <Input
                                                id={`packs.${index}.batch_number`}
                                                disabled={
                                                    pack.batch_not_printed
                                                }
                                                value={pack.batch_number}
                                                onChange={(event) =>
                                                    updatePack(pack.key, {
                                                        batch_number:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            <label className="frontline-tap flex items-center gap-2">
                                                <Checkbox
                                                    checked={
                                                        pack.batch_not_printed
                                                    }
                                                    onCheckedChange={(value) =>
                                                        updatePack(pack.key, {
                                                            batch_not_printed:
                                                                value === true,
                                                        })
                                                    }
                                                />
                                                Batch not printed on the pack
                                            </label>
                                            <Label
                                                htmlFor={`packs.${index}.expiry_month`}
                                            >
                                                Expiry printed on the pack
                                                (MM/YYYY)
                                            </Label>
                                            <Input
                                                id={`packs.${index}.expiry_month`}
                                                placeholder="MM/YYYY"
                                                disabled={
                                                    pack.expiry_not_printed
                                                }
                                                value={pack.expiry_month}
                                                onChange={(event) =>
                                                    updatePack(pack.key, {
                                                        expiry_month:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            <label className="frontline-tap flex items-center gap-2">
                                                <Checkbox
                                                    checked={
                                                        pack.expiry_not_printed
                                                    }
                                                    onCheckedChange={(value) =>
                                                        updatePack(pack.key, {
                                                            expiry_not_printed:
                                                                value === true,
                                                        })
                                                    }
                                                />
                                                Expiry not printed on the pack
                                            </label>
                                            <Label
                                                htmlFor={`packs.${index}.short_expiry_reason`}
                                            >
                                                If it expires within 7 days, why
                                                accept it?
                                            </Label>
                                            <Textarea
                                                id={`packs.${index}.short_expiry_reason`}
                                                value={pack.short_expiry_reason}
                                                onChange={(event) =>
                                                    updatePack(pack.key, {
                                                        short_expiry_reason:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            {packs.length > 1 && (
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        removePack(pack.key)
                                                    }
                                                >
                                                    Remove this pack entry
                                                </Button>
                                            )}
                                        </ReviewCard>
                                    ))}
                                    <Button
                                        variant="outline"
                                        disabled={packs.length >= 25}
                                        onClick={() =>
                                            setPacks([
                                                ...packs,
                                                blankPack(crypto.randomUUID()),
                                            ])
                                        }
                                    >
                                        <Plus className="size-4" />
                                        Add another batch
                                    </Button>
                                    {overDelivery && (
                                        <SettingsNotice>
                                            More arrived than the pharmacy sent.
                                            Check the count and source with the
                                            house lead.
                                        </SettingsNotice>
                                    )}
                                    {shortBy > 0 && (
                                        <>
                                            <SettingsNotice role="note">
                                                {shortBy} {unit} fewer than
                                                expected. Say whether the rest
                                                is coming.
                                            </SettingsNotice>
                                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                                <Button
                                                    variant="outline"
                                                    aria-pressed={
                                                        outcome ===
                                                        'still_to_come'
                                                    }
                                                    onClick={() =>
                                                        setOutcome(
                                                            'still_to_come',
                                                        )
                                                    }
                                                >
                                                    The rest is still to come
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    aria-pressed={
                                                        outcome ===
                                                        'closed_short'
                                                    }
                                                    onClick={() =>
                                                        setOutcome(
                                                            'closed_short',
                                                        )
                                                    }
                                                >
                                                    No more is coming
                                                </Button>
                                            </div>
                                            {outcome === 'closed_short' && (
                                                <>
                                                    <Label htmlFor="closure_reason">
                                                        Why is no more coming?
                                                    </Label>
                                                    <Textarea
                                                        id="closure_reason"
                                                        value={closureReason}
                                                        onChange={(event) =>
                                                            setClosureReason(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    />
                                                </>
                                            )}
                                        </>
                                    )}
                                    <Label htmlFor="notes">
                                        Delivery notes (optional)
                                    </Label>
                                    <Textarea
                                        id="notes"
                                        value={notes}
                                        onChange={(event) =>
                                            setNotes(event.target.value)
                                        }
                                    />
                                </>
                            )}
                            {step === 2 && (
                                <>
                                    <SettingsNotice role="note">
                                        A photo helps identify this supplied
                                        pack. Check the label and current order.
                                        Taking a photo is optional; earlier
                                        photos are kept.
                                    </SettingsNotice>
                                    <label className="frontline-tap flex items-center gap-2">
                                        <Checkbox
                                            checked={looksDifferent}
                                            onCheckedChange={(value) =>
                                                setLooksDifferent(
                                                    value === true,
                                                )
                                            }
                                        />
                                        This pack or brand looks different.
                                    </label>
                                    {looksDifferent && (
                                        <SettingsNotice role="note">
                                            Add a new photo to help staff
                                            identify the changed pack.
                                        </SettingsNotice>
                                    )}
                                    {packs.length > 1 && (
                                        <RecordPicker
                                            label="Pack shown in this photo"
                                            value={photoPack}
                                            options={packs.map(
                                                (pack, index) => ({
                                                    value: pack.key,
                                                    label: `Pack ${index + 1} · ${pack.batch_number || 'Batch not printed'}`,
                                                }),
                                            )}
                                            onChange={setPhotoPack}
                                        />
                                    )}
                                    <FileDropzone
                                        accept="image/jpeg,image/png,image/webp"
                                        multiple={false}
                                        onFiles={choosePhoto}
                                        title="Add a pack photo"
                                        hint="JPEG, PNG or WebP · up to 8 MB"
                                    />
                                    <input
                                        ref={camera}
                                        className="hidden"
                                        type="file"
                                        accept="image/*"
                                        capture="environment"
                                        onChange={(event) =>
                                            choosePhoto(
                                                Array.from(
                                                    event.target.files ?? [],
                                                ),
                                            )
                                        }
                                    />
                                    <Button
                                        variant="outline"
                                        className="frontline-tap"
                                        onClick={() => camera.current?.click()}
                                    >
                                        <Camera className="size-4" />
                                        Take a photo
                                    </Button>
                                    {photo && (
                                        <StagedFileCard
                                            file={photo}
                                            onRemove={() => setPhoto(null)}
                                        >
                                            <p className="text-caption">
                                                Saved for the selected pack
                                                after stock is received.
                                            </p>
                                        </StagedFileCard>
                                    )}
                                    <InputError message={photoError} />
                                </>
                            )}
                            {item.controlled && step === 3 && (
                                <WitnessField
                                    value={witness}
                                    onChange={setWitness}
                                    candidates={candidates}
                                    errors={command.errors}
                                />
                            )}
                            {step === reviewIndex && (
                                <>
                                    {packs.map((pack, index) => (
                                        <ReviewCard
                                            key={pack.key}
                                            icon={Package}
                                            title={`Supplied pack ${index + 1}`}
                                            onEdit={() => setStep(1)}
                                        >
                                            <ReviewRow
                                                label="Person"
                                                value={item.client_name}
                                            />
                                            <ReviewRow
                                                label="Medicine"
                                                value={item.name}
                                            />
                                            <ReviewRow
                                                label="Counted"
                                                value={`${pack.quantity} ${unit}`}
                                            />
                                            <ReviewRow
                                                label="Batch"
                                                value={
                                                    pack.batch_not_printed
                                                        ? 'Not printed on the pack'
                                                        : pack.batch_number
                                                }
                                            />
                                            <ReviewRow
                                                label="Expiry"
                                                value={
                                                    pack.expiry_not_printed
                                                        ? 'Not printed on the pack'
                                                        : pack.expiry_month
                                                }
                                            />
                                            <ReviewRow
                                                label="Short expiry reason"
                                                value={pack.short_expiry_reason}
                                            />
                                        </ReviewCard>
                                    ))}
                                    <ReviewCard icon={Truck} title="Delivery">
                                        {item.controlled && (
                                            <ReviewRow
                                                label="Witness"
                                                value={
                                                    candidates.find(
                                                        (person) =>
                                                            String(
                                                                person.id,
                                                            ) === witness.id,
                                                    )?.name ?? 'Not selected'
                                                }
                                            />
                                        )}
                                        <ReviewRow
                                            label="Total counted"
                                            value={`${total} ${unit}`}
                                        />
                                        <ReviewRow
                                            label="Source"
                                            value={reference}
                                        />
                                        <ReviewRow
                                            label="Still to come"
                                            value={
                                                shortBy
                                                    ? outcome ===
                                                      'still_to_come'
                                                        ? `${shortBy} ${unit}`
                                                        : `None — close short: ${closureReason}`
                                                    : order
                                                      ? 'None'
                                                      : 'No pharmacy order'
                                            }
                                        />
                                        <ReviewRow
                                            label="Photo"
                                            value={
                                                photo
                                                    ? `Staged for pack ${packs.findIndex((pack) => pack.key === photoPack) + 1}`
                                                    : 'Continue without a photo'
                                            }
                                        />
                                    </ReviewCard>
                                    <SettingsNotice role="note">
                                        Saving creates a separate record for
                                        every batch and a permanent receipt
                                        history.{' '}
                                        {order
                                            ? 'The supply record is updated with the counted delivery and its outcome.'
                                            : 'No pharmacy order is transmitted.'}{' '}
                                        This is not a stock count.
                                    </SettingsNotice>
                                </>
                            )}
                        </div>
                    </fieldset>
                    {command.uncertain && item.controlled && (
                        <div className="mt-4 space-y-2">
                            <p className="text-caption">
                                Keep the same delivery and witness while
                                checking the earlier save. The witness can
                                re-enter their own PIN to retry this request.
                            </p>
                            <WitnessPinInput
                                id="receipt-retry-witness-pin"
                                label="Same witness’s 6-digit PIN"
                                value={witness.pin}
                                onChange={(pin) =>
                                    setWitness((current) => ({
                                        ...current,
                                        pin,
                                    }))
                                }
                                disabled={saving}
                                atCupboard
                                error={command.errors.witness_credential}
                            />
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                frontline
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    if (command.uncertain) onSaved();
                    onClose();
                }}
                title={
                    command.uncertain
                        ? 'Leave this unconfirmed receipt?'
                        : 'Discard this receipt?'
                }
                description={
                    command.uncertain
                        ? 'The stock may already have been received. Closing discards this draft and staged photo. Check the stock history before starting another receipt for this delivery.'
                        : 'Nothing has been received. Your entries and staged photo will be discarded.'
                }
                confirmText={
                    command.uncertain
                        ? 'Close and check history'
                        : 'Discard draft'
                }
            />
        </>
    );
}
