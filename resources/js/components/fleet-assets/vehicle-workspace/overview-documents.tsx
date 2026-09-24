import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import {
    formatDateOnly,
    formatDateTime,
    toDateInput,
    toDatetimeLocal,
} from '@/lib/datetime';
import {
    Archive,
    ArrowUpRight,
    Bell,
    CalendarClock,
    FileText,
    FolderOpen,
    Loader2,
    Lock,
    Pencil,
    RefreshCw,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import { CataloguePicker, PersonPicker } from './choice-picker';
import { vehicleReference } from './finance-shared';
import {
    useVehicleCollectionView,
    VehicleCollectionToggle,
    VehicleRecordCollection,
} from './record-collection';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { SourceRecordDialog } from './studio-kit';
import type { DocumentFile, DocumentSet, VehicleWorkspace } from './types';
import {
    fieldProps,
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

const REQUIRED = [
    {
        category: 'Insurance policy',
        search: 'Insurance policy',
        matches: ['insurance policy'],
        profileDate: 'insurance_expires_at' as const,
    },
    {
        category: 'Warranty',
        search: 'Warranty',
        matches: ['warranty'],
        profileDate: 'warranty_expires_at' as const,
    },
    {
        category: 'Ownership agreement',
        search: 'agreement',
        matches: [
            'ownership agreement',
            'purchase agreement',
            'lease agreement',
        ],
        profileDate: null,
    },
];

export function FileLink({ file }: { file: DocumentFile }) {
    const state = file.state ? FILE_STATE_LABELS[file.state] : null;
    return (
        <span className="flex flex-wrap items-center gap-2">
            {file.url ? (
                <a
                    href={file.url}
                    target="_blank"
                    rel="noreferrer"
                    className="text-primary underline-offset-2 hover:underline"
                >
                    {file.name}
                </a>
            ) : (
                <span className="text-muted-foreground">{file.name}</span>
            )}
            {state && file.state !== 'available' && (
                <StatusBadge
                    size="sm"
                    variant={state.variant}
                    label={state.label}
                />
            )}
            {file.archived_at && (
                <StatusBadge size="sm" variant="neutral" label="Archived" />
            )}
            {!file.current && !file.archived_at && (
                <StatusBadge size="sm" variant="neutral" label="Superseded" />
            )}
        </span>
    );
}

type Mode =
    | { kind: 'create'; category?: string }
    | { kind: 'replace'; set: DocumentSet }
    | { kind: 'edit'; set: DocumentSet; step?: number };

/** Where "Open source" goes for files kept with another vehicle record. */
const SOURCE_LOCATIONS: Record<string, WorkspaceLocation> = {
    compliance_version: { tab: 'service', view: 'evidence' },
    odometer_observation: { tab: 'service', view: 'mileage' },
    service_completion: { tab: 'service', view: 'history' },
    service_schedule: { tab: 'service', view: 'schedules' },
    unavailable_period: { tab: 'calendar' },
    checklist_run: { tab: 'checks', view: 'recent' },
};

const RENEWAL_STATES: Record<string, string> = {
    scheduled: 'Renewal reminder',
    acknowledged: 'Reminder acknowledged',
    paused: 'Reminder paused',
};

type LibraryEntry = {
    set: DocumentSet;
    /** The record the files are kept with; null for the vehicle's own documents. */
    source: string | null;
    location: WorkspaceLocation | null;
};

export function DocumentsPanel({
    workspace,
    onNavigate,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    onNavigate: (location: WorkspaceLocation) => void;
    onChanged: () => void;
}) {
    const { view, setView } = useVehicleCollectionView('documents');
    const [query, setQuery] = useState('');
    const [scope, setScope] = useState<'current' | 'all'>('current');
    const [mode, setMode] = useState<Mode | null>(null);
    const [archiving, setArchiving] = useState<DocumentSet | null>(null);
    const [viewing, setViewing] = useState<LibraryEntry | null>(null);
    const retry = useVehicleRecordCommand(isJsonObject);
    const { can, vehicle, documents, linked_documents: linked } = workspace;

    if (!can.view_documents) {
        return (
            <Card>
                <CardContent className="p-5">
                    <EmptyState
                        icon={Lock}
                        title="Document access required"
                        description="Vehicle documents follow the asset register's access. Ask a coordinator if you need to see them."
                    />
                </CardContent>
            </Card>
        );
    }

    const reference = vehicleReference(vehicle);
    const today = todayInAuckland();
    const library: LibraryEntry[] = [
        ...documents.map((set) => ({ set, source: null, location: null })),
        ...linked.map((set) => ({
            set,
            source: set.source.label,
            location: SOURCE_LOCATIONS[set.source.type] ?? null,
        })),
    ];
    const needle = query.trim().toLowerCase();
    const visible = library.filter(({ set, source }) => {
        if (scope === 'current' && !set.files.some((file) => file.current))
            return false;
        if (!needle) return true;
        return [
            set.category,
            set.reference,
            source,
            ...set.files.map((file) => file.name),
        ]
            .filter(Boolean)
            .some((text) => String(text).toLowerCase().includes(needle));
    });
    const reminders = () => onNavigate({ tab: 'service', view: 'reminders' });
    const retryFile = async (file: DocumentFile) => {
        const url =
            file.state === 'legacy_unverified'
                ? `/fleet-assets/vehicles/${vehicle.id}/document-files/${file.id}/verify`
                : `/fleet-assets/vehicles/${vehicle.id}/document-files/${file.id}/retry`;
        // A failed retry keeps its message visible; a success reloads the file state.
        if (await retry.submit(url, {})) {
            retry.reset();
            onChanged();
        }
    };

    return (
        <div className="studio-page record-workspace">
            <div className="studio-section-heading">
                <div>
                    <span className="studio-eyebrow">
                        Vehicle record · {reference}
                    </span>
                    <h2>Documents &amp; renewal evidence</h2>
                    <p>
                        Find the original, replace a version, or follow its
                        source.
                    </p>
                </div>
                <VehicleCollectionToggle
                    label="Documents"
                    view={view}
                    onChange={setView}
                />
                {can.manage_documents && (
                    <Button onClick={() => setMode({ kind: 'create' })}>
                        <Upload className="size-4" />
                        Upload documents
                    </Button>
                )}
            </div>

            <VehicleRecordCollection
                label="Required documents"
                view={view}
                columns={[
                    { label: 'File status' },
                    { label: 'Renewal / expiry', width: '1.5fr' },
                    { label: 'Evidence' },
                ]}
                empty={{ title: 'No required documents' }}
                records={REQUIRED.map((required) => {
                    const sets = documents.filter((set) =>
                        required.matches.includes(set.category.toLowerCase()),
                    );
                    const current = sets.flatMap((set) =>
                        set.files.filter((file) => file.current),
                    ).length;
                    const expiry =
                        sets
                            .filter((set) =>
                                set.files.some((file) => file.current),
                            )
                            .map((set) => set.expires_on)
                            .filter(Boolean)
                            .sort()[0] ?? null;
                    const profileDate = required.profileDate
                        ? vehicle[required.profileDate]
                        : null;
                    const find = () => setQuery(required.search);
                    const upload = () =>
                        setMode({
                            kind: 'create',
                            category: required.category,
                        });
                    return {
                        id: required.category,
                        name: required.category,
                        icon: FileText,
                        tone: current ? undefined : 'warning',
                        fields: [
                            <StatusBadge
                                key="status"
                                variant={current ? 'success' : 'warning'}
                                label={
                                    current
                                        ? `${current} ${current === 1 ? 'file' : 'files'}`
                                        : 'File missing'
                                }
                            />,
                            <Fragment key="expiry">
                                <span>
                                    {expiry
                                        ? `Document expiry ${formatDateOnly(expiry)}`
                                        : profileDate
                                          ? `Profile renewal ${formatDateOnly(profileDate)}`
                                          : 'No expiry recorded'}
                                </span>
                                {expiry &&
                                    profileDate &&
                                    expiry !== profileDate && (
                                        <StatusBadge
                                            size="sm"
                                            variant="warning"
                                            label="Profile date differs · review evidence"
                                        />
                                    )}
                            </Fragment>,
                            <div key="evidence" className="collection-actions">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={find}
                                >
                                    Find files
                                </Button>
                                {can.manage_documents && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={upload}
                                    >
                                        Upload
                                    </Button>
                                )}
                            </div>,
                        ],
                        onOpen: find,
                        footer: {
                            primary: current
                                ? 'Supporting files available'
                                : 'Evidence needed',
                            secondary:
                                'Profile references are not uploaded files',
                        },
                        actions: [
                            {
                                label: 'Find matching files',
                                icon: FolderOpen,
                                onClick: find,
                            },
                            ...(can.manage_documents
                                ? [
                                      {
                                          label: 'Upload document',
                                          icon: Upload,
                                          onClick: upload,
                                      },
                                  ]
                                : []),
                            {
                                label: 'Renewal reminders',
                                icon: CalendarClock,
                                onClick: reminders,
                            },
                        ],
                    };
                })}
            />

            <div className="record-filterbar">
                <Input
                    aria-label="Search vehicle documents"
                    placeholder="Search file, type, reference or source…"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                />
                <Select
                    value={scope}
                    onValueChange={(value) =>
                        setScope(value as 'current' | 'all')
                    }
                >
                    <SelectTrigger
                        className="w-36"
                        aria-label="Document versions"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="current">Current</SelectItem>
                        <SelectItem value="all">All history</SelectItem>
                    </SelectContent>
                </Select>
                <Button variant="outline" onClick={reminders}>
                    <CalendarClock className="size-4" />
                    Renewal reminders
                </Button>
            </div>
            {retry.message && (
                <p role="alert" className="text-sm text-status-warning">
                    {retry.message}
                </p>
            )}

            {visible.length ? (
                <VehicleRecordCollection
                    label="Document library"
                    view={view}
                    columns={[
                        { label: 'Version / expiry' },
                        { label: 'Source & reference', width: '1.2fr' },
                        { label: 'File actions', width: '1.5fr' },
                    ]}
                    empty={{ title: 'No matching documents' }}
                    records={visible.map((entry) => {
                        const { set, source, location } = entry;
                        const current = set.files.some((file) => file.current);
                        const expired =
                            current &&
                            !!set.expires_on &&
                            set.expires_on < today;
                        const files =
                            scope === 'current'
                                ? set.files.filter((file) => file.current)
                                : set.files;
                        const own = source === null;
                        const manage = can.manage_documents && own && current;
                        const renewalDay = set.renewal?.due_at
                            ? toDateInput(set.renewal.due_at)
                            : null;
                        const retryable = set.files
                            .filter(
                                (file) =>
                                    file.current || file.state !== 'available',
                            )
                            .filter((file) =>
                                [
                                    'scan_unavailable',
                                    'publication_failed',
                                    'stored',
                                    'reserved',
                                    'legacy_unverified',
                                ].includes(file.state ?? ''),
                            );
                        const openable = files.filter((file) => file.url);
                        return {
                            id: `${own ? 'vehicle' : 'linked'}-${set.id}`,
                            name: set.category,
                            subline: source ?? 'Vehicle document',
                            icon: FileText,
                            tone: expired ? 'critical' : undefined,
                            fields: [
                                <Fragment key="version">
                                    <StatusBadge
                                        size="sm"
                                        variant={
                                            expired
                                                ? 'critical'
                                                : current
                                                  ? 'info'
                                                  : 'neutral'
                                        }
                                        label={
                                            expired
                                                ? 'Expired'
                                                : current
                                                  ? 'Current'
                                                  : 'Archived'
                                        }
                                    />
                                    <small>
                                        {set.expires_on
                                            ? `Expires ${formatDateOnly(set.expires_on)}`
                                            : 'No expiry recorded'}
                                    </small>
                                    {current && set.renewal && renewalDay && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                onNavigate({
                                                    tab: 'calendar',
                                                    date: renewalDay,
                                                })
                                            }
                                        >
                                            <CalendarClock className="size-3.5" />
                                            {RENEWAL_STATES[
                                                set.renewal.state
                                            ] ?? 'Renewal reminder'}{' '}
                                            · {formatDateOnly(renewalDay)}
                                        </Button>
                                    )}
                                    {set.current_revision > 1 && (
                                        <small>
                                            Version {set.current_revision}
                                        </small>
                                    )}
                                </Fragment>,
                                <Fragment key="source">
                                    <strong>{source ?? reference}</strong>
                                    <small>
                                        {[
                                            set.reference,
                                            set.document_date
                                                ? `Dated ${formatDateOnly(set.document_date)}`
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') || 'No reference'}
                                    </small>
                                    {set.legacy && (
                                        <small>
                                            Uploaded before virus checking
                                        </small>
                                    )}
                                </Fragment>,
                                <div
                                    key="actions"
                                    className="collection-actions"
                                >
                                    {files.map((file) => (
                                        <FileLink key={file.id} file={file} />
                                    ))}
                                    {location && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => onNavigate(location)}
                                        >
                                            Open source
                                        </Button>
                                    )}
                                    {manage && (
                                        <>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setMode({
                                                        kind: 'edit',
                                                        set,
                                                    })
                                                }
                                            >
                                                <Pencil className="size-3.5" />
                                                Edit details &amp; expiry
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setMode({
                                                        kind: 'replace',
                                                        set,
                                                    })
                                                }
                                            >
                                                Replace
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setArchiving(set)
                                                }
                                            >
                                                Archive
                                            </Button>
                                        </>
                                    )}
                                </div>,
                            ],
                            onOpen: () => setViewing(entry),
                            footer: {
                                primary: source ?? 'Vehicle record',
                                secondary:
                                    'Source document · original retained',
                            },
                            actions: [
                                {
                                    label: 'View document details',
                                    icon: FileText,
                                    onClick: () => setViewing(entry),
                                },
                                ...openable.map((file) => ({
                                    label:
                                        openable.length > 1
                                            ? `Open ${file.name}`
                                            : 'Open file',
                                    icon: FolderOpen,
                                    onClick: () => {
                                        window.open(
                                            file.url!,
                                            '_blank',
                                            'noopener,noreferrer',
                                        );
                                    },
                                })),
                                ...(location
                                    ? [
                                          {
                                              label: 'Open source',
                                              icon: ArrowUpRight,
                                              onClick: () =>
                                                  onNavigate(location),
                                          },
                                      ]
                                    : []),
                                ...(manage
                                    ? [
                                          {
                                              label: 'Edit details & expiry',
                                              icon: Pencil,
                                              onClick: () =>
                                                  setMode({
                                                      kind: 'edit',
                                                      set,
                                                  }),
                                          },
                                          {
                                              label: set.renewal
                                                  ? 'Manage renewal reminder'
                                                  : 'Add renewal reminder',
                                              icon: Bell,
                                              onClick: () =>
                                                  setMode({
                                                      kind: 'edit',
                                                      set,
                                                      step: 1,
                                                  }),
                                          },
                                          ...(renewalDay
                                              ? [
                                                    {
                                                        label: 'View renewal in calendar',
                                                        icon: CalendarClock,
                                                        onClick: () =>
                                                            onNavigate({
                                                                tab: 'calendar',
                                                                date: renewalDay,
                                                            }),
                                                    },
                                                ]
                                              : []),
                                          {
                                              label: 'Replace document',
                                              icon: Upload,
                                              onClick: () =>
                                                  setMode({
                                                      kind: 'replace',
                                                      set,
                                                  }),
                                          },
                                          {
                                              label: 'Archive document',
                                              icon: Archive,
                                              onClick: () => setArchiving(set),
                                          },
                                      ]
                                    : []),
                                ...(can.manage_documents && own
                                    ? retryable.map((file) => ({
                                          label:
                                              file.state === 'legacy_unverified'
                                                  ? `Check ${file.name} now`
                                                  : `Retry ${file.name}`,
                                          icon: RefreshCw,
                                          onClick: () => retryFile(file),
                                      }))
                                    : []),
                            ],
                        };
                    })}
                />
            ) : (
                <section className="studio-card studio-empty document-empty">
                    <FolderOpen size={34} aria-hidden />
                    <h3>
                        {needle
                            ? 'No matching documents'
                            : 'Add the evidence behind this record'}
                    </h3>
                    <p>
                        {needle
                            ? 'Try another file name, category or reference.'
                            : 'Upload policy, purchase, lease, warranty and vehicle documents. Existing references are not uploaded files.'}
                    </p>
                    {needle ? (
                        <Button variant="outline" onClick={() => setQuery('')}>
                            Clear search
                        </Button>
                    ) : (
                        can.manage_documents && (
                            <Button
                                variant="outline"
                                onClick={() => setMode({ kind: 'create' })}
                            >
                                <Upload className="size-4" />
                                Upload first document
                            </Button>
                        )
                    )}
                </section>
            )}
            <p className="studio-footnote">
                Archived and replaced files stay in All history. Saving a
                document doesn&apos;t certify compliance or release the vehicle;
                registration, WoF, CoF and RUC are recorded under Service &amp;
                compliance.
            </p>
            {mode && (
                <DocumentDialog
                    workspace={workspace}
                    mode={mode}
                    onClose={() => setMode(null)}
                    onSaved={onChanged}
                />
            )}
            {archiving && (
                <ArchiveDialog
                    vehicleId={vehicle.id}
                    set={archiving}
                    onClose={() => setArchiving(null)}
                    onSaved={onChanged}
                />
            )}
            {viewing && (
                <SourceRecordDialog
                    title={viewing.set.category}
                    description={viewing.source ?? 'Vehicle document'}
                    rows={[
                        ['Category', viewing.set.category],
                        ['Reference', viewing.set.reference ?? 'Not recorded'],
                        ['Source', viewing.source ?? reference],
                        [
                            'Document date',
                            viewing.set.document_date
                                ? formatDateOnly(viewing.set.document_date)
                                : 'Not recorded',
                        ],
                        [
                            'Expiry',
                            viewing.set.expires_on
                                ? formatDateOnly(viewing.set.expires_on)
                                : 'Not recorded',
                        ],
                        [
                            'Version',
                            viewing.set.current_revision > 1
                                ? `Version ${viewing.set.current_revision}`
                                : 'First version',
                        ],
                        [
                            'Files',
                            viewing.set.files
                                .map(
                                    (file) =>
                                        `${file.name}${file.current ? '' : file.archived_at ? ' (archived)' : ' (replaced)'}`,
                                )
                                .join(', ') || 'None',
                        ],
                        [
                            'Renewal reminder',
                            viewing.set.renewal?.due_at
                                ? `${RENEWAL_STATES[viewing.set.renewal.state] ?? 'Renewal reminder'} · ${formatDateTime(viewing.set.renewal.due_at)} · ${viewing.set.renewal.owner ?? 'No owner'}`
                                : 'Not scheduled',
                        ],
                    ]}
                    onClose={() => setViewing(null)}
                />
            )}
        </div>
    );
}

