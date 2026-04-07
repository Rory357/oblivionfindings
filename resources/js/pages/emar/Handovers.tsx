import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle,
    ClipboardCheck,
    Pencil,
    Plus,
    Shield,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';

const DEFAULT_CHECKLIST_ITEMS = [
    {
        label: 'All scheduled medications administered',
        checked: false,
        notes: '',
    },
    {
        label: 'PRN medications documented with effectiveness',
        checked: false,
        notes: '',
    },
    {
        label: 'Controlled drugs counted and verified',
        checked: false,
        notes: '',
    },
    { label: 'Stock levels checked for low items', checked: false, notes: '' },
    {
        label: 'Medication errors reported and documented',
        checked: false,
        notes: '',
    },
    { label: 'GP follow-ups documented', checked: false, notes: '' },
    { label: 'Client refusals followed up', checked: false, notes: '' },
    { label: 'New prescriptions actioned', checked: false, notes: '' },
];

type ChecklistItem = { label: string; checked: boolean; notes: string };
type ClientAttention = {
    client_id: string;
    client_name: string;
    reason: string;
};
type ShiftOption = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    shift_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    location?: string | null;
    service_context_name?: string | null;
    client_name?: string | null;
    staff_name?: string | null;
};

type Handover = {
    id: number;
    handover_at: string;
    outgoing_user: { id: number; name: string } | null;
    incoming_user: { id: number; name: string } | null;
    site: { id: number; name: string } | null;
    service_context?: { id: number; name: string } | null;
    shift?: ShiftOption | null;
    controlled_drugs_verified: boolean;
    controlled_drug_counts: Array<{
        medication_id: number;
        medication_name?: string;
        expected: number;
        actual: number;
        discrepancy: number;
    }> | null;
    outstanding_medications: any[] | null;
    new_prescriptions: any[] | null;
    ceased_medications: any[] | null;
    incidents: any[] | null;
    prn_given: any[] | null;
    flagged_clients: any[] | null;
    general_notes: string | null;
    acknowledged: boolean;
    acknowledged_at: string | null;
    checklist_items: ChecklistItem[] | null;
    safety_concerns: string | null;
    medication_errors_count: number;
    pending_gp_followups: number;
    clients_requiring_attention: ClientAttention[] | null;
    previous_shift_notes_read: boolean;
    stock_issues_identified: string | null;
    prescriber_changes_summary: string | null;
};

type Props = {
    handovers: { data: Handover[]; links: any };
    staff: { id: number; name: string }[];
    shifts: ShiftOption[];
};

type HandoverFormData = {
    incoming_user_id: string;
    shift_id: string;
    controlled_drugs_verified: boolean;
    general_notes: string;
    checklist_items: ChecklistItem[];
    safety_concerns: string;
    clients_requiring_attention: ClientAttention[];
    stock_issues_identified: string;
    prescriber_changes_summary: string;
    previous_shift_notes_read: boolean;
};

function getChecklistCompletion(items: ChecklistItem[] | null): string {
    if (!items || items.length === 0) return '0/0';
    const checked = items.filter((i) => i.checked).length;
    return `${checked}/${items.length}`;
}

