import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    isJsonObject,
    useVehicleRecordCommand as useRecordCommand,
} from '@/components/fleet-assets/vehicle-workspace/record-command';
import { VehicleSearchSelect as SearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import { WorkspaceWizard } from '@/components/fleet-assets/vehicle-workspace/wizard-kit';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import {
    ArrowRightLeft,
    CheckCircle,
    ClipboardCheck,
    Package,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { AssetRecordPicker } from './record-picker';
import type {
    Movement,
    Option,
    ProfileAction,
    ProfileWorkspace,
} from './types';

const TITLES: Record<ProfileAction, string> = {
    ownership: 'Record asset ownership',
    dispatch: 'Transfer or loan asset',
    receive: 'Record actual receipt',
    return: 'Return loaned asset',
    cancel_movement: 'Cancel dispatch',
    exception: 'Record custody exception',
    verify_location: 'Verify asset location',
    kit_add: 'Add kit item',
    kit_remove: 'Remove kit item',
    retire: 'Retire asset',
    assign: 'Assign responsibility',
    confirm_assignment_receipt: 'Verify assignment receipt',
    release: 'Release responsibility',
    set_photo: 'Choose profile photo',
    remove_photo: 'Remove profile photo',
    generate_qr: 'Create QR identity',
};
const steps = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Record what happened',
        icon: ArrowRightLeft,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the recorded facts',
        icon: ClipboardCheck,
    },
];