function suggestReminder(expiry: string): string {
    const [year, month, day] = expiry.split('-').map(Number);
    const suggested = new Date(Date.UTC(year, month - 1, day - 30))
        .toISOString()
        .slice(0, 10);
    const tomorrow = toDatetimeLocal(
        new Date(Date.now() + 86_400_000).toISOString(),
    ).slice(0, 10);
    return `${suggested > tomorrow ? suggested : tomorrow}T09:00`;
}

const STEPS = [
    {
        key: 'details',
        label: 'Document details',
        blurb: 'Type, reference and date',
        icon: FileText,
    },
    {
        key: 'files',
        label: 'Files & follow-up',
        blurb: 'Expiry, reminder and files',
        icon: Bell,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the document',
        icon: ShieldCheck,
    },
];

/** The document upload wizard, for other views that add a vehicle document. */
export function DocumentUploadDialog({
    workspace,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    onClose: () => void;
    onSaved: () => void;
}) {
    return (
        <DocumentDialog
            workspace={workspace}
            mode={{ kind: 'create' }}
            onClose={onClose}
            onSaved={onSaved}
        />
    );
}

/**
 * Edit a document's details and renewal plan from elsewhere (the calendar's
 * renewal reminders): the document owns its renewal reminder.
 */
export function DocumentEditDialog({
    workspace,
    set,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    set: DocumentSet;
    onClose: () => void;
    onSaved: () => void;
}) {
    return (
        <DocumentDialog
            workspace={workspace}
            mode={{ kind: 'edit', set, step: 1 }}
            onClose={onClose}
            onSaved={onSaved}
        />
    );
}