function ChecklistSection({
    items,
    onChange,
}: {
    items: ChecklistItem[];
    onChange: (items: ChecklistItem[]) => void;
}) {
    function toggleItem(index: number, checked: boolean) {
        const updated = [...items];
        const existing = updated[index] ?? {
            label: '',
            checked: false,
            notes: '',
        };
        updated[index] = { ...existing, checked };
        onChange(updated);
    }

    function updateNotes(index: number, notes: string) {
        const updated = [...items];
        const existing = updated[index] ?? {
            label: '',
            checked: false,
            notes: '',
        };
        updated[index] = { ...existing, notes };
        onChange(updated);
    }

    return (
        <div className="space-y-3">
            <Label className="flex items-center gap-1.5 text-sm font-semibold">
                <ClipboardCheck className="h-4 w-4" /> Handover Checklist
            </Label>
            <div className="space-y-2 rounded-md border p-3">
                {items.map((item, index) => (
                    <div key={index} className="space-y-1">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id={`checklist-${index}`}
                                checked={item.checked}
                                onCheckedChange={(checked) =>
                                    toggleItem(index, !!checked)
                                }
                            />
                            <Label
                                htmlFor={`checklist-${index}`}
                                className="cursor-pointer text-sm font-normal"
                            >
                                {item.label}
                            </Label>
                        </div>
                        {item.checked && (
                            <div className="ml-6">
                                <Input
                                    placeholder="Optional notes..."
                                    value={item.notes}
                                    onChange={(e) =>
                                        updateNotes(index, e.target.value)
                                    }
                                    className="h-8 text-xs"
                                />
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}

function ClientsAttentionSection({
    clients,
    onChange,
}: {
    clients: ClientAttention[];
    onChange: (clients: ClientAttention[]) => void;
}) {
    function addClient() {
        onChange([...clients, { client_id: '', client_name: '', reason: '' }]);
    }

    function removeClient(index: number) {
        onChange(clients.filter((_, i) => i !== index));
    }

    function updateClient(
        index: number,
        field: keyof ClientAttention,
        value: string,
    ) {
        const updated = [...clients];
        const existing = updated[index] ?? {
            client_id: '',
            client_name: '',
            reason: '',
        };
        updated[index] = { ...existing, [field]: value };
        onChange(updated);
    }

    return (
        <div className="space-y-3">
            <Label className="flex items-center gap-1.5 text-sm font-semibold">
                <Users className="h-4 w-4" /> Clients Requiring Attention
            </Label>
            <div className="space-y-2">
                {clients.map((client, index) => (
                    <div
                        key={index}
                        className="flex items-start gap-2 rounded-md border p-2"
                    >
                        <div className="flex-1 space-y-1">
                            <Input
                                placeholder="Client name"
                                value={client.client_name}
                                onChange={(e) =>
                                    updateClient(
                                        index,
                                        'client_name',
                                        e.target.value,
                                    )
                                }
                                className="h-8 text-xs"
                            />
                            <Input
                                placeholder="Reason for attention"
                                value={client.reason}
                                onChange={(e) =>
                                    updateClient(
                                        index,
                                        'reason',
                                        e.target.value,
                                    )
                                }
                                className="h-8 text-xs"
                            />
                        </div>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="h-8 w-8 shrink-0"
                            onClick={() => removeClient(index)}
                        >
                            <X className="h-3.5 w-3.5 text-red-500" />
                        </Button>
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={addClient}
                >
                    <Plus className="mr-1 h-3.5 w-3.5" /> Add Client
                </Button>
            </div>
        </div>
    );
}

function HandoverFormFields({
    formData,
    setField,
    errors,
    staff,
    shiftOptions,
    idPrefix,
}: {
    formData: HandoverFormData;
    setField: (key: keyof HandoverFormData, value: any) => void;
    errors: Partial<Record<keyof HandoverFormData, string>>;
    staff: { id: number; name: string }[];
    shiftOptions: ShiftOption[];
    idPrefix: string;
}) {
    const selectedShift = shiftOptions.find(
        (shift) => String(shift.id) === formData.shift_id,
    );

    return (
        <div className="max-h-[70vh] space-y-4 overflow-y-auto py-4 pr-1">
            <div className="space-y-2">
                <Label htmlFor={`${idPrefix}_shift_id`}>Linked Shift</Label>
                <Select
                    value={formData.shift_id}
                    onValueChange={(v) => setField('shift_id', v)}
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Select a shift..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="">No linked shift</SelectItem>
                        {shiftOptions.map((shift) => (
                            <SelectItem key={shift.id} value={String(shift.id)}>
                                {`${shift.client_name || 'Client'} • ${new Date(shift.starts_at).toLocaleString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}`}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {selectedShift && (
                    <div className="rounded-md border bg-muted/20 p-3 text-xs text-muted-foreground">
                        <div className="font-medium text-foreground">
                            {selectedShift.client_name || 'Linked shift'}
                        </div>
                        <div className="mt-1">
                            {String(
                                selectedShift.shift_type ?? 'standard',
                            ).replace('_', ' ')}
                            {selectedShift.service_context_name
                                ? ` • ${selectedShift.service_context_name}`
                                : ''}
                            {selectedShift.location
                                ? ` • ${selectedShift.location}`
                                : ''}
                        </div>
                        {selectedShift.staff_name ? (
                            <div className="mt-1">
                                Assigned staff: {selectedShift.staff_name}
                            </div>
                        ) : null}
                    </div>
                )}
            </div>

            {/* Incoming Staff */}
            <div className="space-y-2">
                <Label htmlFor={`${idPrefix}_incoming_user_id`}>
                    Incoming Staff Member
                </Label>
                <Select
                    value={formData.incoming_user_id}
                    onValueChange={(v) => setField('incoming_user_id', v)}
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Select incoming staff..." />
                    </SelectTrigger>
                    <SelectContent>
                        {staff.map((s) => (
                            <SelectItem key={s.id} value={String(s.id)}>
                                {s.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {errors.incoming_user_id && (
                    <p className="text-sm text-red-600">
                        {errors.incoming_user_id}
                    </p>
                )}
            </div>

            {/* Controlled Drugs Verified */}
            <div className="flex items-center gap-2">
                <Checkbox
                    id={`${idPrefix}_controlled_drugs_verified`}
                    checked={formData.controlled_drugs_verified}
                    onCheckedChange={(checked) =>
                        setField('controlled_drugs_verified', !!checked)
                    }
                />
                <Label htmlFor={`${idPrefix}_controlled_drugs_verified`}>
                    Controlled drugs verified
                </Label>
            </div>

            {/* Checklist */}
            <ChecklistSection
                items={formData.checklist_items}
                onChange={(items) => setField('checklist_items', items)}
            />

            {/* Safety Concerns */}
            <div className="space-y-2">
                <Label
                    htmlFor={`${idPrefix}_safety_concerns`}
                    className="flex items-center gap-1.5 text-sm font-semibold"
                >
                    <AlertTriangle className="h-4 w-4 text-amber-500" /> Safety
                    Concerns
                </Label>
                <Textarea
                    id={`${idPrefix}_safety_concerns`}
                    value={formData.safety_concerns}
                    onChange={(e) =>
                        setField('safety_concerns', e.target.value)
                    }
                    rows={3}
                    placeholder="Any safety issues for the incoming shift..."
                />
            </div>

            {/* Clients Requiring Attention */}
            <ClientsAttentionSection
                clients={formData.clients_requiring_attention}
                onChange={(clients) =>
                    setField('clients_requiring_attention', clients)
                }
            />

            {/* Stock Issues */}
            <div className="space-y-2">
                <Label htmlFor={`${idPrefix}_stock_issues`}>Stock Issues</Label>
                <Textarea
                    id={`${idPrefix}_stock_issues`}
                    value={formData.stock_issues_identified}
                    onChange={(e) =>
                        setField('stock_issues_identified', e.target.value)
                    }
                    rows={2}
                    placeholder="Any stock level concerns or shortages..."
                />
            </div>

            {/* Prescriber Changes Summary */}
            <div className="space-y-2">
                <Label htmlFor={`${idPrefix}_prescriber_changes`}>
                    Prescriber Changes Summary
                </Label>
                <Textarea
                    id={`${idPrefix}_prescriber_changes`}
                    value={formData.prescriber_changes_summary}
                    onChange={(e) =>
                        setField('prescriber_changes_summary', e.target.value)
                    }
                    rows={2}
                    placeholder="Summary of any prescriber or prescription changes..."
                />
            </div>

            {/* Previous Shift Notes Read */}
            <div className="flex items-center gap-2">
                <Checkbox
                    id={`${idPrefix}_previous_shift_notes_read`}
                    checked={formData.previous_shift_notes_read}
                    onCheckedChange={(checked) =>
                        setField('previous_shift_notes_read', !!checked)
                    }
                />
                <Label htmlFor={`${idPrefix}_previous_shift_notes_read`}>
                    Previous shift notes read
                </Label>
            </div>

            {/* General Notes */}
            <div className="space-y-2">
                <Label htmlFor={`${idPrefix}_general_notes`}>
                    General Notes
                </Label>
                <Textarea
                    id={`${idPrefix}_general_notes`}
                    value={formData.general_notes}
                    onChange={(e) => setField('general_notes', e.target.value)}
                    rows={4}
                    placeholder="Any relevant notes for the incoming staff member..."
                />
                {errors.general_notes && (
                    <p className="text-sm text-red-600">
                        {errors.general_notes}
                    </p>
                )}
            </div>
        </div>
    );
}

export default function Handovers({ handovers, staff, shifts }: Props) {
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    const [open, setOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [editingHandover, setEditingHandover] = useState<Handover | null>(
        null,
    );

    const form = useForm<HandoverFormData>({
        incoming_user_id: '',
        shift_id: '',
        controlled_drugs_verified: false,
        general_notes: '',
        checklist_items: DEFAULT_CHECKLIST_ITEMS.map((i) => ({ ...i })),
        safety_concerns: '',
        clients_requiring_attention: [],
        stock_issues_identified: '',
        prescriber_changes_summary: '',
        previous_shift_notes_read: false,
    });

    const editForm = useForm<HandoverFormData>({
        incoming_user_id: '',
        shift_id: '',
        controlled_drugs_verified: false,
        general_notes: '',
        checklist_items: DEFAULT_CHECKLIST_ITEMS.map((i) => ({ ...i })),
        safety_concerns: '',
        clients_requiring_attention: [],
        stock_issues_identified: '',
        prescriber_changes_summary: '',
        previous_shift_notes_read: false,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/emar/handovers', {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    function openEdit(h: Handover) {
        setEditingHandover(h);
        editForm.setData({
            incoming_user_id: h.incoming_user?.id?.toString() ?? '',
            shift_id: h.shift?.id?.toString() ?? '',
            controlled_drugs_verified: h.controlled_drugs_verified,
            general_notes: h.general_notes ?? '',
            checklist_items:
                h.checklist_items ??
                DEFAULT_CHECKLIST_ITEMS.map((i) => ({ ...i })),
            safety_concerns: h.safety_concerns ?? '',
            clients_requiring_attention: h.clients_requiring_attention ?? [],
            stock_issues_identified: h.stock_issues_identified ?? '',
            prescriber_changes_summary: h.prescriber_changes_summary ?? '',
            previous_shift_notes_read: h.previous_shift_notes_read,
        });
        setEditOpen(true);
    }

    function submitEdit(e: React.FormEvent) {
        e.preventDefault();
        if (!editingHandover) return;
        editForm.put(`/emar/handovers/${editingHandover.id}`, {
            onSuccess: () => {
                setEditOpen(false);
                setEditingHandover(null);
                editForm.reset();
            },
        });
    }

    return (
        <AppLayout>
            <Head title="eMAR - Medication Handovers" />
            <PageHeader
                title="Medication Handovers"
                description="Shift handover records for medication, including controlled drug counts and outstanding items."
                backHref="/emar"
            />
            <PageShell>
                <div className="mb-4 flex justify-end">
                    <Dialog open={open} onOpenChange={setOpen}>
                        <DialogTrigger asChild>
                            <Button>
                                <Plus className="mr-2 h-4 w-4" /> New Handover
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-w-2xl">
                            <form onSubmit={submit}>
                                <DialogHeader>
                                    <DialogTitle>New Handover</DialogTitle>
                                    <DialogDescription>
                                        Create a new medication shift handover
                                        record.
                                    </DialogDescription>
                                </DialogHeader>
                                <HandoverFormFields
                                    formData={form.data}
                                    setField={(key, value) =>
                                        form.setData(key, value)
                                    }
                                    errors={form.errors}
                                    staff={staff}
                                    shiftOptions={shifts}
                                    idPrefix="new"
                                />
                                <DialogFooter>
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        {form.processing
                                            ? 'Creating...'
                                            : 'Create Handover'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <div className="space-y-4">
                    {handovers.data.map((h) => {
                        const hasDiscrepancies = h.controlled_drug_counts?.some(
                            (c) => c.discrepancy !== 0,
                        );
                        const isIncomingUser =
                            h.incoming_user?.id === auth.user.id;
                        const checklistCompletion = getChecklistCompletion(
                            h.checklist_items,
                        );
                        const hasSafetyConcerns =
                            !!h.safety_concerns &&
                            h.safety_concerns.trim().length > 0;
                        const clientsCount =
                            h.clients_requiring_attention?.length ?? 0;

                        return (
                            <Card
                                key={h.id}
                                className={
                                    hasDiscrepancies
                                        ? 'border-red-200 dark:border-red-800'
                                        : ''
                                }
                            >
                                <CardHeader className="pb-3">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">
                                                {h.outgoing_user?.name ??
                                                    'Unknown'}
                                            </span>
                                            <ArrowRight className="h-4 w-4 text-muted-foreground" />
                                            <span className="font-medium">
                                                {h.incoming_user?.name ??
                                                    'Unknown'}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs text-muted-foreground">
                                                {h.handover_at
                                                    ? new Date(
                                                          h.handover_at,
                                                      ).toLocaleString(
                                                          'en-NZ',
                                                          {
                                                              dateStyle:
                                                                  'short',
                                                              timeStyle:
                                                                  'short',
                                                          },
                                                      )
                                                    : '—'}
                                            </span>
                                            {h.acknowledged ? (
                                                <Badge className="bg-green-100 text-xs text-green-700">
                                                    <CheckCircle className="mr-1 h-3 w-3" />{' '}
                                                    Acknowledged
                                                </Badge>
                                            ) : (
                                                <Badge
                                                    variant="outline"
                                                    className="text-xs"
                                                >
                                                    Pending
                                                </Badge>
                                            )}
                                            {!h.acknowledged &&
                                                isIncomingUser && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                `/emar/handovers/${h.id}/acknowledge`,
                                                            )
                                                        }
                                                    >
                                                        <CheckCircle className="mr-1 h-3.5 w-3.5" />{' '}
                                                        Acknowledge
                                                    </Button>
                                                )}
                                            {!h.acknowledged && (
                                                <>
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            openEdit(h)
                                                        }
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        onClick={() => {
                                                            if (
                                                                confirm(
                                                                    'Are you sure you want to delete this handover?',
                                                                )
                                                            ) {
                                                                router.delete(
                                                                    `/emar/handovers/${h.id}`,
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        <Trash2 className="h-4 w-4 text-red-500" />
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                    {h.site && (
                                        <p className="text-xs text-muted-foreground">
                                            {h.site.name}
                                        </p>
                                    )}
                                    {h.shift && (
                                        <p className="text-xs text-muted-foreground">
                                            {h.shift.client_name ||
                                                'Linked shift'}
                                            {h.shift.shift_type
                                                ? ` • ${String(h.shift.shift_type).replace('_', ' ')}`
                                                : ''}
                                            {h.shift.service_context_name
                                                ? ` • ${h.shift.service_context_name}`
                                                : ''}
                                            {h.shift.location
                                                ? ` • ${h.shift.location}`
                                                : ''}
                                        </p>
                                    )}
                                    {/* Checklist + Safety + Clients indicators */}
                                    <div className="mt-2 flex items-center gap-3">
                                        <Badge
                                            variant="outline"
                                            className="gap-1 text-xs"
                                        >
                                            <ClipboardCheck className="h-3 w-3" />{' '}
                                            {checklistCompletion} items checked
                                        </Badge>
                                        {hasSafetyConcerns && (
                                            <Badge
                                                variant="destructive"
                                                className="gap-1 text-xs"
                                            >
                                                <AlertTriangle className="h-3 w-3" />{' '}
                                                Safety concerns
                                            </Badge>
                                        )}
                                        {clientsCount > 0 && (
                                            <Badge className="gap-1 bg-amber-100 text-xs text-amber-700">
                                                <Users className="h-3 w-3" />{' '}
                                                {clientsCount} client
                                                {clientsCount !== 1
                                                    ? 's'
                                                    : ''}{' '}
                                                need attention
                                            </Badge>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        {/* Controlled Drug Counts */}
                                        <div>
                                            <h4 className="mb-2 flex items-center gap-1 text-xs font-semibold">
                                                <Shield className="h-3.5 w-3.5" />{' '}
                                                CD Counts
                                                {h.controlled_drugs_verified ? (
                                                    <Badge className="ml-1 bg-green-100 text-[10px] text-green-700">
                                                        Verified
                                                    </Badge>
                                                ) : (
                                                    <Badge
                                                        variant="destructive"
                                                        className="ml-1 text-[10px]"
                                                    >
                                                        Unverified
                                                    </Badge>
                                                )}
                                            </h4>
                                            {h.controlled_drug_counts &&
                                            h.controlled_drug_counts.length >
                                                0 ? (
                                                <div className="space-y-1 text-xs">
                                                    {h.controlled_drug_counts.map(
                                                        (c, i) => (
                                                            <div
                                                                key={i}
                                                                className="flex justify-between"
                                                            >
                                                                <span>
                                                                    {c.medication_name ??
                                                                        `Med #${c.medication_id}`}
                                                                </span>
                                                                <span
                                                                    className={
                                                                        c.discrepancy !==
                                                                        0
                                                                            ? 'font-bold text-red-600'
                                                                            : ''
                                                                    }
                                                                >
                                                                    {c.actual}/
                                                                    {c.expected}
                                                                    {c.discrepancy !==
                                                                        0 &&
                                                                        ` (${c.discrepancy > 0 ? '+' : ''}${c.discrepancy})`}
                                                                </span>
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            ) : (
                                                <p className="text-xs text-muted-foreground">
                                                    No CD counts recorded.
                                                </p>
                                            )}
                                        </div>

                                        {/* Outstanding Items */}
                                        <div>
                                            <h4 className="mb-2 text-xs font-semibold">
                                                Outstanding Items
                                            </h4>
                                            <div className="space-y-1 text-xs">
                                                {h.outstanding_medications &&
                                                    h.outstanding_medications
                                                        .length > 0 && (
                                                        <p>
                                                            <Badge
                                                                variant="outline"
                                                                className="text-[10px]"
                                                            >
                                                                {
                                                                    h
                                                                        .outstanding_medications
                                                                        .length
                                                                }
                                                            </Badge>{' '}
                                                            Outstanding meds
                                                        </p>
                                                    )}
                                                {h.new_prescriptions &&
                                                    h.new_prescriptions.length >
                                                        0 && (
                                                        <p>
                                                            <Badge className="bg-blue-100 text-[10px] text-blue-700">
                                                                {
                                                                    h
                                                                        .new_prescriptions
                                                                        .length
                                                                }
                                                            </Badge>{' '}
                                                            New prescriptions
                                                        </p>
                                                    )}
                                                {h.ceased_medications &&
                                                    h.ceased_medications
                                                        .length > 0 && (
                                                        <p>
                                                            <Badge
                                                                variant="secondary"
                                                                className="text-[10px]"
                                                            >
                                                                {
                                                                    h
                                                                        .ceased_medications
                                                                        .length
                                                                }
                                                            </Badge>{' '}
                                                            Ceased meds
                                                        </p>
                                                    )}
                                                {h.incidents &&
                                                    h.incidents.length > 0 && (
                                                        <p>
                                                            <Badge
                                                                variant="destructive"
                                                                className="text-[10px]"
                                                            >
                                                                {
                                                                    h.incidents
                                                                        .length
                                                                }
                                                            </Badge>{' '}
                                                            Incidents
                                                        </p>
                                                    )}
                                                {h.prn_given &&
                                                    h.prn_given.length > 0 && (
                                                        <p>
                                                            <Badge
                                                                variant="outline"
                                                                className="text-[10px]"
                                                            >
                                                                {
                                                                    h.prn_given
                                                                        .length
                                                                }
                                                            </Badge>{' '}
                                                            PRN given
                                                        </p>
                                                    )}
                                            </div>
                                        </div>

                                        {/* Notes & Flags */}
                                        <div>
                                            <h4 className="mb-2 text-xs font-semibold">
                                                Notes
                                            </h4>
                                            {h.flagged_clients &&
                                                h.flagged_clients.length >
                                                    0 && (
                                                    <div className="mb-2">
                                                        <span className="flex items-center gap-1 text-xs font-medium text-amber-600">
                                                            <AlertTriangle className="h-3 w-3" />{' '}
                                                            {
                                                                h
                                                                    .flagged_clients
                                                                    .length
                                                            }{' '}
                                                            flagged client(s)
                                                        </span>
                                                    </div>
                                                )}
                                            <p className="text-xs text-muted-foreground">
                                                {h.general_notes ?? 'No notes.'}
                                            </p>
                                            {hasSafetyConcerns && (
                                                <div className="mt-2 rounded-md bg-red-50 p-2 dark:bg-red-950">
                                                    <p className="flex items-center gap-1 text-xs font-medium text-red-700 dark:text-red-300">
                                                        <AlertTriangle className="h-3 w-3" />{' '}
                                                        Safety Concerns
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-red-600 dark:text-red-400">
                                                        {h.safety_concerns}
                                                    </p>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    {/* Clients Requiring Attention detail */}
                                    {h.clients_requiring_attention &&
                                        h.clients_requiring_attention.length >
                                            0 && (
                                            <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-2 dark:border-amber-800 dark:bg-amber-950">
                                                <h4 className="mb-1 flex items-center gap-1 text-xs font-semibold text-amber-700 dark:text-amber-300">
                                                    <Users className="h-3 w-3" />{' '}
                                                    Clients Requiring Attention
                                                </h4>
                                                <div className="space-y-1">
                                                    {h.clients_requiring_attention.map(
                                                        (c, i) => (
                                                            <div
                                                                key={i}
                                                                className="flex items-center gap-2 text-xs"
                                                            >
                                                                <span className="font-medium">
                                                                    {
                                                                        c.client_name
                                                                    }
                                                                </span>
                                                                <span className="text-muted-foreground">
                                                                    —
                                                                </span>
                                                                <span>
                                                                    {c.reason}
                                                                </span>
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                        )}

                                    {/* Checklist summary */}
                                    {h.checklist_items &&
                                        h.checklist_items.length > 0 && (
                                            <div className="mt-3">
                                                <h4 className="mb-1 flex items-center gap-1 text-xs font-semibold">
                                                    <ClipboardCheck className="h-3 w-3" />{' '}
                                                    Checklist (
                                                    {checklistCompletion})
                                                </h4>
                                                <div className="grid gap-1 text-xs sm:grid-cols-2">
                                                    {h.checklist_items.map(
                                                        (item, i) => (
                                                            <div
                                                                key={i}
                                                                className="flex items-start gap-1.5"
                                                            >
                                                                {item.checked ? (
                                                                    <CheckCircle className="mt-0.5 h-3 w-3 shrink-0 text-green-600" />
                                                                ) : (
                                                                    <X className="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" />
                                                                )}
                                                                <span
                                                                    className={
                                                                        item.checked
                                                                            ? ''
                                                                            : 'text-muted-foreground'
                                                                    }
                                                                >
                                                                    {item.label}
                                                                    {item.checked &&
                                                                        item.notes && (
                                                                            <span className="ml-1 text-muted-foreground">
                                                                                (
                                                                                {
                                                                                    item.notes
                                                                                }

                                                                                )
                                                                            </span>
                                                                        )}
                                                                </span>
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                        )}
                                </CardContent>
                            </Card>
                        );
                    })}

                    {handovers.data.length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center py-12">
                                <ArrowRight className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <p className="text-muted-foreground">
                                    No medication handover records.
                                </p>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Edit Handover Dialog */}
                <Dialog open={editOpen} onOpenChange={setEditOpen}>
                    <DialogContent className="max-w-2xl">
                        <form onSubmit={submitEdit}>
                            <DialogHeader>
                                <DialogTitle>Edit Handover</DialogTitle>
                                <DialogDescription>
                                    Update the handover record details.
                                </DialogDescription>
                            </DialogHeader>
                            <HandoverFormFields
                                formData={editForm.data}
                                setField={(key, value) =>
                                    editForm.setData(key, value)
                                }
                                errors={editForm.errors}
                                staff={staff}
                                shiftOptions={shifts}
                                idPrefix="edit"
                            />
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setEditOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={editForm.processing}
                                >
                                    {editForm.processing
                                        ? 'Saving...'
                                        : 'Save Changes'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}