export function AssetActionDialog({
    action,
    assetId,
    assetName,
    workspace,
    sites,
    movement,
    itemId,
    onClose,
    onSaved,
}: {
    action: ProfileAction;
    assetId: number;
    assetName: string;
    workspace: ProfileWorkspace;
    sites: Option[];
    movement?: Movement;
    itemId?: number;
    onClose: () => void;
    onSaved: () => void;
}) {
    const command = useRecordCommand(isJsonObject);
    const [step, setStep] = useState(0),
        [reason, setReason] = useState(''),
        [kind, setKind] = useState('transfer'),
        [ownerType, setOwnerType] = useState('site'),
        [owner, setOwner] = useState(''),
        [ownerName, setOwnerName] = useState(''),
        [site, setSite] = useState(
            action === 'return' ? String(movement?.origin_site_id || '') : '',
        ),
        [room, setRoom] = useState(''),
        [person, setPerson] = useState(''),
        [personName, setPersonName] = useState(''),
        [due, setDue] = useState(''),
        [outcome, setOutcome] = useState('acknowledged'),
        [received, setReceived] = useState<number[]>([]),
        [verifiedReceived, setVerifiedReceived] = useState(false),
        [name, setName] = useState(''),
        [component, setComponent] = useState(''),
        [photo, setPhoto] = useState(''),
        [location, setLocation] = useState(''),
        [error, setError] = useState(''),
        [result, setResult] = useState<string | null>(null),
        [requestKey, setRequestKey] = useState(() => crypto.randomUUID());
    const moving = action === 'dispatch' || action === 'return';
    const validate = () => {
        if (action === 'confirm_assignment_receipt' && !verifiedReceived) {
            setError('Confirm that assignment receipt has been verified.');
            return false;
        }
        if (action === 'ownership' && !owner) {
            setError('Choose the recorded owner.');
            return false;
        }
        if (action === 'set_photo' && !photo) {
            setError('Choose an available image from the document library.');
            return false;
        }
        const message = !reason.trim()
            ? 'Record a reason or observation.'
            : moving && (!site || !person)
              ? 'Choose a destination and recipient.'
              : action === 'dispatch' && kind === 'loan' && !due
                ? 'Choose an expected return date.'
                : action === 'receive' &&
                    outcome === 'acknowledged' &&
                    received.length !== movement?.kit.length
                  ? 'Confirm every kit item or choose an incomplete receipt.'
                  : action === 'assign' && !person
                    ? 'Choose the responsible person.'
                    : action === 'kit_add' && !name.trim()
                      ? 'Name the kit item.'
                      : action === 'verify_location' && !location.trim()
                        ? 'Describe the observed location.'
                        : action === 'retire' &&
                            workspace.retirement_blockers.length
                          ? 'Resolve the listed dependencies before retiring this asset.'
                          : '';
        setError(message);
        return !message;
    };
    const submit = async () => {
        if (!validate()) {
            setStep(0);
            return;
        }
        const payload = {
            action,
            ...(action === 'assign'
                ? {
                      assignee_type: person.split(':')[0],
                      assignee_id: Number(person.split(':')[1]),
                  }
                : {}),
            ...(action === 'confirm_assignment_receipt'
                ? { verified_received: verifiedReceived }
                : {}),
            ...(action === 'ownership'
                ? { owner_type: ownerType, owner_id: Number(owner) }
                : {}),
            request_key: requestKey,
            expected_version: workspace.version,
            reason: reason.trim(),
            kind,
            destination_site_id: site ? Number(site) : null,
            destination_room_id: room ? Number(room) : null,
            recipient_user_id: moving && person ? Number(person) : null,
            return_due_on:
                action === 'dispatch' && kind === 'loan' ? due || null : null,
            document_id: photo ? Number(photo) : null,
            movement_id: movement?.id,
            outcome,
            received_kit: received,
            name: name || null,
            component_asset_id: component ? Number(component) : null,
            kit_item_id: itemId,
            assignment_id: ['release', 'confirm_assignment_receipt'].includes(
                action,
            )
                ? itemId
                : null,
            location: location || null,
        };
        const saved = await command.submit(
            `/assets/${assetId}/profile-actions`,
            payload,
        );
        if (saved) {
            setResult(String(saved.message || 'Recorded.'));
            onSaved();
        }
    };
    return (
        <WorkspaceWizard
            title={TITLES[action]}
            description={assetName}
            railIcon={Package}
            railSub="Asset Profile"
            steps={steps}
            step={step}
            setStep={setStep}
            pct={reason ? 70 : 0}
            context={{
                name: assetName,
                detail: 'Custody, holds and financial decisions remain separate.',
            }}
            command={command}
            dirty={
                !!(
                    reason ||
                    site ||
                    person ||
                    name ||
                    location ||
                    due ||
                    photo ||
                    received.length ||
                    verifiedReceived ||
                    outcome !== 'acknowledged' ||
                    kind !== 'transfer'
                )
            }
            saved={!!result}
            submitLabel={TITLES[action]}
            onValidateStep={validate}
            onSubmit={submit}
            onClose={onClose}
            onReload={async () => {
                await onSaved();
                command.reset();
                setRequestKey(crypto.randomUUID());
                setStep(0);
            }}
            success={
                <WizardSuccessPane
                    title="Recorded"
                    blurb={result}
                    actions={<Button onClick={onClose}>Return to asset</Button>}
                />
            }
            errorKey={error + command.message}
        >
            {step === 0 ? (
                <fieldset disabled={command.locked} className="space-y-5">
                    {(error || Object.keys(command.errors).length > 0) && (
                        <p
                            role="alert"
                            tabIndex={-1}
                            className="text-status-critical"
                        >
                            {error || Object.values(command.errors).join(' ')}
                        </p>
                    )}
                    {action === 'dispatch' && (
                        <div
                            role="group"
                            aria-label="Movement type"
                            className="grid grid-cols-2 gap-3"
                        >
                            {[
                                ['transfer', 'Transfer'],
                                ['loan', 'Loan'],
                            ].map(([value, label]) => (
                                <Button
                                    key={value}
                                    variant={
                                        kind === value ? 'default' : 'outline'
                                    }
                                    aria-pressed={kind === value}
                                    onClick={() => setKind(value)}
                                >
                                    <ArrowRightLeft />
                                    {label}
                                </Button>
                            ))}
                        </div>
                    )}
                    {moving && (
                        <>
                            <Label>Destination site *</Label>
                            <SearchSelect
                                label="Destination site"
                                invalid={!!error && !site}
                                value={site}
                                options={sites.map((option) => ({
                                    value: String(option.id),
                                    label: option.name,
                                }))}
                                disabled={action === 'return'}
                                onChange={(value) => {
                                    setSite(value);
                                    setRoom('');
                                    setPerson('');
                                    setPersonName('');
                                }}
                            />
                            <Label>Room (optional)</Label>
                            <AssetRecordPicker
                                assetId={assetId}
                                kind="rooms"
                                siteId={site}
                                value={room}
                                label="Room"
                                onChange={setRoom}
                            />
                        </>
                    )}
                    {(moving || action === 'assign') && (
                        <>
                            <Label>
                                {moving
                                    ? 'Receiving person'
                                    : 'Responsible person'}{' '}
                                *
                            </Label>
                            <AssetRecordPicker
                                assetId={assetId}
                                kind={
                                    action === 'assign' ? 'assignees' : 'staff'
                                }
                                siteId={moving ? site : undefined}
                                invalid={!!error && !person}
                                value={person}
                                label={
                                    moving
                                        ? 'Receiving person'
                                        : 'Responsible person'
                                }
                                onChange={(value, label) => {
                                    setPerson(value);
                                    setPersonName(label);
                                }}
                            />
                        </>
                    )}
                    {action === 'dispatch' && kind === 'loan' && (
                        <>
                            <Label>Expected return date *</Label>
                            <DatePicker
                                id="asset-loan-due"
                                label="Expected return date"
                                value={due}
                                onChange={setDue}
                            />
                            <p className="text-caption text-muted-foreground">
                                A due date does not confirm actual return.
                            </p>
                        </>
                    )}
                    {action === 'confirm_assignment_receipt' && (
                        <label className="flex items-start gap-3 rounded-md border p-4">
                            <input
                                type="checkbox"
                                checked={verifiedReceived}
                                onChange={(event) =>
                                    setVerifiedReceived(event.target.checked)
                                }
                            />
                            <span>
                                I verified that the assigned person received
                                this asset. This does not acknowledge a kit
                                movement or release a Maintenance hold.
                            </span>
                        </label>
                    )}
                    {action === 'receive' && (
                        <>
                            <p>
                                {movement?.origin} → {movement?.destination} ·{' '}
                                {movement?.recipient}
                            </p>
                            <div
                                role="group"
                                aria-label="Receipt outcome"
                                className="flex flex-wrap gap-3"
                            >
                                {[
                                    ['acknowledged', 'Received in full'],
                                    ['incomplete', 'Incomplete'],
                                    ['disputed', 'Disputed'],
                                ].map(([value, label]) => (
                                    <Button
                                        key={value}
                                        variant={
                                            outcome === value
                                                ? 'default'
                                                : 'outline'
                                        }
                                        aria-pressed={outcome === value}
                                        onClick={() => setOutcome(value)}
                                    >
                                        <CheckCircle />
                                        {label}
                                    </Button>
                                ))}
                            </div>
                            <fieldset className="space-y-2">
                                <legend className="mb-2 font-medium">
                                    Confirm each dispatched kit item
                                </legend>
                                {movement?.kit.length ? (
                                    movement.kit.map((item) => (
                                        <label
                                            key={item.id}
                                            className="flex items-center gap-3 rounded-md border p-3"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={received.includes(
                                                    item.id,
                                                )}
                                                onChange={(event) =>
                                                    setReceived(
                                                        event.target.checked
                                                            ? [
                                                                  ...received,
                                                                  item.id,
                                                              ]
                                                            : received.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      item.id,
                                                              ),
                                                    )
                                                }
                                            />
                                            {item.name}
                                        </label>
                                    ))
                                ) : (
                                    <p className="text-subtle text-muted-foreground">
                                        No kit items were recorded for this
                                        dispatch.
                                    </p>
                                )}
                            </fieldset>
                            <p className="text-caption">
                                Receipt does not release a Maintenance hold or
                                change an assignment.
                            </p>
                        </>
                    )}
                    {action === 'set_photo' && (
                        <>
                            <Label>Checked image *</Label>
                            <SearchSelect
                                label="Profile photo"
                                value={photo}
                                onChange={setPhoto}
                                options={workspace.documents
                                    .filter(
                                        (file) =>
                                            file.state === 'available' &&
                                            !file.archived &&
                                            file.mime?.startsWith('image/'),
                                    )
                                    .map((file) => ({
                                        value: String(file.id),
                                        label: file.name,
                                    }))}
                            />
                            <p className="text-subtle">
                                Upload a photo in Documents first. Only files
                                that passed their virus check can be selected.
                            </p>
                        </>
                    )}
                    {action === 'generate_qr' && (
                        <p>
                            Creates a stable asset link for labels. It contains
                            no personal information and requires authorised
                            sign-in when scanned.
                        </p>
                    )}
                    {action === 'remove_photo' && (
                        <p>
                            The photo is removed from the profile. Its original
                            file remains in the document library.
                        </p>
                    )}
                    {action === 'kit_add' && (
                        <>
                            <Label htmlFor="asset-kit-name">Item name *</Label>
                            <Input
                                id="asset-kit-name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                maxLength={160}
                            />
                            <Label>
                                Link a registered component (optional)
                            </Label>
                            <AssetRecordPicker
                                assetId={assetId}
                                kind="components"
                                value={component}
                                label="Component"
                                onChange={(value, label) => {
                                    setComponent(value);
                                    if (!name) setName(label);
                                }}
                            />
                        </>
                    )}
                    {action === 'verify_location' && (
                        <>
                            <Label htmlFor="asset-observed-location">
                                Observed location *
                            </Label>
                            <Input
                                id="asset-observed-location"
                                value={location}
                                onChange={(event) =>
                                    setLocation(event.target.value)
                                }
                            />
                            <p className="text-caption">
                                Records a manual observation at the current
                                site, separate from assigned location and
                                tracker reports.
                            </p>
                        </>
                    )}
                    {action === 'ownership' && (
                        <>
                            <Label>Ownership type *</Label>
                            <SearchSelect
                                label="Ownership type"
                                value={ownerType}
                                options={[
                                    {
                                        value: 'site',
                                        label: 'Organisation site',
                                    },
                                    { value: 'client', label: 'Client-owned' },
                                ]}
                                onChange={(value) => {
                                    setOwnerType(value);
                                    setOwner('');
                                    setOwnerName('');
                                }}
                            />
                            <Label>Recorded owner *</Label>
                            {ownerType === 'site' ? (
                                <SearchSelect
                                    label="Recorded owner"
                                    value={owner}
                                    options={sites.map((site) => ({
                                        value: String(site.id),
                                        label: site.name,
                                    }))}
                                    onChange={(value) => {
                                        setOwner(value);
                                        setOwnerName(
                                            sites.find(
                                                (site) =>
                                                    String(site.id) === value,
                                            )?.name ?? '',
                                        );
                                    }}
                                />
                            ) : (
                                <AssetRecordPicker
                                    assetId={assetId}
                                    kind="owners"
                                    label="Recorded owner"
                                    value={owner}
                                    onChange={(value, name) => {
                                        setOwner(value);
                                        setOwnerName(name);
                                    }}
                                />
                            )}
                            <p className="text-caption">
                                The owner must belong to the asset’s current
                                site. This closes the previous ownership period
                                and retains its history.
                            </p>
                        </>
                    )}
                    {action === 'retire' && (
                        <div className="rounded-lg border p-4">
                            <h3 className="text-section-title">
                                Retirement dependencies
                            </h3>
                            {workspace.retirement_blockers.length ? (
                                <ul className="list-disc space-y-2 pl-5">
                                    {workspace.retirement_blockers.map(
                                        (blocker) => (
                                            <li key={blocker}>{blocker}</li>
                                        ),
                                    )}
                                </ul>
                            ) : (
                                <p>
                                    No blocking dependency was found. Retirement
                                    keeps all history and makes no Finance
                                    posting.
                                </p>
                            )}
                        </div>
                    )}
                    {action === 'release' && (
                        <p>
                            Releases responsibility only. Physical receipt,
                            location and outstanding loans remain separate.
                        </p>
                    )}
                    <Label htmlFor="asset-action-reason">
                        {action === 'verify_location'
                            ? 'Observation'
                            : 'Reason / notes'}{' '}
                        *
                    </Label>
                    <Textarea
                        id="asset-action-reason"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        required
                        aria-invalid={!!error && !reason.trim()}
                        maxLength={
                            action === 'confirm_assignment_receipt' ? 500 : 2000
                        }
                    />
                </fieldset>
            ) : (
                <ReviewCard title={TITLES[action]} icon={UserRound}>
                    <ReviewRow label="Asset" value={assetName} />
                    {action === 'confirm_assignment_receipt' && (
                        <ReviewRow
                            label="Assignment receipt"
                            value={`Verified · Assignment #${itemId}`}
                        />
                    )}
                    {action === 'ownership' && (
                        <ReviewRow label="Recorded owner" value={ownerName} />
                    )}
                    {moving && (
                        <>
                            <ReviewRow
                                label="Destination"
                                value={
                                    sites.find(
                                        (item) => String(item.id) === site,
                                    )?.name ||
                                    movement?.origin ||
                                    '—'
                                }
                            />
                            <ReviewRow label="Recipient" value={personName} />
                            {due && (
                                <ReviewRow
                                    label="Expected return"
                                    value={formatDateOnly(due)}
                                />
                            )}
                        </>
                    )}
                    {action === 'receive' && (
                        <>
                            <ReviewRow
                                label="Outcome"
                                value={outcome.replaceAll('_', ' ')}
                            />
                            <ReviewRow
                                label="Kit confirmed"
                                value={`${received.length} of ${movement?.kit.length || 0}`}
                            />
                        </>
                    )}
                    {personName && action === 'assign' && (
                        <ReviewRow label="Responsibility" value={personName} />
                    )}
                    <ReviewRow label="Reason" value={reason} />
                    {name && <ReviewRow label="Item" value={name} />}
                    <p className="text-caption">
                        This action will be attributed to your signed-in
                        account. Any Maintenance hold remains unchanged.
                    </p>
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}
