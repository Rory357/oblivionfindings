import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    Accessibility,
    ArrowRight,
    Camera,
    Car,
    FileText,
    History,
    MapPin,
    ShieldCheck,
    Upload,
    UserRound,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { CataloguePicker, formatChoice, PersonPicker } from './choice-picker';
// The design's facts grid and compact timeline are ported with the Finance styles.
import './finance.css';
import { DocumentUploadDialog } from './overview-documents';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { SectionHeading } from './studio-kit';
import type { VehicleProfile, VehicleWorkspace } from './types';
import {
    fieldProps,
    RecordDialog,
    StagedFilesField,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';
import {
    FILE_STATE_LABELS,
    todayInAuckland,
    type WorkspaceLocation,
} from './workspace-model';

export type EligibleDriver = {
    id: number;
    name: string;
    email: string;
    licence_status?: string | null;
    licence_expires_at?: string | null;
};

export const LIFECYCLE: Record<
    string,
    { label: string; variant: StatusVariant }
> = {
    active: { label: 'In service', variant: 'success' },
    maintenance: { label: 'In maintenance', variant: 'warning' },
    out_of_service: { label: 'Out of service', variant: 'critical' },
    retired: { label: 'Retired', variant: 'neutral' },
};

const FUEL_TYPES = [
    { value: 'petrol', label: 'Petrol' },
    { value: 'diesel', label: 'Diesel' },
    { value: 'electric', label: 'Electric' },
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'lpg', label: 'LPG' },
];

const FEATURES = [
    { key: 'has_wheelchair_ramp', label: 'Wheelchair ramp' },
    { key: 'has_hoist', label: 'Hoist' },
    { key: 'has_child_seat_anchors', label: 'Child seat anchors' },
    { key: 'has_medical_storage', label: 'Medical storage' },
] as const;

type FeatureKey = (typeof FEATURES)[number]['key'];

function fuelLabel(value: string | null): string | null {
    return FUEL_TYPES.find((fuel) => fuel.value === value)?.label ?? value;
}

/** The design's two-column fact list. */
function Facts({ rows }: { rows: Array<[string, ReactNode]> }) {
    return (
        <dl className="facts-grid">
            {rows.map(([label, value]) => (
                <div key={label}>
                    <dt>{label}</dt>
                    <dd>{value || 'Not recorded'}</dd>
                </div>
            ))}
        </dl>
    );
}