function DocumentDialog({
    workspace,
    mode,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    mode: Mode;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const set = mode.kind === 'create' ? null : mode.set;
    const [initial] = useState(() => ({
        category:
            set?.category ??
            (mode.kind === 'create' ? (mode.category ?? '') : ''),
        reference:
            set?.reference ??
            (mode.kind === 'create' && mode.category === 'Insurance policy'
                ? (vehicle.insurance_policy_reference ?? '')
                : mode.kind === 'create' && mode.category === 'Warranty'
                  ? (vehicle.warranty_reference ?? '')
                  : ''),
        document_date: set?.document_date ?? todayInAuckland(),
        expires_on:
            set?.expires_on ??
            (mode.kind === 'create' && mode.category === 'Insurance policy'
                ? (vehicle.insurance_expires_at ?? '')
                : mode.kind === 'create' && mode.category === 'Warranty'
                  ? (vehicle.warranty_expires_at ?? '')
                  : ''),
        reminder: set?.renewal
            ? set.renewal.state !== 'paused'
            : mode.kind === 'edit' && mode.step === 1,
        remind_local: set?.renewal?.due_at
            ? toDatetimeLocal(set.renewal.due_at)
            : '',
        owner_user_id:
            set?.renewal?.owner_user_id ?? vehicle.responsible?.id ?? null,
        backup_user_id: set?.renewal?.backup_user_id ?? null,
        reason: '',
    }));
    const [form, setForm] = useState(initial);
    const [files, setFiles] = useState<File[]>([]);
    const [step, setStep] = useState(
        mode.kind === 'edit'
            ? (mode.step ?? 0)
            : mode.kind === 'replace'
              ? 1
              : 0,
    );
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [savedText, setSavedText] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const errors = { ...command.errors, ...localErrors };
    const withFiles = mode.kind !== 'edit';
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => {
            const next = { ...old, [key]: value };
            // A new expiry moves the suggested reminder unless one was chosen.
            if (
                key === 'expires_on' &&
                value &&
                (!old.remind_local ||
                    old.remind_local ===
                        (old.expires_on ? suggestReminder(old.expires_on) : ''))
            )
                next.remind_local = suggestReminder(String(value));
            if (
                key === 'reminder' &&
                value &&
                !old.remind_local &&
                old.expires_on
            )
                next.remind_local = suggestReminder(old.expires_on);
            return next;
        });
        // Reminder fields report under "reminder."; a new expiry or switch
        // also settles the suggested reminder time.
        const cleared = [
            key as string,
            `reminder.${key as string}`,
            ...(key === 'expires_on' || key === 'reminder'
                ? ['reminder.remind_local']
                : []),
        ];
        setLocalErrors((old) => {
            const next = { ...old };
            cleared.forEach((field) => delete next[field]);
            return next;
        });
        cleared.forEach((field) => command.clearError(field));
    };
    useEffect(() => {
        const field = Object.keys(command.errors)[0];
        if (field)
            setStep(
                ['category', 'reference', 'document_date'].includes(field)
                    ? 0
                    : 1,
            );
    }, [command.errors]);

    const validateStep = (at: number): boolean => {
        const found: Record<string, string> = {};
        if (at === 0 && mode.kind !== 'replace') {
            if (!form.category.trim())
                found.category = 'Choose the document type.';
            if (!form.document_date)
                found.document_date = 'Choose the document date.';
        }
        if (at === 1) {
            if (
                form.expires_on &&
                form.document_date &&
                form.expires_on < form.document_date
            )
                found.expires_on =
                    'Expiry must be on or after the document date.';
            if (mode.kind !== 'replace' && form.reminder) {
                if (!form.expires_on)
                    found.expires_on =
                        'Add an expiry date here before scheduling its reminder.';
                if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(form.remind_local))
                    found['reminder.remind_local'] =
                        'Choose a future reminder date and time.';
                else if (
                    form.expires_on &&
                    form.remind_local.slice(0, 10) > form.expires_on
                )
                    found['reminder.remind_local'] =
                        'The renewal reminder must be on or before expiry. For an expired document, add a follow-up from Reminders.';
                if (!form.owner_user_id)
                    found['reminder.owner_user_id'] =
                        'Choose the reminder owner.';
            }
            if (withFiles && files.length === 0)
                found.files = 'Attach at least one file.';
            if (!form.reason.trim())
                found.reason = 'Record the reason or supporting details.';
        }
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };

    const submit = async () => {
        if (!command.uncertain) {
            for (const at of mode.kind === 'replace' ? [1] : [0, 1]) {
                if (!validateStep(at)) {
                    setStep(at);
                    return;
                }
            }
        }
        const reminder = form.reminder
            ? {
                  enabled: true,
                  remind_local: form.remind_local,
                  owner_user_id: form.owner_user_id,
                  backup_user_id: form.backup_user_id,
              }
            : { enabled: false };
        let result: Record<string, unknown> | null = null;
        if (mode.kind === 'edit') {
            result = await command.submit(
                `/fleet-assets/vehicles/${vehicle.id}/documents/${mode.set.id}`,
                {
                    category: form.category,
                    reference: form.reference || null,
                    document_date: form.document_date,
                    expires_on: form.expires_on || null,
                    reason: form.reason.trim(),
                    reminder,
                    expected_version: mode.set.lock_version,
                },
                { method: 'PUT' },
            );
        } else {
            const body = new FormData();
            files.forEach((file) => body.append('files[]', file));
            body.append('reason', form.reason.trim());
            if (mode.kind === 'replace') {
                body.append('expected_version', String(mode.set.lock_version));
                result = await command.submit(
                    `/fleet-assets/vehicles/${vehicle.id}/documents/${mode.set.id}/revisions`,
                    body,
                );
            } else {
                body.append('category', form.category);
                body.append('reference', form.reference);
                body.append('document_date', form.document_date);
                if (form.expires_on) body.append('expires_on', form.expires_on);
                if (form.reminder) {
                    body.append('reminder[enabled]', '1');
                    body.append('reminder[remind_local]', form.remind_local);
                    if (form.owner_user_id)
                        body.append(
                            'reminder[owner_user_id]',
                            String(form.owner_user_id),
                        );
                    if (form.backup_user_id)
                        body.append(
                            'reminder[backup_user_id]',
                            String(form.backup_user_id),
                        );
                }
                result = await command.submit(
                    `/fleet-assets/vehicles/${vehicle.id}/documents`,
                    body,
                );
            }
        }
        if (!result) return;
        const states = Array.isArray(result.files)
            ? result.files.map((file) =>
                  isJsonObject(file) ? String(file.state) : '',
              )
            : [];
        const waiting = states.filter((state) => state !== 'available').length;
        setSavedText(
            mode.kind === 'edit'
                ? 'The details and renewal reminder are saved together.'
                : waiting
                  ? `${states.length - waiting} of ${states.length} files are available now. The rest are stored privately and will open once they pass a virus check.`
                  : `${states.length} ${states.length === 1 ? 'file is' : 'files are'} available.`,
        );
        onSaved();
    };
    const owner = workspace.people.find(
        (person) => person.id === form.owner_user_id,
    );

    return (
        <WorkspaceWizard
            title={
                mode.kind === 'create'
                    ? 'Upload vehicle documents'
                    : mode.kind === 'replace'
                      ? 'Replace vehicle document'
                      : 'Edit document details & renewal'
            }
            description={`${vehicle.name}: files uploaded together share these details and one renewal reminder.`}
            railIcon={FileText}
            railSub={vehicle.registration_number ?? vehicle.asset_tag ?? ''}
            steps={STEPS}
            step={step}
            setStep={(next) =>
                setStep(mode.kind === 'replace' && next === 0 ? 1 : next)
            }
            pct={Math.round(
                ([
                    !!form.category,
                    !!form.document_date,
                    !withFiles || files.length > 0,
                    !!form.reason.trim(),
                    !form.reminder || !!form.remind_local,
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
            command={command}
            dirty={
                JSON.stringify(form) !== JSON.stringify(initial) ||
                files.length > 0
            }
            saved={savedText !== null}
            submitLabel={
                mode.kind === 'create'
                    ? 'Save vehicle documents'
                    : mode.kind === 'replace'
                      ? 'Save new version'
                      : 'Save document details'
            }
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={onClose}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title={
                        mode.kind === 'edit'
                            ? 'Document details saved'
                            : 'Documents saved'
                    }
                    blurb={`${savedText ?? ''} Any renewal reminder is in Reminders and on the site calendar.`}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        {mode.kind === 'edit'
                            ? `Update the details for this document. Its files stay unchanged.`
                            : 'Files uploaded together share these details and one renewal reminder. Upload documents with different expiry dates separately.'}
                    </p>
                    <WizardField
                        id="category"
                        label="Document type"
                        error={errors.category}
                    >
                        <CataloguePicker
                            id="category"
                            kind="document_type"
                            label="Document type"
                            value={form.category}
                            onChange={(value) => update('category', value)}
                            invalid={!!errors.category}
                        />
                    </WizardField>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="reference"
                            label="Document or policy reference"
                            optional
                            error={errors.reference}
                        >
                            <Input
                                {...fieldProps('reference', errors.reference)}
                                maxLength={120}
                                value={form.reference}
                                onChange={(event) =>
                                    update('reference', event.target.value)
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="document_date"
                            label="Document date"
                            error={errors.document_date}
                        >
                            <DatePicker
                                id="document_date"
                                label="Document date"
                                value={form.document_date}
                                onChange={(value) =>
                                    update('document_date', value)
                                }
                                invalid={!!errors.document_date}
                            />
                        </WizardField>
                    </div>
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    {mode.kind === 'replace' ? (
                        <p className="text-subtle">
                            Replace this {mode.set.category.toLowerCase()} (
                            {
                                mode.set.files.filter((file) => file.current)
                                    .length
                            }{' '}
                            current files). Previous files stay in history and
                            stay current until the new ones pass their virus
                            check. The renewal reminder follows the new version.
                        </p>
                    ) : (
                        <>
                            <p className="text-subtle">
                                Set the expiry and its reminder together. Saving
                                a document doesn&apos;t certify compliance or
                                release the vehicle.
                            </p>
                            <div className="vehicle-wizard-fields">
                                <WizardField
                                    id="expires_on"
                                    label="Expiry / renewal date"
                                    optional={!form.reminder}
                                    error={errors.expires_on}
                                    hint="Leave blank only if this document has no expiry."
                                >
                                    <DatePicker
                                        id="expires_on"
                                        label="Expiry / renewal date"
                                        value={form.expires_on}
                                        onChange={(value) =>
                                            update('expires_on', value)
                                        }
                                        invalid={!!errors.expires_on}
                                    />
                                </WizardField>
                                <label className="flex items-start gap-3 rounded-lg border p-3">
                                    <Switch
                                        checked={form.reminder}
                                        onCheckedChange={(value) =>
                                            update('reminder', value)
                                        }
                                        aria-label="Schedule renewal reminder"
                                    />
                                    <span>
                                        <span className="block font-medium">
                                            Schedule renewal reminder
                                        </span>
                                        <span className="text-caption">
                                            {set?.renewal
                                                ? 'Updates the existing reminder. Turn off to pause it; its history is kept.'
                                                : 'One in-app reminder for this document. No email or text is sent.'}
                                        </span>
                                    </span>
                                </label>
                            </div>
                            {form.reminder && (
                                <div className="grid gap-4 rounded-lg border p-4">
                                    <DateTimeField
                                        id="reminder.remind_local"
                                        label="Remind at"
                                        value={form.remind_local}
                                        onChange={(value) =>
                                            update('remind_local', value)
                                        }
                                        error={errors['reminder.remind_local']}
                                        hint="Pacific/Auckland · suggested 30 days before expiry; adjust as needed."
                                    />
                                    <div className="vehicle-wizard-fields">
                                        <WizardField
                                            id="reminder.owner_user_id"
                                            label="Reminder owner"
                                            error={
                                                errors['reminder.owner_user_id']
                                            }
                                        >
                                            <PersonPicker
                                                id="reminder.owner_user_id"
                                                label="Reminder owner"
                                                value={form.owner_user_id}
                                                people={workspace.people}
                                                onChange={(value) =>
                                                    update(
                                                        'owner_user_id',
                                                        value,
                                                    )
                                                }
                                                invalid={
                                                    !!errors[
                                                        'reminder.owner_user_id'
                                                    ]
                                                }
                                            />
                                        </WizardField>
                                        <WizardField
                                            id="reminder.backup_user_id"
                                            label="Backup owner"
                                            optional
                                            error={
                                                errors[
                                                    'reminder.backup_user_id'
                                                ]
                                            }
                                        >
                                            <PersonPicker
                                                id="reminder.backup_user_id"
                                                label="Backup owner"
                                                value={form.backup_user_id}
                                                people={workspace.people}
                                                onChange={(value) =>
                                                    update(
                                                        'backup_user_id',
                                                        value,
                                                    )
                                                }
                                            />
                                        </WizardField>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                    {withFiles && (
                        <StagedFilesField
                            label="Upload documents"
                            files={files}
                            onChange={setFiles}
                            error={errors.files ?? errors['files.0']}
                            optional={false}
                        />
                    )}
                    <WizardField
                        id="reason"
                        label="Reason / supporting details"
                        error={errors.reason}
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
            {step === 2 && (
                <div className="grid gap-4">
                    {mode.kind !== 'replace' && (
                        <ReviewCard
                            icon={FileText}
                            title="Document details"
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow
                                label="Type"
                                value={form.category || undefined}
                            />
                            <ReviewRow
                                label="Reference"
                                value={form.reference || undefined}
                            />
                            <ReviewRow
                                label="Document date"
                                value={
                                    form.document_date
                                        ? formatDateOnly(form.document_date)
                                        : undefined
                                }
                            />
                        </ReviewCard>
                    )}
                    <ReviewCard
                        icon={Bell}
                        title="Files & follow-up"
                        onEdit={() => setStep(1)}
                    >
                        {mode.kind !== 'replace' && (
                            <ReviewRow
                                label="Expiry"
                                value={
                                    form.expires_on
                                        ? formatDateOnly(form.expires_on)
                                        : 'No expiry'
                                }
                            />
                        )}
                        {mode.kind !== 'replace' && (
                            <ReviewRow
                                label="Renewal reminder"
                                value={
                                    form.reminder
                                        ? `${formatDateOnly(form.remind_local.slice(0, 10))}, ${form.remind_local.slice(11)} · ${owner?.name ?? 'No owner'}`
                                        : 'Not scheduled'
                                }
                            />
                        )}
                        {withFiles && (
                            <ReviewRow
                                label="Files"
                                value={
                                    files.length
                                        ? files
                                              .map((file) => file.name)
                                              .join(', ')
                                        : undefined
                                }
                            />
                        )}
                        <ReviewRow
                            label="Reason"
                            value={form.reason || undefined}
                        />
                    </ReviewCard>
                    <p className="text-caption">
                        Document details and the linked renewal reminder are
                        saved together. Profile cover dates and compliance
                        evidence stay separate records.
                    </p>
                </div>
            )}
        </WorkspaceWizard>
    );
}

function ArchiveDialog({
    vehicleId,
    set,
    onClose,
    onSaved,
}: {
    vehicleId: number;
    set: DocumentSet;
    onClose: () => void;
    onSaved: () => void;
}) {
    const current = set.files.filter((file) => file.current);
    const [fileId, setFileId] = useState<string>(
        current[0] ? String(current[0].id) : '',
    );
    const [reason, setReason] = useState('');
    const [pause, setPause] = useState(false);
    const [error, setError] = useState('');
    const command = useVehicleRecordCommand(isJsonObject);
    const selected = useMemo(
        () => current.find((file) => String(file.id) === fileId),
        [current, fileId],
    );
    const save = async () => {
        if (!reason.trim()) {
            setError('Record why this file is archived.');
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicleId}/document-files/${fileId}/archive`,
            {
                reason: reason.trim(),
                pause_renewal: pause,
            },
        );
        if (result) {
            onSaved();
            onClose();
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(next) => !next && !command.processing && onClose()}
        >
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Archive vehicle document</DialogTitle>
                    <DialogDescription>
                        {selected?.name ?? 'The file'} stays in history.
                        Archiving does not close a renewal or change compliance
                        evidence.
                    </DialogDescription>
                </DialogHeader>
                {command.message && (
                    <p
                        role="alert"
                        className="rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning"
                    >
                        {command.message}
                    </p>
                )}
                <div className="grid gap-4">
                    {current.length > 1 && (
                        <WizardField id="archive-file" label="File">
                            <Select value={fileId} onValueChange={setFileId}>
                                <SelectTrigger id="archive-file">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {current.map((file) => (
                                        <SelectItem
                                            key={file.id}
                                            value={String(file.id)}
                                        >
                                            {file.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </WizardField>
                    )}
                    <WizardField
                        id="archive-reason"
                        label="Archive reason"
                        error={error}
                    >
                        <Textarea
                            {...fieldProps('archive-reason', error)}
                            rows={2}
                            maxLength={2000}
                            value={reason}
                            onChange={(event) => {
                                setReason(event.target.value);
                                setError('');
                            }}
                        />
                    </WizardField>
                    {set.renewal && (
                        <label className="flex items-start gap-3 text-sm">
                            <Checkbox
                                checked={pause}
                                onCheckedChange={(value) =>
                                    setPause(value === true)
                                }
                                aria-label="Pause renewal reminder"
                            />
                            <span>
                                Pause the renewal reminder for this document
                                <span className="text-caption block">
                                    Leave unticked to keep the follow-up active.
                                </span>
                            </span>
                        </label>
                    )}
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        disabled={command.processing}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={
                            command.processing ||
                            command.requiresReload ||
                            !fileId
                        }
                        onClick={save}
                    >
                        {command.processing && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        Archive file
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