export function DetailsPanel({
    workspace,
    sites,
    drivers,
    onNavigate,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    sites: Array<{ id: number; name: string }>;
    drivers: EligibleDriver[];
    onNavigate: (location: WorkspaceLocation) => void;
    onChanged: () => void;
}) {
    const { vehicle, can } = workspace;
    const [dialog, setDialog] = useState<
        'record' | 'photo' | 'placement' | 'accessibility' | 'upload' | null
    >(null);
    const lifecycle = LIFECYCLE[vehicle.status] ?? {
        label: vehicle.status,
        variant: 'neutral' as StatusVariant,
    };
    const title =
        [vehicle.manufacturer, vehicle.model].filter(Boolean).join(' ') ||
        vehicle.name;
    const driver = drivers.find(
        (candidate) => candidate.id === vehicle.primary_driver?.id,
    );
    // The vehicle's own current files; files kept with other records stay in Documents.
    const files = can.view_documents
        ? workspace.documents.flatMap((set) =>
              set.files
                  .filter((file) => file.current)
                  .map((file) => ({ ...file, category: set.category })),
          )
        : [];

    return (
        <div className="profile-details-studio">
            <section className="studio-card profile-identity">
                <div className="profile-photo">
                    {vehicle.photo_url ? (
                        <img
                            src={vehicle.photo_url}
                            alt={`${vehicle.name} profile photo`}
                        />
                    ) : (
                        <Car size={74} aria-hidden />
                    )}
                    {can.manage_documents && (
                        <Button
                            variant="secondary"
                            onClick={() => setDialog('photo')}
                        >
                            <Camera className="size-4" />
                            {vehicle.photo_url
                                ? 'Change photo'
                                : 'Upload profile photo'}
                        </Button>
                    )}
                </div>
                <div>
                    <span className="studio-eyebrow">
                        {[vehicle.asset_tag, vehicle.registration_number]
                            .filter(Boolean)
                            .join(' · ') || 'Vehicle'}
                    </span>
                    <h2 className="text-section-title">{title}</h2>
                    <p className="muted">
                        {[vehicle.site?.name, vehicle.use_purpose]
                            .filter(Boolean)
                            .join(' · ') || 'No site or use recorded'}
                    </p>
                    <StatusBadge
                        variant={lifecycle.variant}
                        label={lifecycle.label}
                    />
                </div>
            </section>

            <section className="studio-card">
                <SectionHeading title="Vehicle details">
                    {can.manage && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setDialog('record')}
                        >
                            Edit details
                        </Button>
                    )}
                </SectionHeading>
                <Facts
                    rows={[
                        ['VIN / chassis', vehicle.serial_number],
                        ['Category', vehicle.body_type],
                        [
                            'Fuel / seats',
                            [
                                fuelLabel(vehicle.fuel_type),
                                vehicle.seating_capacity
                                    ? `${vehicle.seating_capacity} seats`
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(' · '),
                        ],
                        ['Responsible person', vehicle.responsible?.name],
                        [
                            'Insurance',
                            [
                                vehicle.insurance_provider,
                                vehicle.insurance_policy_reference,
                            ]
                                .filter(Boolean)
                                .join(' · '),
                        ],
                        ['Warranty', vehicle.warranty_reference],
                        ['Ownership', vehicle.ownership_arrangement],
                        [
                            'Purchase / start date',
                            vehicle.purchase_date
                                ? formatDateOnly(vehicle.purchase_date)
                                : null,
                        ],
                    ]}
                />
                <p className="studio-footnote">
                    Lifecycle changes such as retirement use the Asset
                    retirement workflow. Finance recognition stays with Finance.
                </p>
            </section>

            <section className="studio-card profile-documents">
                <div className="evidence-shelf">
                    <div className="studio-section-heading">
                        <h3 className="text-section-title">
                            Vehicle documents <small>{files.length}</small>
                        </h3>
                        {can.manage_documents && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setDialog('upload')}
                            >
                                <Upload className="size-4" />
                                Upload
                            </Button>
                        )}
                    </div>
                    {files.length ? (
                        <div className="attachment-grid">
                            {files.map((file) =>
                                file.url ? (
                                    <a
                                        key={file.id}
                                        className="attachment-item"
                                        href={file.url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <FileText size={24} aria-hidden />
                                        <div>
                                            <strong>{file.name}</strong>
                                            <small>{file.category}</small>
                                        </div>
                                    </a>
                                ) : (
                                    <div
                                        key={file.id}
                                        className="attachment-item"
                                    >
                                        <FileText size={24} aria-hidden />
                                        <div>
                                            <strong>{file.name}</strong>
                                            <small>
                                                {file.category} ·{' '}
                                                {file.state
                                                    ? (FILE_STATE_LABELS[
                                                          file.state
                                                      ]?.label ??
                                                      'Not available yet')
                                                    : 'Not available yet'}
                                            </small>
                                        </div>
                                    </div>
                                ),
                            )}
                        </div>
                    ) : (
                        <div className="evidence-empty">
                            <FileText size={20} aria-hidden />
                            <span>PDFs, photos and supporting records</span>
                        </div>
                    )}
                </div>
                {can.view_documents && (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            onNavigate({ tab: 'overview', view: 'documents' })
                        }
                    >
                        Manage documents &amp; renewals
                        <ArrowRight className="size-3.5" />
                    </Button>
                )}
                {can.view_finance && (
                    <Button
                        variant="outline"
                        onClick={() =>
                            onNavigate({ tab: 'overview', view: 'finance' })
                        }
                    >
                        Finance records
                        <ArrowRight className="size-3.5" />
                    </Button>
                )}
            </section>

            <section className="studio-card">
                <SectionHeading title="Lifecycle history" />
                {vehicle.history.length ? (
                    <div className="compact-timeline">
                        {vehicle.history.map((entry) => (
                            <div key={entry.id}>
                                <span className="timeline-node">
                                    <History size={14} aria-hidden />
                                </span>
                                <p>
                                    {[
                                        formatDateTime(entry.occurred_at),
                                        entry.label,
                                    ].join(' · ')}
                                    {entry.reason && (
                                        <small className="block text-muted-foreground">
                                            {entry.reason}
                                            {entry.actor
                                                ? ` · ${entry.actor}`
                                                : ''}
                                        </small>
                                    )}
                                </p>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="muted">
                        Changes to this vehicle record will be listed here with
                        their reason.
                    </p>
                )}
            </section>

            <section className="studio-card">
                <SectionHeading title="Placement & driver">
                    {can.manage && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setDialog('placement')}
                        >
                            <MapPin className="size-4" /> Change
                        </Button>
                    )}
                </SectionHeading>
                <Facts
                    rows={[
                        [
                            'Home site',
                            vehicle.home_site?.name ?? vehicle.site?.name,
                        ],
                        ['Primary driver', vehicle.primary_driver?.name],
                        [
                            'Driver licence',
                            driver
                                ? driver.licence_expires_at
                                    ? `${driver.licence_status ?? 'Recorded'} · expires ${formatDateOnly(driver.licence_expires_at)}`
                                    : (driver.licence_status ?? 'Recorded')
                                : vehicle.primary_driver
                                  ? 'No eligibility record visible to you'
                                  : null,
                        ],
                    ]}
                />
            </section>

            <section className="studio-card">
                <SectionHeading title="Accessibility">
                    {can.manage && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setDialog('accessibility')}
                        >
                            <Accessibility className="size-4" /> Edit
                        </Button>
                    )}
                </SectionHeading>
                <div className="flex flex-wrap gap-2">
                    {FEATURES.map((feature) => (
                        <StatusBadge
                            key={feature.key}
                            variant={
                                vehicle.accessibility[feature.key]
                                    ? 'success'
                                    : 'neutral'
                            }
                            label={`${feature.label}: ${vehicle.accessibility[feature.key] ? 'yes' : 'no'}`}
                        />
                    ))}
                </div>
                <p className="studio-footnote">
                    {vehicle.accessibility.accessibility_notes ||
                        'No accessibility notes recorded.'}
                </p>
            </section>

            {dialog === 'record' && (
                <RecordWizard
                    workspace={workspace}
                    onClose={() => setDialog(null)}
                    onSaved={onChanged}
                />
            )}
            {dialog === 'photo' && (
                <PhotoDialog
                    vehicle={vehicle}
                    onClose={() => setDialog(null)}
                    onSaved={onChanged}
                />
            )}
            {dialog === 'upload' && (
                <DocumentUploadDialog
                    workspace={workspace}
                    onClose={() => setDialog(null)}
                    onSaved={onChanged}
                />
            )}
            {dialog === 'placement' && (
                <PlacementDialog
                    vehicle={vehicle}
                    sites={sites}
                    drivers={drivers}
                    onClose={() => setDialog(null)}
                    onSaved={onChanged}
                />
            )}
            {dialog === 'accessibility' && (
                <AccessibilityDialog
                    vehicle={vehicle}
                    onClose={() => setDialog(null)}
                    onSaved={onChanged}
                />
            )}
        </div>
    );
}

const RECORD_STEPS = [
    {
        key: 'identity',
        label: 'Vehicle identity',
        blurb: 'Name, plate and specification',
        icon: Car,
    },
    {
        key: 'ownership',
        label: 'Ownership & responsibility',
        blurb: 'Arrangement, use and owner',
        icon: UserRound,
    },
    {
        key: 'cover',
        label: 'Cover & documents',
        blurb: 'Insurance and warranty',
        icon: FileText,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Reason and confirmation',
        icon: ShieldCheck,
    },
];

const STEP_FIELDS: string[][] = [
    [
        'name',
        'registration_number',
        'serial_number',
        'body_type',
        'manufacturer',
        'model',
        'fuel_type',
        'seating_capacity',
    ],
    [
        'ownership_arrangement',
        'fleet_responsible_user_id',
        'use_purpose',
        'purchase_date',
        'ownershipFiles',
    ],
    [
        'insurance_provider',
        'insurance_policy_reference',
        'insurance_expires_at',
        'warranty_reference',
        'warranty_expires_at',
        'insuranceFiles',
        'warrantyFiles',
    ],
    ['reason'],
];

type FileGroup = 'ownershipFiles' | 'insuranceFiles' | 'warrantyFiles';

function RecordWizard({
    workspace,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const [initial] = useState(() => ({
        name: vehicle.name ?? '',
        registration_number: vehicle.registration_number ?? '',
        serial_number: vehicle.serial_number ?? '',
        body_type: vehicle.body_type ?? '',
        manufacturer: vehicle.manufacturer ?? '',
        model: vehicle.model ?? '',
        fuel_type: vehicle.fuel_type ?? '',
        seating_capacity: vehicle.seating_capacity
            ? String(vehicle.seating_capacity)
            : '',
        ownership_arrangement: vehicle.ownership_arrangement ?? '',
        fleet_responsible_user_id: vehicle.responsible?.id ?? null,
        use_purpose: vehicle.use_purpose ?? '',
        purchase_date: vehicle.purchase_date ?? '',
        insurance_provider: vehicle.insurance_provider ?? '',
        insurance_policy_reference: vehicle.insurance_policy_reference ?? '',
        insurance_expires_at: vehicle.insurance_expires_at ?? '',
        warranty_reference: vehicle.warranty_reference ?? '',
        warranty_expires_at: vehicle.warranty_expires_at ?? '',
        reason: '',
    }));
    const [form, setForm] = useState(initial);
    const [files, setFiles] = useState<Record<FileGroup, File[]>>({
        ownershipFiles: [],
        insuranceFiles: [],
        warrantyFiles: [],
    });
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [savedText, setSavedText] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const documents = useVehicleRecordCommand(isJsonObject);
    const errors = { ...command.errors, ...localErrors };
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors((old) => {
            const next = { ...old };
            delete next[key as string];
            return next;
        });
        command.clearError(key as string);
    };
    const [seenErrors, setSeenErrors] = useState(command.errors);
    if (seenErrors !== command.errors) {
        // Show the step holding the first server error.
        setSeenErrors(command.errors);
        const field = Object.keys(command.errors)[0];
        const at = field
            ? STEP_FIELDS.findIndex((fields) => fields.includes(field))
            : -1;
        if (at >= 0) setStep(at);
    }
    const validateStep = (at: number): boolean => {
        const found: Record<string, string> = {};
        if (at === 0) {
            if (!form.name.trim())
                found.name = 'Enter the name people use for this vehicle.';
            if (!form.manufacturer.trim())
                found.manufacturer = 'Choose the manufacturer.';
            if (!form.model.trim()) found.model = 'Choose the vehicle model.';
            if (!form.fuel_type)
                found.fuel_type = 'Choose the fuel or energy type.';
            if (form.seating_capacity && !/^\d+$/.test(form.seating_capacity))
                found.seating_capacity = 'Enter seats as a whole number.';
        }
        if (at === 3 && !form.reason.trim())
            found.reason = 'Record the reason for this change.';
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };
    const changed = (
        Object.keys(initial) as Array<keyof typeof initial>
    ).filter((key) => key !== 'reason' && form[key] !== initial[key]);
    const staged = Object.values(files).reduce(
        (total, group) => total + group.length,
        0,
    );

    const submit = async () => {
        if (!command.uncertain) {
            for (const at of [0, 3]) {
                if (!validateStep(at)) {
                    setStep(at);
                    return;
                }
            }
        }
        const payload: Record<string, unknown> = {
            profile_version: vehicle.profile_version,
            reason: form.reason.trim(),
        };
        for (const key of changed) {
            const value = form[key];
            payload[key] =
                key === 'seating_capacity'
                    ? value
                        ? Number(value)
                        : null
                    : value === ''
                      ? null
                      : value;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}`,
            payload,
            { method: 'PUT' },
        );
        if (!result) return;
        // Files are saved as vehicle documents once the record itself is saved.
        const groups: Array<{
            group: FileGroup;
            category: string;
            reference: string;
            expires: string;
        }> = [
            {
                group: 'ownershipFiles',
                category: 'Ownership agreement',
                reference: '',
                expires: '',
            },
            {
                group: 'insuranceFiles',
                category: 'Insurance policy',
                reference: form.insurance_policy_reference,
                expires: form.insurance_expires_at,
            },
            {
                group: 'warrantyFiles',
                category: 'Warranty',
                reference: form.warranty_reference,
                expires: form.warranty_expires_at,
            },
        ];
        const failed: string[] = [];
        for (const { group, category, reference, expires } of groups) {
            if (!files[group].length) continue;
            const body = new FormData();
            files[group].forEach((file) => body.append('files[]', file));
            body.append('category', category);
            body.append('reference', reference);
            body.append('document_date', todayInAuckland());
            if (expires) body.append('expires_on', expires);
            body.append('reason', form.reason.trim());
            const saved = await documents.submit(
                `/fleet-assets/vehicles/${vehicle.id}/documents`,
                body,
            );
            if (!saved) failed.push(category.toLowerCase());
            documents.reset();
        }
        setSavedText(
            failed.length
                ? `The vehicle record is saved. The ${failed.join(' and ')} files were not saved; upload them from Documents.`
                : staged
                  ? 'The vehicle record is saved and its files are in Documents. Files open once they pass a virus check.'
                  : 'The vehicle record is saved with your reason in its history.',
        );
        onSaved();
    };
    const responsible = workspace.people.find(
        (person) => person.id === form.fleet_responsible_user_id,
    );
    const fileField = (group: FileGroup, label: string) => (
        <StagedFilesField
            label={label}
            files={files[group]}
            onChange={(next) => setFiles((old) => ({ ...old, [group]: next }))}
        />
    );

    return (
        <WorkspaceWizard
            title="Edit vehicle record"
            description={`${vehicle.name}: update identity, ownership and cover details. Your reason is kept in the vehicle history.`}
            railIcon={Car}
            railSub={vehicle.registration_number ?? vehicle.asset_tag ?? ''}
            steps={RECORD_STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([
                    !!form.name,
                    !!form.manufacturer,
                    !!form.model,
                    !!form.fuel_type,
                    !!form.reason.trim(),
                ].filter(Boolean).length /
                    5) *
                    100,
            )}
            context={{
                name: vehicle.name,
                detail: [vehicle.registration_number, vehicle.site?.name]
                    .filter(Boolean)
                    .join(' · '),
            }}
            command={{
                ...command,
                processing: command.processing || documents.processing,
                locked: command.locked || documents.processing,
            }}
            dirty={changed.length > 0 || staged > 0 || !!form.reason}
            saved={savedText !== null}
            submitLabel="Save vehicle record"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title="Vehicle record saved"
                    blurb={savedText ?? ''}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Choose reusable specifications. Unique identifiers stay
                        attached to this vehicle.
                    </p>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="name"
                            label="Display name"
                            error={errors.name}
                        >
                            <Input
                                {...fieldProps('name', errors.name)}
                                maxLength={255}
                                value={form.name}
                                onChange={(event) =>
                                    update('name', event.target.value)
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="registration_number"
                            label="Registration plate"
                            optional
                            error={errors.registration_number}
                        >
                            <Input
                                {...fieldProps(
                                    'registration_number',
                                    errors.registration_number,
                                )}
                                maxLength={50}
                                value={form.registration_number}
                                onChange={(event) =>
                                    update(
                                        'registration_number',
                                        event.target.value.toUpperCase(),
                                    )
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="serial_number"
                            label="VIN / chassis reference"
                            optional
                            error={errors.serial_number}
                        >
                            <Input
                                {...fieldProps(
                                    'serial_number',
                                    errors.serial_number,
                                )}
                                maxLength={255}
                                value={form.serial_number}
                                onChange={(event) =>
                                    update('serial_number', event.target.value)
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="body_type"
                            label="Vehicle category"
                            optional
                            error={errors.body_type}
                        >
                            <CataloguePicker
                                id="body_type"
                                kind="vehicle_body_type"
                                label="Vehicle category"
                                value={form.body_type}
                                onChange={(value) => update('body_type', value)}
                                optional
                            />
                        </WizardField>
                        <WizardField
                            id="manufacturer"
                            label="Manufacturer"
                            error={errors.manufacturer}
                        >
                            <CataloguePicker
                                id="manufacturer"
                                kind="vehicle_manufacturer"
                                label="Manufacturer"
                                value={form.manufacturer}
                                onChange={(value) =>
                                    update('manufacturer', value)
                                }
                                invalid={!!errors.manufacturer}
                            />
                        </WizardField>
                        <WizardField
                            id="model"
                            label="Vehicle model"
                            error={errors.model}
                        >
                            <CataloguePicker
                                id="model"
                                kind="vehicle_model"
                                label="Vehicle model"
                                value={form.model}
                                onChange={(value) => update('model', value)}
                                invalid={!!errors.model}
                            />
                        </WizardField>
                        <WizardField
                            id="fuel_type"
                            label="Fuel / energy type"
                            error={errors.fuel_type}
                        >
                            <Select
                                value={form.fuel_type}
                                onValueChange={(value) =>
                                    update('fuel_type', value)
                                }
                            >
                                <SelectTrigger
                                    {...fieldProps(
                                        'fuel_type',
                                        errors.fuel_type,
                                    )}
                                >
                                    <SelectValue placeholder="Choose fuel type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {FUEL_TYPES.map((fuel) => (
                                        <SelectItem
                                            key={fuel.value}
                                            value={fuel.value}
                                        >
                                            {fuel.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </WizardField>
                        <WizardField
                            id="seating_capacity"
                            label="Seats"
                            optional
                            error={errors.seating_capacity}
                        >
                            <CataloguePicker
                                id="seating_capacity"
                                kind="seats"
                                label="Seats"
                                value={form.seating_capacity}
                                onChange={(value) =>
                                    update('seating_capacity', value)
                                }
                                optional
                                invalid={!!errors.seating_capacity}
                            />
                        </WizardField>
                    </div>
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Operational ownership is separate from Finance
                        recognition and approval.
                    </p>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="ownership_arrangement"
                            label="Ownership arrangement"
                            optional
                            error={errors.ownership_arrangement}
                        >
                            <CataloguePicker
                                id="ownership_arrangement"
                                kind="ownership_arrangement"
                                label="Ownership arrangement"
                                value={form.ownership_arrangement}
                                onChange={(value) =>
                                    update('ownership_arrangement', value)
                                }
                                optional
                            />
                        </WizardField>
                        <WizardField
                            id="fleet_responsible_user_id"
                            label="Responsible person"
                            optional
                            error={errors.fleet_responsible_user_id}
                        >
                            <PersonPicker
                                id="fleet_responsible_user_id"
                                label="Responsible person"
                                value={form.fleet_responsible_user_id}
                                people={workspace.people}
                                onChange={(value) =>
                                    update('fleet_responsible_user_id', value)
                                }
                                invalid={!!errors.fleet_responsible_user_id}
                            />
                        </WizardField>
                        <WizardField
                            id="use_purpose"
                            label="Use"
                            optional
                            error={errors.use_purpose}
                        >
                            <CataloguePicker
                                id="use_purpose"
                                kind="vehicle_use_purpose"
                                label="Use"
                                value={form.use_purpose}
                                onChange={(value) =>
                                    update('use_purpose', value)
                                }
                                optional
                            />
                        </WizardField>
                        <WizardField
                            id="purchase_date"
                            label="Purchase / start date"
                            optional
                            error={errors.purchase_date}
                        >
                            <DatePicker
                                id="purchase_date"
                                label="Purchase / start date"
                                value={form.purchase_date}
                                onChange={(value) =>
                                    update('purchase_date', value)
                                }
                                invalid={!!errors.purchase_date}
                            />
                        </WizardField>
                    </div>
                    {workspace.can.manage_documents &&
                        fileField(
                            'ownershipFiles',
                            'Purchase / lease documents',
                        )}
                </div>
            )}
            {step === 2 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Attach policy and warranty evidence here; files appear
                        in Documents after saving. Registration, WoF, CoF and
                        RUC are recorded on Service &amp; compliance.
                    </p>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="insurance_provider"
                            label="Insurance provider"
                            optional
                            error={errors.insurance_provider}
                        >
                            <Input
                                {...fieldProps(
                                    'insurance_provider',
                                    errors.insurance_provider,
                                )}
                                maxLength={160}
                                value={form.insurance_provider}
                                onChange={(event) =>
                                    update(
                                        'insurance_provider',
                                        event.target.value,
                                    )
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="insurance_policy_reference"
                            label="Insurance policy reference"
                            optional
                            error={errors.insurance_policy_reference}
                        >
                            <Input
                                {...fieldProps(
                                    'insurance_policy_reference',
                                    errors.insurance_policy_reference,
                                )}
                                maxLength={120}
                                value={form.insurance_policy_reference}
                                onChange={(event) =>
                                    update(
                                        'insurance_policy_reference',
                                        event.target.value,
                                    )
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="insurance_expires_at"
                            label="Insurance expiry"
                            optional
                            error={errors.insurance_expires_at}
                        >
                            <DatePicker
                                id="insurance_expires_at"
                                label="Insurance expiry"
                                value={form.insurance_expires_at}
                                onChange={(value) =>
                                    update('insurance_expires_at', value)
                                }
                                invalid={!!errors.insurance_expires_at}
                            />
                        </WizardField>
                        <WizardField
                            id="warranty_reference"
                            label="Warranty reference"
                            optional
                            error={errors.warranty_reference}
                        >
                            <Input
                                {...fieldProps(
                                    'warranty_reference',
                                    errors.warranty_reference,
                                )}
                                maxLength={120}
                                value={form.warranty_reference}
                                onChange={(event) =>
                                    update(
                                        'warranty_reference',
                                        event.target.value,
                                    )
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="warranty_expires_at"
                            label="Warranty expiry"
                            optional
                            error={errors.warranty_expires_at}
                        >
                            <DatePicker
                                id="warranty_expires_at"
                                label="Warranty expiry"
                                value={form.warranty_expires_at}
                                onChange={(value) =>
                                    update('warranty_expires_at', value)
                                }
                                invalid={!!errors.warranty_expires_at}
                            />
                        </WizardField>
                    </div>
                    {workspace.can.manage_documents && (
                        <div className="vehicle-wizard-fields">
                            {fileField(
                                'insuranceFiles',
                                'Insurance policy files',
                            )}
                            {fileField('warrantyFiles', 'Warranty files')}
                        </div>
                    )}
                </div>
            )}
            {step === 3 && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={Car}
                        title="Vehicle identity"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Vehicle"
                            value={
                                [form.name, form.registration_number]
                                    .filter(Boolean)
                                    .join(' · ') || undefined
                            }
                        />
                        <ReviewRow
                            label="Specification"
                            value={
                                [form.manufacturer, form.model, form.body_type]
                                    .filter(Boolean)
                                    .join(' · ') || undefined
                            }
                        />
                        <ReviewRow
                            label="Fuel / seats"
                            value={
                                [
                                    fuelLabel(form.fuel_type),
                                    form.seating_capacity
                                        ? formatChoice(
                                              'seats',
                                              form.seating_capacity,
                                          )
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || undefined
                            }
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={UserRound}
                        title="Ownership & responsibility"
                        onEdit={() => setStep(1)}
                    >
                        <ReviewRow
                            label="Ownership"
                            value={form.ownership_arrangement || undefined}
                        />
                        <ReviewRow
                            label="Responsible person"
                            value={responsible?.name}
                        />
                        <ReviewRow
                            label="Use"
                            value={form.use_purpose || undefined}
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={FileText}
                        title="Cover & documents"
                        onEdit={() => setStep(2)}
                    >
                        <ReviewRow
                            label="Insurance"
                            value={
                                [
                                    form.insurance_provider,
                                    form.insurance_policy_reference,
                                    form.insurance_expires_at
                                        ? formatDateOnly(
                                              form.insurance_expires_at,
                                          )
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || undefined
                            }
                        />
                        <ReviewRow
                            label="Warranty"
                            value={
                                [
                                    form.warranty_reference,
                                    form.warranty_expires_at
                                        ? formatDateOnly(
                                              form.warranty_expires_at,
                                          )
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || undefined
                            }
                        />
                        <ReviewRow
                            label="Files to add"
                            value={
                                staged
                                    ? `${staged} ${staged === 1 ? 'file' : 'files'}`
                                    : 'None'
                            }
                        />
                    </ReviewCard>
                    <WizardField
                        id="reason"
                        label="Reason for change"
                        error={errors.reason}
                        hint={
                            changed.length
                                ? `${changed.length} ${changed.length === 1 ? 'field' : 'fields'} changed.`
                                : 'No field has changed yet.'
                        }
                    >
                        <Textarea
                            {...fieldProps('reason', errors.reason)}
                            rows={2}
                            maxLength={2000}
                            value={form.reason}
                            onChange={(event) =>
                                update('reason', event.target.value)
                            }
                        />
                    </WizardField>
                </div>
            )}
        </WorkspaceWizard>
    );
}

const NO_DRIVER = 'none';

function PlacementDialog({
    vehicle,
    sites,
    drivers,
    onClose,
    onSaved,
}: {
    vehicle: VehicleProfile;
    sites: Array<{ id: number; name: string }>;
    drivers: EligibleDriver[];
    onClose: () => void;
    onSaved: () => void;
}) {
    const homeId = vehicle.home_site?.id ?? vehicle.site?.id ?? null;
    const [siteId, setSiteId] = useState(homeId ? String(homeId) : '');
    const [driverId, setDriverId] = useState(
        vehicle.primary_driver ? String(vehicle.primary_driver.id) : NO_DRIVER,
    );
    const [reason, setReason] = useState('');
    const [reasonError, setReasonError] = useState('');
    const command = useVehicleRecordCommand(isJsonObject);
    const today = todayInAuckland();
    // Placement guards report against the underlying site or client placement.
    const placementError =
        command.errors.home_site_id ??
        command.errors.site_id ??
        command.errors.client_id;
    const currentDriverListed =
        !vehicle.primary_driver ||
        drivers.some((driver) => driver.id === vehicle.primary_driver?.id);
    const save = async () => {
        if (!reason.trim()) {
            setReasonError('Record the reason for this change.');
            return;
        }
        const payload: Record<string, unknown> = {
            profile_version: vehicle.profile_version,
            reason: reason.trim(),
        };
        if (siteId && Number(siteId) !== homeId)
            payload.home_site_id = Number(siteId);
        const nextDriver = driverId === NO_DRIVER ? null : Number(driverId);
        if (nextDriver !== (vehicle.primary_driver?.id ?? null))
            payload.primary_driver_user_id = nextDriver;
        if (
            await command.submit(
                `/fleet-assets/vehicles/${vehicle.id}`,
                payload,
                { method: 'PUT' },
            )
        ) {
            onSaved();
            onClose();
        }
    };

    return (
        <RecordDialog
            title="Change placement & driver"
            description="The home site sets where this vehicle is managed. Only drivers with a current licence can be assigned."
            command={command}
            submitLabel="Save placement"
            onSubmit={save}
            onClose={onClose}
        >
            <WizardField
                id="home_site_id"
                label="Home site"
                error={placementError}
            >
                <Select value={siteId} onValueChange={setSiteId}>
                    <SelectTrigger
                        {...fieldProps('home_site_id', placementError)}
                    >
                        <SelectValue placeholder="Choose site" />
                    </SelectTrigger>
                    <SelectContent>
                        {sites.map((site) => (
                            <SelectItem key={site.id} value={String(site.id)}>
                                {site.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </WizardField>
            <WizardField
                id="primary_driver_user_id"
                label="Primary driver"
                optional
                error={command.errors.primary_driver_user_id}
                hint={
                    drivers.length
                        ? undefined
                        : 'No eligible drivers are available to assign yet.'
                }
            >
                <Select value={driverId} onValueChange={setDriverId}>
                    <SelectTrigger
                        {...fieldProps(
                            'primary_driver_user_id',
                            command.errors.primary_driver_user_id,
                        )}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NO_DRIVER}>
                            No primary driver
                        </SelectItem>
                        {!currentDriverListed && vehicle.primary_driver && (
                            <SelectItem
                                value={String(vehicle.primary_driver.id)}
                                disabled
                            >
                                {vehicle.primary_driver.name} (current)
                            </SelectItem>
                        )}
                        {drivers.map((driver) => {
                            const expired =
                                !!driver.licence_expires_at &&
                                driver.licence_expires_at < today;
                            return (
                                <SelectItem
                                    key={driver.id}
                                    value={String(driver.id)}
                                    disabled={expired}
                                >
                                    {driver.name}
                                    {expired ? ' (licence expired)' : ''}
                                </SelectItem>
                            );
                        })}
                    </SelectContent>
                </Select>
            </WizardField>
            <WizardField
                id="placement-reason"
                label="Reason for change"
                error={reasonError || command.errors.reason}
            >
                <Textarea
                    {...fieldProps(
                        'placement-reason',
                        reasonError || command.errors.reason,
                    )}
                    rows={2}
                    maxLength={2000}
                    value={reason}
                    onChange={(event) => {
                        setReason(event.target.value);
                        setReasonError('');
                    }}
                />
            </WizardField>
        </RecordDialog>
    );
}

function AccessibilityDialog({
    vehicle,
    onClose,
    onSaved,
}: {
    vehicle: VehicleProfile;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [features, setFeatures] = useState<Record<FeatureKey, boolean>>({
        has_wheelchair_ramp: vehicle.accessibility.has_wheelchair_ramp,
        has_hoist: vehicle.accessibility.has_hoist,
        has_child_seat_anchors: vehicle.accessibility.has_child_seat_anchors,
        has_medical_storage: vehicle.accessibility.has_medical_storage,
    });
    const [notes, setNotes] = useState(
        vehicle.accessibility.accessibility_notes ?? '',
    );
    const [reason, setReason] = useState('');
    const [reasonError, setReasonError] = useState('');
    const command = useVehicleRecordCommand(isJsonObject);
    const save = async () => {
        if (!reason.trim()) {
            setReasonError('Record the reason for this change.');
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}`,
            {
                ...features,
                accessibility_notes: notes.trim() || null,
                profile_version: vehicle.profile_version,
                reason: reason.trim(),
            },
            { method: 'PUT' },
        );
        if (result) {
            onSaved();
            onClose();
        }
    };

    return (
        <RecordDialog
            title="Edit accessibility"
            description="Booking and trip planning use these features to match people with the right vehicle."
            command={command}
            submitLabel="Save accessibility"
            onSubmit={save}
            onClose={onClose}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                {FEATURES.map((feature) => (
                    <label
                        key={feature.key}
                        className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm font-medium"
                    >
                        {feature.label}
                        <Switch
                            checked={features[feature.key]}
                            onCheckedChange={(value) =>
                                setFeatures((old) => ({
                                    ...old,
                                    [feature.key]: value,
                                }))
                            }
                            aria-label={feature.label}
                        />
                    </label>
                ))}
            </div>
            <WizardField
                id="accessibility_notes"
                label="Accessibility notes"
                optional
                error={command.errors.accessibility_notes}
            >
                <Textarea
                    {...fieldProps(
                        'accessibility_notes',
                        command.errors.accessibility_notes,
                    )}
                    rows={3}
                    maxLength={5000}
                    value={notes}
                    onChange={(event) => setNotes(event.target.value)}
                />
            </WizardField>
            <WizardField
                id="accessibility-reason"
                label="Reason for change"
                error={reasonError || command.errors.reason}
            >
                <Textarea
                    {...fieldProps(
                        'accessibility-reason',
                        reasonError || command.errors.reason,
                    )}
                    rows={2}
                    maxLength={2000}
                    value={reason}
                    onChange={(event) => {
                        setReason(event.target.value);
                        setReasonError('');
                    }}
                />
            </WizardField>
        </RecordDialog>
    );
}

export function PhotoDialog({
    vehicle,
    onClose,
    onSaved,
}: {
    vehicle: VehicleProfile;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [files, setFiles] = useState<File[]>([]);
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const remove = useVehicleRecordCommand(isJsonObject);
    const save = async () => {
        if (!files[0]) {
            setError('Choose a PNG or JPEG photo.');
            return;
        }
        const body = new FormData();
        body.append('photo', files[0]);
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/photo`,
            body,
        );
        if (!result) return;
        onSaved();
        const file = isJsonObject(result.file) ? result.file : null;
        if (file && file.state !== 'available') setPending(true);
        else onClose();
    };
    const removePhoto = async () => {
        if (
            await remove.submit(
                `/fleet-assets/vehicles/${vehicle.id}/photo`,
                {},
                { method: 'DELETE' },
            )
        ) {
            onSaved();
            onClose();
        }
    };

    return (
        <RecordDialog
            title="Vehicle profile photo"
            description="The photo helps people recognise the vehicle. It is stored privately with the vehicle record."
            command={{
                processing: command.processing || remove.processing,
                uncertain: command.uncertain,
                requiresReload: command.requiresReload || remove.requiresReload,
                locked: command.locked || remove.locked,
                message: command.message || remove.message,
            }}
            submitLabel={pending ? 'Done' : 'Use profile photo'}
            onSubmit={pending ? onClose : save}
            onClose={onClose}
            destructive={
                vehicle.photo_url && !pending ? (
                    <Button
                        variant="ghost"
                        disabled={remove.processing || command.processing}
                        onClick={removePhoto}
                    >
                        Remove photo
                    </Button>
                ) : undefined
            }
        >
            {pending ? (
                <p role="status" className="text-sm">
                    The photo is stored privately and will show once it passes a
                    virus check. Your current photo stays in place until then.
                </p>
            ) : (
                <StagedFilesField
                    label="Photo"
                    files={files}
                    onChange={(next) => {
                        setFiles(next);
                        setError('');
                    }}
                    error={error || command.errors.photo}
                    imagesOnly
                    multiple={false}
                    optional={false}
                />
            )}
        </RecordDialog>
    );
}
