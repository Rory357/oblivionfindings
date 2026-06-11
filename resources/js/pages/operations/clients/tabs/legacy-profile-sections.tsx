import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
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
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Clock,
    DollarSign,
    FileText,
    Heart,
    ListTodo,
    MapPin,
    Package,
    Pencil,
    Pill,
    Plus,
    Search,
    Shield,
    ShieldAlert,
    Stethoscope,
    Trash2,
    Users,
} from 'lucide-react';
import type * as React from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';

type GalleryPhoto = {
    id: number;
    url: string;
    thumbnail_url?: string | null;
    caption?: string | null;
    tags?: string[] | null;
    visibility: string;
    status: string;
    original_name: string;
    uploaded_by?: string | null;
    created_at: string;
};

type AssetLocation = {
    id: number;
    name: string;
    type: string;
    rooms: Array<{ id: number; name: string }>;
};

type AvailableTracker = {
    id: number;
    name: string;
    status: string;
    serial?: string | null;
    site_id?: number | null;
    last_seen_at?: string | null;
    battery?: number | null;
};

type AssetTracker = {
    id: number;
    name: string;
    status: string;
    last_seen_at?: string | null;
    battery?: number | null;
    lat?: number | null;
    lng?: number | null;
    speed?: number | null;
};

type PersonalAsset = {
    id: number;
    name: string;
    category?: string | null;
    description?: string | null;
    serial_number?: string | null;
    estimated_value?: string | null;
    condition?: string | null;
    location?: string | null;
    site_id?: number | null;
    site_name?: string | null;
    room_id?: number | null;
    room_name?: string | null;
    tracker_hardware_id?: number | null;
    tracker?: AssetTracker | null;
    photo_url?: string | null;
    acquired_at?: string | null;
    notes?: string | null;
    status: string;
    ownership?: string | null;
    funding_source?: string | null;
    return_required?: boolean;
    return_by?: string | null;
    last_serviced_at?: string | null;
    next_service_due?: string | null;
    service_provider?: string | null;
    warranty_expires_at?: string | null;
    insurance_reference?: string | null;
    disposed_at?: string | null;
    disposal_reason?: string | null;
    portal_visible?: boolean;
    is_service_overdue?: boolean;
    is_warranty_expired?: boolean;
    is_warranty_expiring_soon?: boolean;
    is_return_overdue?: boolean;
    recorded_by?: string | null;
    created_at: string;
};
export function SupportPlanTab({
    clientId,
    plan,
    canEdit,
}: {
    clientId: number;
    plan: any | null;
    canEdit: boolean;
}) {
    const form = useForm<{
        goals: string;
        routines: string;
        preferences: string;
        communication_needs: string;
        cultural_needs: string;
        risk_notes: string;
        reviewed_at: string;
        next_review_at: string;
    }>({
        goals: plan?.goals ?? '',
        routines: plan?.routines ?? '',
        preferences: plan?.preferences ?? '',
        communication_needs: plan?.communication_needs ?? '',
        cultural_needs: plan?.cultural_needs ?? '',
        risk_notes: plan?.risk_notes ?? '',
        reviewed_at: plan?.reviewed_at ?? '',
        next_review_at: plan?.next_review_at ?? '',
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Support plan</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                {!canEdit && !plan && (
                    <div className="text-sm text-muted-foreground">
                        No support plan recorded.
                    </div>
                )}

                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <Label>Reviewed at</Label>
                        <Input
                            type="date"
                            value={form.data.reviewed_at}
                            onChange={(e) =>
                                form.setData('reviewed_at', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div>
                        <Label>Next review</Label>
                        <Input
                            type="date"
                            value={form.data.next_review_at}
                            onChange={(e) =>
                                form.setData('next_review_at', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Goals</Label>
                        <Textarea
                            rows={4}
                            value={form.data.goals}
                            onChange={(e) =>
                                form.setData('goals', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Daily routines</Label>
                        <Textarea
                            rows={4}
                            value={form.data.routines}
                            onChange={(e) =>
                                form.setData('routines', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Preferences</Label>
                        <Textarea
                            rows={4}
                            value={form.data.preferences}
                            onChange={(e) =>
                                form.setData('preferences', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Communication needs</Label>
                        <Textarea
                            rows={4}
                            value={form.data.communication_needs}
                            onChange={(e) =>
                                form.setData(
                                    'communication_needs',
                                    e.target.value,
                                )
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Cultural needs</Label>
                        <Textarea
                            rows={3}
                            value={form.data.cultural_needs}
                            onChange={(e) =>
                                form.setData('cultural_needs', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Risk notes</Label>
                        <Textarea
                            rows={3}
                            value={form.data.risk_notes}
                            onChange={(e) =>
                                form.setData('risk_notes', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                </div>

                {canEdit && (
                    <div>
                        <Button
                            onClick={() =>
                                form.put(
                                    `/operations/clients/${clientId}/support-plan`,
                                    {
                                        preserveScroll: true,
                                    },
                                )
                            }
                            disabled={form.processing}
                        >
                            Save support plan
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

const ASSESSMENT_TYPES: Record<
    string,
    {
        label: string;
        icon: string;
        border: string;
        bg: string;
        gradient: string;
    }
> = {
    interrai: {
        label: 'InterRAI',
        icon: '\u{1F3E5}',
        border: 'border-l-blue-400',
        bg: 'bg-status-info-bg',
        gradient: 'from-status-info-bg to-status-info-bg',
    },
    whodas: {
        label: 'WHODAS 2.0',
        icon: '\u{1F4CA}',
        border: 'border-l-violet-400',
        bg: 'bg-primary/10',
        gradient: 'from-primary/10 to-primary/10',
    },
    risk: {
        label: 'Risk Assessment',
        icon: '\u26A0\uFE0F',
        border: 'border-l-red-400',
        bg: 'bg-status-critical-bg',
        gradient: 'from-status-critical-bg to-status-critical-bg',
    },
    medication_review: {
        label: 'Medication Review',
        icon: '\u{1F48A}',
        border: 'border-l-emerald-400',
        bg: 'bg-status-success-bg',
        gradient: 'from-status-success-bg to-status-success-bg',
    },
    honos: {
        label: 'HoNOS',
        icon: '\u{1F9E0}',
        border: 'border-l-amber-400',
        bg: 'bg-status-warning-bg',
        gradient: 'from-status-warning-bg to-status-warning-bg',
    },
    functional: {
        label: 'Functional Assessment',
        icon: '\u{1F3C3}',
        border: 'border-l-cyan-400',
        bg: 'bg-status-info-bg',
        gradient: 'from-status-info-bg to-status-info-bg',
    },
    nasc: {
        label: 'Needs Assessment (NASC)',
        icon: '\u{1F4CB}',
        border: 'border-l-indigo-400',
        bg: 'bg-primary/10',
        gradient: 'from-primary/10 to-status-info-bg',
    },
    behaviour_support: {
        label: 'Behaviour Support',
        icon: '\u{1F91D}',
        border: 'border-l-pink-400',
        bg: 'bg-status-critical-bg',
        gradient: 'from-status-critical-bg to-status-critical-bg',
    },
    other: {
        label: 'Other',
        icon: '\u{1F4DD}',
        border: 'border-l-slate-400',
        bg: 'bg-muted',
        gradient: 'from-muted to-muted',
    },
};

function getTypeStyle(type: string): {
    label: string;
    icon: string;
    border: string;
    bg: string;
    gradient: string;
} {
    if (ASSESSMENT_TYPES[type]) return ASSESSMENT_TYPES[type]!;
    const lower = (type ?? '').toLowerCase();
    if (lower.includes('interrai')) return ASSESSMENT_TYPES.interrai!;
    if (lower.includes('whodas')) return ASSESSMENT_TYPES.whodas!;
    if (lower.includes('risk')) return ASSESSMENT_TYPES.risk!;
    if (lower.includes('medication') || lower.includes('med review'))
        return ASSESSMENT_TYPES.medication_review!;
    if (lower.includes('honos')) return ASSESSMENT_TYPES.honos!;
    if (lower.includes('functional')) return ASSESSMENT_TYPES.functional!;
    if (lower.includes('nasc') || lower.includes('needs'))
        return ASSESSMENT_TYPES.nasc!;
    if (lower.includes('behaviour') || lower.includes('behavior'))
        return ASSESSMENT_TYPES.behaviour_support!;
    return { ...ASSESSMENT_TYPES.other!, label: type || 'Assessment' };
}

export function AssessmentsTab({
    clientId,
    assessments,
    canEdit,
}: {
    clientId: number;
    assessments: Array<any>;
    canEdit: boolean;
}) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [showForm, setShowForm] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [customType, setCustomType] = useState('');

    const form = useForm<{
        type: string;
        score: string;
        assessed_at: string;
        next_review_at: string;
        notes: string;
    }>({
        type: '',
        score: '',
        assessed_at: '',
        next_review_at: '',
        notes: '',
    });

    function startEdit(a: any) {
        setEditingId(a.id);
        setShowForm(true);
        const knownKey = Object.keys(ASSESSMENT_TYPES).find(
            (k) => k === a.type,
        );
        if (knownKey) {
            form.setData({
                type: knownKey,
                score: a.score ?? '',
                assessed_at: a.assessed_at ?? '',
                next_review_at: a.next_review_at ?? '',
                notes: a.notes ?? '',
            });
            setCustomType('');
        } else {
            form.setData({
                type: 'other',
                score: a.score ?? '',
                assessed_at: a.assessed_at ?? '',
                next_review_at: a.next_review_at ?? '',
                notes: a.notes ?? '',
            });
            setCustomType(a.type ?? '');
        }
    }

    function resetForm() {
        setEditingId(null);
        setShowForm(false);
        setCustomType('');
        form.reset();
    }

    function submitForm() {
        const submitType =
            form.data.type === 'other' && customType.trim()
                ? customType.trim()
                : form.data.type;
        const url = editingId
            ? `/operations/clients/${clientId}/assessments/${editingId}`
            : `/operations/clients/${clientId}/assessments`;
        const method = editingId ? 'put' : 'post';
        const data = { ...form.data, type: submitType };
        // @ts-ignore
        router[method](url, data, {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        });
    }

    const now = new Date();
    const overdueCount = assessments.filter(
        (a) => a.next_review_at && new Date(a.next_review_at) < now,
    ).length;
    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    const completedThisMonth = assessments.filter(
        (a) => a.assessed_at && new Date(a.assessed_at) >= startOfMonth,
    ).length;
    const nextDue = assessments
        .filter((a) => a.next_review_at && new Date(a.next_review_at) >= now)
        .sort(
            (a, b) =>
                new Date(a.next_review_at).getTime() -
                new Date(b.next_review_at).getTime(),
        )[0];
    const nextDueDays = nextDue
        ? Math.ceil(
              (new Date(nextDue.next_review_at).getTime() - now.getTime()) /
                  86400000,
          )
        : null;

    return (
        <div className="space-y-4">
            {/* Stats Grid */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="rounded-xl border bg-primary/10 p-3 text-center">
                    <div className="text-2xl font-bold text-primary">
                        {assessments.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-primary uppercase">
                        Total Assessments
                    </div>
                </div>
                <div className="rounded-xl border bg-status-critical-bg p-3 text-center">
                    <div
                        className={`text-2xl font-bold ${overdueCount > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
                    >
                        {overdueCount}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-critical uppercase">
                        Overdue Reviews
                    </div>
                </div>
                <div className="rounded-xl border bg-status-success-bg p-3 text-center">
                    <div className="text-2xl font-bold text-status-success">
                        {completedThisMonth}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-success uppercase">
                        This Month
                    </div>
                </div>
                <div className="rounded-xl border bg-status-info-bg p-3 text-center">
                    <div className="text-2xl font-bold text-status-info">
                        {nextDueDays !== null ? `${nextDueDays}d` : '\u2014'}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-info uppercase">
                        Next Due
                    </div>
                </div>
            </div>

            {/* Overdue Alert Banner */}
            {overdueCount > 0 && (
                <div className="flex items-center gap-3 rounded-xl border-2 border-status-warning/30 bg-status-warning-bg p-4">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                        <ShieldAlert className="h-5 w-5" />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm font-semibold text-status-warning">
                            {overdueCount} Assessment Review
                            {overdueCount !== 1 ? 's' : ''} Overdue
                        </p>
                        <p className="text-xs text-status-warning">
                            These assessments are past their scheduled review
                            date.
                        </p>
                    </div>
                </div>
            )}

            {/* Form with Gradient Header */}
            {canEdit && showForm && (
                <Card className="overflow-hidden border-primary">
                    <div className="bg-primary px-4 py-2.5">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-primary-foreground">
                                {editingId
                                    ? 'Edit Assessment'
                                    : 'Record Assessment'}
                            </h3>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                onClick={resetForm}
                            >
                                Cancel
                            </Button>
                        </div>
                    </div>
                    <CardContent className="p-4">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <Label>Type</Label>
                                <Select
                                    value={form.data.type}
                                    onValueChange={(v) => {
                                        form.setData('type', v);
                                        if (v !== 'other') setCustomType('');
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select type..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(ASSESSMENT_TYPES).map(
                                            ([key, t]) => (
                                                <SelectItem
                                                    key={key}
                                                    value={key}
                                                >
                                                    <span className="flex items-center gap-2">
                                                        <span>{t.icon}</span>{' '}
                                                        {t.label}
                                                    </span>
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            {form.data.type === 'other' && (
                                <div>
                                    <Label>Custom Type</Label>
                                    <Input
                                        value={customType}
                                        onChange={(e) =>
                                            setCustomType(e.target.value)
                                        }
                                        placeholder="e.g. Sensory Profile"
                                    />
                                </div>
                            )}
                            <div>
                                <Label>Score (optional)</Label>
                                <Input
                                    value={form.data.score}
                                    onChange={(e) =>
                                        form.setData('score', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <Label>Assessed at</Label>
                                <Input
                                    type="date"
                                    value={form.data.assessed_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'assessed_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div>
                                <Label>Next review</Label>
                                <Input
                                    type="date"
                                    value={form.data.next_review_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'next_review_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <Label>Notes</Label>
                                <Textarea
                                    rows={3}
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                />
                            </div>
                        </div>
                        <div className="mt-3 flex items-center gap-2">
                            <Button
                                className="bg-primary text-primary-foreground hover:bg-primary"
                                onClick={submitForm}
                                disabled={
                                    form.processing ||
                                    !form.data.type ||
                                    (form.data.type === 'other' &&
                                        !customType.trim())
                                }
                            >
                                Save
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Header Row */}
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium">
                    All Assessments ({assessments.length})
                </span>
            </div>

            {/* List Items or Empty State */}
            {assessments.length === 0 ? (
                <Card className="border-dashed">
                    <CardContent className="flex flex-col items-center justify-center py-12">
                        <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10">
                            <ClipboardList className="h-7 w-7 text-primary" />
                        </div>
                        <p className="font-medium">No Assessments Recorded</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Clinical assessments and reviews will appear here.
                        </p>
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-3">
                    {assessments.map((a) => {
                        const isOverdue =
                            a.next_review_at &&
                            new Date(a.next_review_at) < now;
                        const isExpanded = expandedId === a.id;
                        const typeStyle = getTypeStyle(a.type);
                        return (
                            <Card
                                key={a.id}
                                className={`overflow-hidden border-l-4 ${typeStyle.border} ${isOverdue ? 'bg-status-critical-bg' : ''}`}
                            >
                                <CardContent className="p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-start gap-3">
                                            <div
                                                className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${typeStyle.bg} text-lg`}
                                            >
                                                {typeStyle.icon}
                                            </div>
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="text-sm font-semibold">
                                                        {typeStyle.label}
                                                    </span>
                                                    {a.score && (
                                                        <Badge className="border-0 bg-primary/10 text-xs font-bold text-primary">
                                                            Score: {a.score}
                                                        </Badge>
                                                    )}
                                                    {isOverdue && (
                                                        <Badge className="border-0 bg-status-critical-bg text-[9px] font-medium text-status-critical">
                                                            Review Overdue
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                    {a.assessed_at && (
                                                        <span className="flex items-center gap-1">
                                                            <Calendar className="h-3 w-3" />
                                                            {new Date(
                                                                a.assessed_at,
                                                            ).toLocaleDateString(
                                                                'en-NZ',
                                                            )}
                                                        </span>
                                                    )}
                                                    {a.next_review_at && (
                                                        <span
                                                            className={`flex items-center gap-1 ${isOverdue ? 'font-medium text-status-critical' : ''}`}
                                                        >
                                                            <Clock className="h-3 w-3" />
                                                            Review:{' '}
                                                            {new Date(
                                                                a.next_review_at,
                                                            ).toLocaleDateString(
                                                                'en-NZ',
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                        {canEdit && (
                                            <div className="flex shrink-0 items-center gap-1">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => startEdit(a)}
                                                >
                                                    <Pencil className="h-3.5 w-3.5" />
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-status-critical hover:text-status-critical"
                                                    onClick={() =>
                                                        setDeletingId(a.id)
                                                    }
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                    {a.notes && (
                                        <div className="mt-2 ml-12">
                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto p-0 text-xs text-primary hover:underline"
                                                onClick={() =>
                                                    setExpandedId(
                                                        isExpanded
                                                            ? null
                                                            : a.id,
                                                    )
                                                }
                                            >
                                                {isExpanded
                                                    ? 'Hide notes'
                                                    : 'Show notes'}
                                            </Button>
                                            {isExpanded && (
                                                <div className="mt-1.5 border-l-2 border-primary pl-3 text-xs whitespace-pre-wrap text-muted-foreground">
                                                    {a.notes}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            )}

            {/* Delete Confirmation Dialog */}
            <Dialog
                open={deletingId !== null}
                onOpenChange={(open) => {
                    if (!open) setDeletingId(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Assessment</DialogTitle>
                        <DialogDescription>
                            Confirm that this assessment should be removed from
                            the client record.
                        </DialogDescription>
                    </DialogHeader>
                    <p className="text-sm text-muted-foreground">
                        Are you sure you want to delete this assessment? This
                        action cannot be undone.
                    </p>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeletingId(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (deletingId) {
                                    router.delete(
                                        `/operations/clients/${clientId}/assessments/${deletingId}`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                setDeletingId(null),
                                        },
                                    );
                                }
                            }}
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export function PhotoGalleryTab({
    clientId,
    photos,
    canEdit,
}: {
    clientId: number;
    photos: GalleryPhoto[];
    canEdit: boolean;
}) {
    const [showUpload, setShowUpload] = useState(false);
    const photoForm = useForm<{
        photo: File | null;
        caption: string;
        visibility: string;
    }>({
        photo: null,
        caption: '',
        visibility: 'family',
    });
    const submitPhoto = (e: React.FormEvent) => {
        e.preventDefault();
        if (!photoForm.data.photo) return;
        photoForm.post(`/operations/clients/${clientId}/gallery-photos`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setShowUpload(false);
                photoForm.reset();
            },
        });
    };
    const deletePhoto = (photoId: number) => {
        if (!confirm('Delete this photo?')) return;
        router.delete(
            `/operations/clients/${clientId}/gallery-photos/${photoId}`,
            { preserveScroll: true },
        );
    };
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-base">
                    <span>Photo Gallery</span>
                    {canEdit && (
                        <Button
                            size="sm"
                            onClick={() => setShowUpload(!showUpload)}
                        >
                            {showUpload ? 'Cancel' : 'Upload Photo'}
                        </Button>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {showUpload && (
                    <form
                        onSubmit={submitPhoto}
                        className="space-y-3 rounded-lg border bg-muted/30 p-4"
                    >
                        <div>
                            <Label>Photo *</Label>
                            <Input
                                type="file"
                                accept="image/*"
                                onChange={(e) =>
                                    photoForm.setData(
                                        'photo',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </div>
                        <div>
                            <Label>Caption</Label>
                            <Input
                                value={photoForm.data.caption}
                                onChange={(e) =>
                                    photoForm.setData('caption', e.target.value)
                                }
                                placeholder="Add a caption..."
                            />
                        </div>
                        <div>
                            <Label>Visibility</Label>
                            <Select
                                value={photoForm.data.visibility}
                                onValueChange={(v) =>
                                    photoForm.setData('visibility', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="staff_only">
                                        Staff Only
                                    </SelectItem>
                                    <SelectItem value="family">
                                        Family & Staff
                                    </SelectItem>
                                    <SelectItem value="all_portal_users">
                                        All Portal Users
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Button
                            type="submit"
                            disabled={
                                photoForm.processing || !photoForm.data.photo
                            }
                        >
                            {photoForm.processing ? 'Uploading...' : 'Upload'}
                        </Button>
                    </form>
                )}

                {photos.length > 0 ? (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                        {photos.map((p) => (
                            <Card
                                key={p.id}
                                className="group relative gap-0 overflow-hidden rounded-lg p-0"
                            >
                                <a
                                    href={p.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <img
                                        src={p.thumbnail_url || p.url}
                                        alt={p.caption || p.original_name}
                                        className="aspect-square w-full object-cover"
                                        loading="lazy"
                                    />
                                </a>
                                <div className="p-2">
                                    {p.caption && (
                                        <p className="line-clamp-2 text-xs font-medium">
                                            {p.caption}
                                        </p>
                                    )}
                                    <div className="mt-1 flex flex-wrap items-center gap-1">
                                        <Badge className="h-4 border-0 bg-muted px-1 text-[8px] text-muted-foreground">
                                            {p.visibility.replace(/_/g, ' ')}
                                        </Badge>
                                        {p.status === 'pending_approval' && (
                                            <Badge className="h-4 border-0 bg-status-warning-bg px-1 text-[8px] text-status-warning">
                                                Pending
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="mt-1 text-[10px] text-muted-foreground">
                                        {p.uploaded_by} &middot;{' '}
                                        {new Date(
                                            p.created_at,
                                        ).toLocaleDateString()}
                                    </p>
                                </div>
                                {canEdit && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => deletePhoto(p.id)}
                                        className="absolute top-1 right-1 h-6 w-6 rounded-full bg-black/50 p-1 text-primary-foreground opacity-0 transition-opacity group-hover:opacity-100 hover:bg-status-critical"
                                        title="Delete photo"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            className="h-3 w-3"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2"
                                        >
                                            <line
                                                x1="18"
                                                y1="6"
                                                x2="6"
                                                y2="18"
                                            />
                                            <line
                                                x1="6"
                                                y1="6"
                                                x2="18"
                                                y2="18"
                                            />
                                        </svg>
                                    </Button>
                                )}
                            </Card>
                        ))}
                    </div>
                ) : (
                    <div className="py-12 text-center text-sm text-muted-foreground">
                        No photos yet. Upload the first one!
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

const ASSET_CATEGORIES: Record<
    string,
    { label: string; color: string; icon: string }
> = {
    mobility_aid: {
        label: 'Mobility Aid',
        color: 'bg-status-info-bg text-status-info',
        icon: '♿',
    },
    electronics: {
        label: 'Electronics',
        color: 'bg-primary/10 text-primary',
        icon: '📱',
    },
    furniture: {
        label: 'Furniture',
        color: 'bg-status-warning-bg text-status-warning',
        icon: '🪑',
    },
    clothing: {
        label: 'Clothing',
        color: 'bg-status-critical-bg text-status-critical',
        icon: '👕',
    },
    medical_equipment: {
        label: 'Medical Equipment',
        color: 'bg-status-critical-bg text-status-critical',
        icon: '🩺',
    },
    personal_care: {
        label: 'Personal Care',
        color: 'bg-status-info-bg text-status-info',
        icon: '🧴',
    },
    entertainment: {
        label: 'Entertainment',
        color: 'bg-primary/10 text-primary',
        icon: '🎮',
    },
    transport: {
        label: 'Transport',
        color: 'bg-status-success-bg text-status-success',
        icon: '🚗',
    },
    other: {
        label: 'Other',
        color: 'bg-muted text-muted-foreground',
        icon: '📦',
    },
};

const CONDITION_COLORS: Record<string, string> = {
    new: 'bg-status-success-bg text-status-success',
    good: 'bg-status-info-bg text-status-info',
    fair: 'bg-status-warning-bg text-status-warning',
    poor: 'bg-status-critical-bg text-status-critical',
};

const STATUS_CONFIG: Record<
    string,
    { label: string; color: string; dot: string }
> = {
    active: {
        label: 'Active',
        color: 'bg-status-success-bg text-status-success',
        dot: 'bg-status-success',
    },
    in_repair: {
        label: 'In Repair',
        color: 'bg-status-warning-bg text-status-warning',
        dot: 'bg-status-warning',
    },
    lost: {
        label: 'Lost',
        color: 'bg-status-critical-bg text-status-critical',
        dot: 'bg-status-critical',
    },
    damaged: {
        label: 'Damaged',
        color: 'bg-status-warning-bg text-status-warning',
        dot: 'bg-status-warning',
    },
    disposed: {
        label: 'Disposed',
        color: 'bg-muted text-muted-foreground',
        dot: 'bg-muted',
    },
    returned: {
        label: 'Returned',
        color: 'bg-primary/10 text-primary',
        dot: 'bg-primary',
    },
};

const OWNERSHIP_CONFIG: Record<string, { label: string; color: string }> = {
    client: {
        label: 'Client Owned',
        color: 'bg-status-info-bg text-status-info',
    },
    provider: {
        label: 'Provider Owned',
        color: 'bg-primary/10 text-primary',
    },
    funded: {
        label: 'Funded',
        color: 'bg-status-success-bg text-status-success',
    },
    loaned: {
        label: 'On Loan',
        color: 'bg-status-warning-bg text-status-warning',
    },
};

export function PersonalAssetsTab({
    clientId,
    assets,
    canEdit,
    firstName,
    locations,
    clientSiteId,
    availableTrackers,
}: {
    clientId: number;
    assets: PersonalAsset[];
    canEdit: boolean;
    firstName: string;
    locations: AssetLocation[];
    clientSiteId: number | null;
    availableTrackers: AvailableTracker[];
}) {
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const [filterCategory, setFilterCategory] = useState('all');
    const [filterStatus, setFilterStatus] = useState('all');
    const [sortBy, setSortBy] = useState<
        'name' | 'value' | 'acquired' | 'added'
    >('added');
    const [groupByCategory, setGroupByCategory] = useState(false);

    const form = useForm<{
        name: string;
        category: string;
        description: string;
        serial_number: string;
        estimated_value: string;
        condition: string;
        location: string;
        site_id: string;
        room_id: string;
        tracker_hardware_id: string;
        photo: File | null;
        acquired_at: string;
        notes: string;
        status: string;
        ownership: string;
        funding_source: string;
        return_required: boolean;
        return_by: string;
        last_serviced_at: string;
        next_service_due: string;
        service_provider: string;
        warranty_expires_at: string;
        insurance_reference: string;
        portal_visible: boolean;
    }>({
        name: '',
        category: '',
        description: '',
        serial_number: '',
        estimated_value: '',
        condition: '',
        location: '',
        site_id: clientSiteId ? String(clientSiteId) : '',
        room_id: '',
        tracker_hardware_id: '',
        photo: null,
        acquired_at: '',
        notes: '',
        status: 'active',
        ownership: 'client',
        funding_source: '',
        return_required: false,
        return_by: '',
        last_serviced_at: '',
        next_service_due: '',
        service_provider: '',
        warranty_expires_at: '',
        insurance_reference: '',
        portal_visible: false,
    });

    const resetForm = () => {
        form.reset();
        setShowForm(false);
        setEditingId(null);
    };

    const startEdit = (a: PersonalAsset) => {
        form.setData({
            name: a.name,
            category: a.category ?? '',
            description: a.description ?? '',
            serial_number: a.serial_number ?? '',
            estimated_value: a.estimated_value ?? '',
            condition: a.condition ?? '',
            location: a.location ?? '',
            site_id: a.site_id
                ? String(a.site_id)
                : clientSiteId
                  ? String(clientSiteId)
                  : '',
            room_id: a.room_id ? String(a.room_id) : '',
            tracker_hardware_id: a.tracker_hardware_id
                ? String(a.tracker_hardware_id)
                : '',
            photo: null,
            acquired_at: a.acquired_at ?? '',
            notes: a.notes ?? '',
            status: a.status ?? 'active',
            ownership: a.ownership ?? 'client',
            funding_source: a.funding_source ?? '',
            return_required: a.return_required ?? false,
            return_by: a.return_by ?? '',
            last_serviced_at: a.last_serviced_at ?? '',
            next_service_due: a.next_service_due ?? '',
            service_provider: a.service_provider ?? '',
            warranty_expires_at: a.warranty_expires_at ?? '',
            insurance_reference: a.insurance_reference ?? '',
            portal_visible: a.portal_visible ?? false,
        });
        setEditingId(a.id);
        setShowForm(true);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingId) {
            router.post(
                `/operations/clients/${clientId}/personal-assets/${editingId}`,
                {
                    ...form.data,
                    _method: 'PUT',
                },
                {
                    preserveScroll: true,
                    onSuccess: () => resetForm(),
                    forceFormData: true,
                },
            );
        } else {
            form.post(`/operations/clients/${clientId}/personal-assets`, {
                preserveScroll: true,
                onSuccess: () => resetForm(),
                forceFormData: true,
            });
        }
    };

    const changeStatus = (assetId: number, newStatus: string) => {
        router.patch(
            `/operations/clients/${clientId}/personal-assets/${assetId}/status`,
            { status: newStatus },
            { preserveScroll: true },
        );
    };

    // Computed stats
    const activeAssets = assets.filter((a) => a.status === 'active');
    const totalValue = activeAssets.reduce(
        (sum, a) => sum + (parseFloat(a.estimated_value ?? '0') || 0),
        0,
    );
    const needsAttention = assets.filter(
        (a) =>
            a.is_service_overdue ||
            a.is_warranty_expired ||
            a.is_warranty_expiring_soon ||
            a.is_return_overdue ||
            a.condition === 'poor',
    ).length;
    const categories = new Set(assets.map((a) => a.category).filter(Boolean));

    // Filter & sort
    const filtered = assets
        .filter((a) => {
            if (filterCategory !== 'all' && a.category !== filterCategory)
                return false;
            if (filterStatus !== 'all' && a.status !== filterStatus)
                return false;
            if (search) {
                const q = search.toLowerCase();
                return (
                    a.name.toLowerCase().includes(q) ||
                    (a.description ?? '').toLowerCase().includes(q) ||
                    (a.serial_number ?? '').toLowerCase().includes(q) ||
                    (a.location ?? '').toLowerCase().includes(q) ||
                    (a.site_name ?? '').toLowerCase().includes(q) ||
                    (a.room_name ?? '').toLowerCase().includes(q)
                );
            }
            return true;
        })
        .sort((a, b) => {
            if (sortBy === 'name') return a.name.localeCompare(b.name);
            if (sortBy === 'value')
                return (
                    (parseFloat(b.estimated_value ?? '0') || 0) -
                    (parseFloat(a.estimated_value ?? '0') || 0)
                );
            if (sortBy === 'acquired')
                return (b.acquired_at ?? '').localeCompare(a.acquired_at ?? '');
            return (b.created_at ?? '').localeCompare(a.created_at ?? '');
        });

    // Group by category
    const grouped = groupByCategory
        ? filtered.reduce(
              (acc: Record<string, PersonalAsset[]>, a) => {
                  const key = a.category || 'other';
                  if (!acc[key]) acc[key] = [];
                  acc[key].push(a);
                  return acc;
              },
              {} as Record<string, PersonalAsset[]>,
          )
        : { all: filtered };

    const renderAssetCard = (a: PersonalAsset) => {
        const cat = ASSET_CATEGORIES[a.category ?? ''];
        const stat = (STATUS_CONFIG[a.status] ?? STATUS_CONFIG.active)!;
        const own = OWNERSHIP_CONFIG[a.ownership ?? 'client'];
        const hasAlerts =
            a.is_service_overdue ||
            a.is_warranty_expired ||
            a.is_warranty_expiring_soon ||
            a.is_return_overdue;

        return (
            <Card
                key={a.id}
                className={`group relative overflow-hidden transition-all hover:shadow-md ${hasAlerts ? 'border-status-warning/30' : ''} ${a.status !== 'active' ? 'opacity-75' : ''}`}
            >
                {/* Photo or category icon header */}
                {a.photo_url ? (
                    <div className="relative h-36 overflow-hidden bg-muted">
                        <img
                            src={a.photo_url}
                            alt={a.name}
                            className="h-full w-full object-cover"
                        />
                        <div className="absolute top-2 left-2 flex gap-1">
                            <span
                                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${stat.color} shadow-sm`}
                            >
                                <span
                                    className={`h-1.5 w-1.5 rounded-full ${stat.dot}`}
                                />
                                {stat.label}
                            </span>
                        </div>
                        {a.portal_visible && (
                            <div className="absolute top-2 right-2">
                                <span className="rounded-full bg-status-info px-1.5 py-0.5 text-[9px] font-medium text-primary-foreground shadow-sm">
                                    Portal
                                </span>
                            </div>
                        )}
                    </div>
                ) : (
                    <div
                        className={`relative flex h-20 items-center justify-center ${cat ? cat.color.replace('text-', 'bg-').split(' ')[0] : 'bg-muted'}`}
                    >
                        <span className="text-3xl">{cat?.icon ?? '📦'}</span>
                        <div className="absolute top-2 left-2 flex gap-1">
                            <span
                                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${stat.color} shadow-sm`}
                            >
                                <span
                                    className={`h-1.5 w-1.5 rounded-full ${stat.dot}`}
                                />
                                {stat.label}
                            </span>
                        </div>
                        {a.portal_visible && (
                            <div className="absolute top-2 right-2">
                                <span className="rounded-full bg-status-info px-1.5 py-0.5 text-[9px] font-medium text-primary-foreground shadow-sm">
                                    Portal
                                </span>
                            </div>
                        )}
                    </div>
                )}

                {/* Alert banner */}
                {hasAlerts && (
                    <div className="flex flex-wrap gap-1.5 border-b border-status-warning/30 bg-status-warning-bg px-3 py-1.5">
                        {a.is_service_overdue && (
                            <span className="text-[10px] font-medium text-status-warning">
                                Service overdue
                            </span>
                        )}
                        {a.is_warranty_expired && (
                            <span className="text-[10px] font-medium text-status-critical">
                                Warranty expired
                            </span>
                        )}
                        {a.is_warranty_expiring_soon &&
                            !a.is_warranty_expired && (
                                <span className="text-[10px] font-medium text-status-warning">
                                    Warranty expiring soon
                                </span>
                            )}
                        {a.is_return_overdue && (
                            <span className="text-[10px] font-medium text-status-critical">
                                Return overdue
                            </span>
                        )}
                    </div>
                )}

                <CardContent className="space-y-2.5 pt-3">
                    <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                            <h4 className="truncate text-sm font-semibold">
                                {a.name}
                            </h4>
                            <div className="mt-1 flex flex-wrap gap-1">
                                {cat && (
                                    <Badge
                                        className={`border-0 text-[10px] ${cat.color}`}
                                    >
                                        {cat.icon} {cat.label}
                                    </Badge>
                                )}
                                {a.condition && (
                                    <Badge
                                        className={`border-0 text-[10px] ${CONDITION_COLORS[a.condition] ?? 'bg-muted text-muted-foreground'}`}
                                    >
                                        {a.condition}
                                    </Badge>
                                )}
                                {own && a.ownership !== 'client' && (
                                    <Badge
                                        className={`border-0 text-[10px] ${own.color}`}
                                    >
                                        {own.label}
                                    </Badge>
                                )}
                            </div>
                        </div>
                        {canEdit && (
                            <div className="flex shrink-0 gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 w-7 p-0"
                                    onClick={() => startEdit(a)}
                                >
                                    <Pencil className="h-3.5 w-3.5" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 w-7 p-0 text-status-critical hover:text-status-critical"
                                    onClick={() => {
                                        if (
                                            confirm(
                                                `Remove "${a.name}" from personal assets?`,
                                            )
                                        ) {
                                            router.delete(
                                                `/operations/clients/${clientId}/personal-assets/${a.id}`,
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        )}
                    </div>

                    {a.description && (
                        <p className="line-clamp-2 text-xs text-muted-foreground">
                            {a.description}
                        </p>
                    )}

                    <div className="space-y-1 text-xs text-muted-foreground">
                        {a.estimated_value &&
                            parseFloat(a.estimated_value) > 0 && (
                                <div className="flex items-center gap-1.5">
                                    <DollarSign className="h-3 w-3" />
                                    <span className="font-medium text-foreground">
                                        $
                                        {parseFloat(
                                            a.estimated_value,
                                        ).toLocaleString('en-NZ', {
                                            minimumFractionDigits: 2,
                                        })}
                                    </span>
                                </div>
                            )}
                        {(a.site_name || a.room_name || a.location) && (
                            <div className="flex items-center gap-1.5">
                                <MapPin className="h-3 w-3" />
                                <span>
                                    {[a.site_name, a.room_name]
                                        .filter(Boolean)
                                        .join(' · ') || a.location}
                                </span>
                            </div>
                        )}
                        {a.serial_number && (
                            <div className="flex items-center gap-1.5">
                                <FileText className="h-3 w-3" />
                                <span className="font-mono text-[10px]">
                                    {a.serial_number}
                                </span>
                            </div>
                        )}
                        {a.funding_source && (
                            <div className="flex items-center gap-1.5">
                                <DollarSign className="h-3 w-3" />
                                <span>Funded by {a.funding_source}</span>
                            </div>
                        )}
                        {a.next_service_due && (
                            <div
                                className={`flex items-center gap-1.5 ${a.is_service_overdue ? 'font-medium text-status-warning' : ''}`}
                            >
                                <Clock className="h-3 w-3" />
                                <span>
                                    Service {a.is_service_overdue ? 'was' : ''}{' '}
                                    due{' '}
                                    {new Date(
                                        a.next_service_due,
                                    ).toLocaleDateString('en-NZ')}
                                </span>
                            </div>
                        )}
                        {a.warranty_expires_at && (
                            <div
                                className={`flex items-center gap-1.5 ${a.is_warranty_expired ? 'font-medium text-status-critical' : a.is_warranty_expiring_soon ? 'font-medium text-status-warning' : ''}`}
                            >
                                <Shield className="h-3 w-3" />
                                <span>
                                    Warranty{' '}
                                    {a.is_warranty_expired
                                        ? 'expired'
                                        : 'expires'}{' '}
                                    {new Date(
                                        a.warranty_expires_at,
                                    ).toLocaleDateString('en-NZ')}
                                </span>
                            </div>
                        )}
                        {a.return_required && a.return_by && (
                            <div
                                className={`flex items-center gap-1.5 ${a.is_return_overdue ? 'font-medium text-status-critical' : ''}`}
                            >
                                <AlertTriangle className="h-3 w-3" />
                                <span>
                                    Return by{' '}
                                    {new Date(a.return_by).toLocaleDateString(
                                        'en-NZ',
                                    )}
                                </span>
                            </div>
                        )}
                        {a.acquired_at && (
                            <div className="flex items-center gap-1.5">
                                <Calendar className="h-3 w-3" />
                                <span>
                                    Acquired{' '}
                                    {new Date(a.acquired_at).toLocaleDateString(
                                        'en-NZ',
                                    )}
                                </span>
                            </div>
                        )}
                    </div>

                    {/* Tracker info */}
                    {a.tracker && (
                        <div className="space-y-1 rounded-lg border border-status-info/30 bg-status-info-bg p-2">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-1.5">
                                    <span className="text-xs">📡</span>
                                    <span className="text-[11px] font-medium text-status-info">
                                        {a.tracker.name}
                                    </span>
                                </div>
                                <span
                                    className={`inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[9px] font-medium ${a.tracker.status === 'online' ? 'bg-status-success-bg text-status-success' : 'bg-muted text-muted-foreground'}`}
                                >
                                    <span
                                        className={`h-1.5 w-1.5 rounded-full ${a.tracker.status === 'online' ? 'bg-status-success' : 'bg-muted'}`}
                                    />
                                    {a.tracker.status}
                                </span>
                            </div>
                            <div className="flex flex-wrap gap-2 text-[10px] text-status-info">
                                {a.tracker.battery != null && (
                                    <span>Battery: {a.tracker.battery}%</span>
                                )}
                                {a.tracker.speed != null && (
                                    <span>Speed: {a.tracker.speed} km/h</span>
                                )}
                                {a.tracker.last_seen_at && (
                                    <span>
                                        Seen:{' '}
                                        {new Date(
                                            a.tracker.last_seen_at,
                                        ).toLocaleString('en-NZ', {
                                            day: 'numeric',
                                            month: 'short',
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })}
                                    </span>
                                )}
                            </div>
                        </div>
                    )}

                    {a.notes && (
                        <p className="line-clamp-2 rounded-lg bg-muted p-2 text-[11px] text-muted-foreground">
                            {a.notes}
                        </p>
                    )}

                    {/* Quick status actions */}
                    {canEdit && a.status === 'active' && (
                        <div className="flex flex-wrap gap-1 pt-1 opacity-0 transition-opacity group-hover:opacity-100">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => changeStatus(a.id, 'in_repair')}
                                className="h-auto rounded-full bg-status-warning-bg px-2 py-0.5 text-[10px] font-medium text-status-warning transition-colors hover:bg-status-warning-bg"
                            >
                                In Repair
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => changeStatus(a.id, 'lost')}
                                className="h-auto rounded-full bg-status-critical-bg px-2 py-0.5 text-[10px] font-medium text-status-critical transition-colors hover:bg-status-critical-bg"
                            >
                                Lost
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => changeStatus(a.id, 'damaged')}
                                className="h-auto rounded-full bg-status-warning-bg px-2 py-0.5 text-[10px] font-medium text-status-warning transition-colors hover:bg-status-warning-bg"
                            >
                                Damaged
                            </Button>
                        </div>
                    )}
                    {canEdit && a.status === 'in_repair' && (
                        <div className="flex flex-wrap gap-1 pt-1">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => changeStatus(a.id, 'active')}
                                className="h-auto rounded-full bg-status-success-bg px-2 py-0.5 text-[10px] font-medium text-status-success transition-colors hover:bg-status-success-bg"
                            >
                                Repaired
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => changeStatus(a.id, 'disposed')}
                                className="h-auto rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:bg-muted"
                            >
                                Dispose
                            </Button>
                        </div>
                    )}
                    {canEdit &&
                        (a.status === 'lost' || a.status === 'damaged') && (
                            <div className="flex flex-wrap gap-1 pt-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => changeStatus(a.id, 'active')}
                                    className="h-auto rounded-full bg-status-success-bg px-2 py-0.5 text-[10px] font-medium text-status-success transition-colors hover:bg-status-success-bg"
                                >
                                    Found / Restored
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() =>
                                        changeStatus(a.id, 'disposed')
                                    }
                                    className="h-auto rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:bg-muted"
                                >
                                    Dispose
                                </Button>
                            </div>
                        )}

                    <div className="flex items-center justify-between pt-0.5 text-[10px] text-muted-foreground">
                        {a.recorded_by && <span>By {a.recorded_by}</span>}
                        {a.created_at && (
                            <span>
                                {new Date(a.created_at).toLocaleDateString(
                                    'en-NZ',
                                )}
                            </span>
                        )}
                    </div>
                </CardContent>
            </Card>
        );
    };

    return (
        <div className="space-y-4">
            {/* Gradient stat cards */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="rounded-xl border bg-primary/10 p-3 text-center">
                    <div className="text-xl font-bold text-primary">
                        {activeAssets.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-primary uppercase">
                        Active Items
                    </div>
                </div>
                <div className="rounded-xl border bg-primary/10 p-3 text-center">
                    <div className="text-xl font-bold text-status-success">
                        $
                        {totalValue > 0
                            ? totalValue.toLocaleString('en-NZ', {
                                  minimumFractionDigits: 0,
                                  maximumFractionDigits: 0,
                              })
                            : '0'}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-success uppercase">
                        Est. Value (NZD)
                    </div>
                </div>
                <div
                    className={`rounded-xl border p-3 text-center ${needsAttention > 0 ? 'bg-status-warning-bg' : 'bg-primary/10'}`}
                >
                    <div
                        className={`text-xl font-bold ${needsAttention > 0 ? 'text-status-warning' : 'text-muted-foreground'}`}
                    >
                        {needsAttention}
                    </div>
                    <div
                        className={`text-[10px] tracking-wider uppercase ${needsAttention > 0 ? 'text-status-warning' : 'text-muted-foreground'}`}
                    >
                        Needs Attention
                    </div>
                </div>
                <div className="rounded-xl border bg-status-info-bg p-3 text-center">
                    <div className="text-xl font-bold text-status-info">
                        {categories.size}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-info uppercase">
                        Categories
                    </div>
                </div>
            </div>

            {/* Toolbar: search, filters, sort, add button */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-1 flex-wrap items-center gap-2">
                    <div className="relative flex-1 sm:max-w-xs">
                        <Search className="absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search assets..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="h-9 pl-9"
                        />
                    </div>
                    <Select
                        value={filterCategory}
                        onValueChange={setFilterCategory}
                    >
                        <SelectTrigger className="h-9 w-[140px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Categories</SelectItem>
                            {Object.entries(ASSET_CATEGORIES).map(([k, v]) => (
                                <SelectItem key={k} value={k}>
                                    {v.icon} {v.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filterStatus}
                        onValueChange={setFilterStatus}
                    >
                        <SelectTrigger className="h-9 w-[130px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {Object.entries(STATUS_CONFIG).map(([k, v]) => (
                                <SelectItem key={k} value={k}>
                                    {v.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={sortBy}
                        onValueChange={(v) => setSortBy(v as any)}
                    >
                        <SelectTrigger className="h-9 w-[120px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="added">Newest</SelectItem>
                            <SelectItem value="name">Name</SelectItem>
                            <SelectItem value="value">Value</SelectItem>
                            <SelectItem value="acquired">Acquired</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        variant={groupByCategory ? 'default' : 'outline'}
                        size="sm"
                        className="h-9 gap-1.5 text-xs"
                        onClick={() => setGroupByCategory(!groupByCategory)}
                    >
                        <Package className="h-3.5 w-3.5" />
                        Group
                    </Button>
                </div>
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-9 gap-1.5 text-xs"
                        onClick={() => window.print()}
                    >
                        <FileText className="h-3.5 w-3.5" />
                        Print Register
                    </Button>
                    {canEdit && (
                        <Button
                            size="sm"
                            className="h-9 gap-1.5 bg-primary hover:bg-primary"
                            onClick={() => {
                                resetForm();
                                setShowForm(true);
                            }}
                        >
                            <Plus className="h-3.5 w-3.5" />
                            Add Asset
                        </Button>
                    )}
                </div>
            </div>

            {/* Add/Edit form */}
            {showForm && canEdit && (
                <Card className="border-primary">
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <Package className="h-4 w-4" />
                            </div>
                            {editingId ? 'Edit Asset' : 'Add Personal Asset'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            {/* Basic Info */}
                            <div>
                                <p className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Basic Information
                                </p>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <Label>Name *</Label>
                                        <Input
                                            value={form.data.name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. Wheelchair, PlayStation, TV"
                                        />
                                        {form.errors.name && (
                                            <p className="mt-1 text-xs text-status-critical">
                                                {form.errors.name}
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <Label>Category</Label>
                                        <Select
                                            value={form.data.category}
                                            onValueChange={(v) =>
                                                form.setData('category', v)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select category" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    ASSET_CATEGORIES,
                                                ).map(([k, v]) => (
                                                    <SelectItem
                                                        key={k}
                                                        value={k}
                                                    >
                                                        {v.icon} {v.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Condition</Label>
                                        <Select
                                            value={form.data.condition}
                                            onValueChange={(v) =>
                                                form.setData('condition', v)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select condition" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="new">
                                                    New
                                                </SelectItem>
                                                <SelectItem value="good">
                                                    Good
                                                </SelectItem>
                                                <SelectItem value="fair">
                                                    Fair
                                                </SelectItem>
                                                <SelectItem value="poor">
                                                    Poor
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Serial / Model Number</Label>
                                        <Input
                                            value={form.data.serial_number}
                                            onChange={(e) =>
                                                form.setData(
                                                    'serial_number',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Estimated Value (NZD)</Label>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={form.data.estimated_value}
                                            onChange={(e) =>
                                                form.setData(
                                                    'estimated_value',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="0.00"
                                        />
                                    </div>
                                    <div>
                                        <Label>Site / Location</Label>
                                        <Select
                                            value={form.data.site_id}
                                            onValueChange={(v) => {
                                                form.setData('site_id', v);
                                                form.setData('room_id', '');
                                            }}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select site" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {locations.map((s) => (
                                                    <SelectItem
                                                        key={s.id}
                                                        value={String(s.id)}
                                                    >
                                                        {s.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Room</Label>
                                        {(() => {
                                            const selectedSite = locations.find(
                                                (s) =>
                                                    String(s.id) ===
                                                    form.data.site_id,
                                            );
                                            const rooms =
                                                selectedSite?.rooms ?? [];
                                            return rooms.length > 0 ? (
                                                <Select
                                                    value={form.data.room_id}
                                                    onValueChange={(v) =>
                                                        form.setData(
                                                            'room_id',
                                                            v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select room" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {rooms.map((r) => (
                                                            <SelectItem
                                                                key={r.id}
                                                                value={String(
                                                                    r.id,
                                                                )}
                                                            >
                                                                {r.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                <Input
                                                    disabled
                                                    placeholder={
                                                        form.data.site_id
                                                            ? 'No rooms at this site'
                                                            : 'Select a site first'
                                                    }
                                                />
                                            );
                                        })()}
                                    </div>
                                    <div>
                                        <Label>Acquired Date</Label>
                                        <Input
                                            type="date"
                                            value={form.data.acquired_at}
                                            onChange={(e) =>
                                                form.setData(
                                                    'acquired_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Photo</Label>
                                        <Input
                                            type="file"
                                            accept="image/*"
                                            onChange={(e) =>
                                                form.setData(
                                                    'photo',
                                                    e.target.files?.[0] ?? null,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            {/* Ownership & Funding */}
                            <div>
                                <p className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Ownership & Funding
                                </p>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <Label>Ownership</Label>
                                        <Select
                                            value={form.data.ownership}
                                            onValueChange={(v) =>
                                                form.setData('ownership', v)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    OWNERSHIP_CONFIG,
                                                ).map(([k, v]) => (
                                                    <SelectItem
                                                        key={k}
                                                        value={k}
                                                    >
                                                        {v.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Funding Source</Label>
                                        <Input
                                            value={form.data.funding_source}
                                            onChange={(e) =>
                                                form.setData(
                                                    'funding_source',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. NASC, MOH, Family"
                                        />
                                    </div>
                                    <div className="flex items-end gap-4">
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                checked={
                                                    form.data.return_required
                                                }
                                                onCheckedChange={(v) =>
                                                    form.setData(
                                                        'return_required',
                                                        !!v,
                                                    )
                                                }
                                                id="return_required"
                                            />
                                            <Label
                                                htmlFor="return_required"
                                                className="text-sm"
                                            >
                                                Return required
                                            </Label>
                                        </div>
                                    </div>
                                    {form.data.return_required && (
                                        <div>
                                            <Label>Return By</Label>
                                            <Input
                                                type="date"
                                                value={form.data.return_by}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'return_by',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>

                            <Separator />

                            {/* Service & Warranty */}
                            <div>
                                <p className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Service & Warranty
                                </p>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <Label>Last Serviced</Label>
                                        <Input
                                            type="date"
                                            value={form.data.last_serviced_at}
                                            onChange={(e) =>
                                                form.setData(
                                                    'last_serviced_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Next Service Due</Label>
                                        <Input
                                            type="date"
                                            value={form.data.next_service_due}
                                            onChange={(e) =>
                                                form.setData(
                                                    'next_service_due',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Service Provider</Label>
                                        <Input
                                            value={form.data.service_provider}
                                            onChange={(e) =>
                                                form.setData(
                                                    'service_provider',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. Enable NZ"
                                        />
                                    </div>
                                    <div>
                                        <Label>Warranty Expires</Label>
                                        <Input
                                            type="date"
                                            value={
                                                form.data.warranty_expires_at
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'warranty_expires_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Insurance Reference</Label>
                                        <Input
                                            value={
                                                form.data.insurance_reference
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'insurance_reference',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>GPS Tracker</Label>
                                        <Select
                                            value={
                                                form.data.tracker_hardware_id ||
                                                'none'
                                            }
                                            onValueChange={(v) =>
                                                form.setData(
                                                    'tracker_hardware_id',
                                                    v === 'none' ? '' : v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="No tracker assigned" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">
                                                    None
                                                </SelectItem>
                                                {availableTrackers.map((t) => (
                                                    <SelectItem
                                                        key={t.id}
                                                        value={String(t.id)}
                                                    >
                                                        {t.name}
                                                        {t.serial
                                                            ? ` (${t.serial})`
                                                            : ''}{' '}
                                                        — {t.status}
                                                        {t.battery != null
                                                            ? ` ${t.battery}%`
                                                            : ''}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="flex items-end">
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                checked={
                                                    form.data.portal_visible
                                                }
                                                onCheckedChange={(v) =>
                                                    form.setData(
                                                        'portal_visible',
                                                        !!v,
                                                    )
                                                }
                                                id="portal_visible"
                                            />
                                            <Label
                                                htmlFor="portal_visible"
                                                className="text-sm"
                                            >
                                                Visible on family portal
                                            </Label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            {/* Description & Notes */}
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <Label>Description</Label>
                                    <Textarea
                                        rows={3}
                                        value={form.data.description}
                                        onChange={(e) =>
                                            form.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Brief description of the item"
                                    />
                                </div>
                                <div>
                                    <Label>Notes</Label>
                                    <Textarea
                                        rows={3}
                                        value={form.data.notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'notes',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Any additional notes"
                                    />
                                </div>
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="bg-primary hover:bg-primary"
                                >
                                    {editingId ? 'Update Asset' : 'Add Asset'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={resetForm}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}

            {/* Asset grid */}
            {assets.length === 0 && !showForm ? (
                <Card className="border-dashed">
                    <CardContent className="flex flex-col items-center justify-center py-12">
                        <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10">
                            <Package className="h-7 w-7 text-primary" />
                        </div>
                        <p className="font-medium">No Personal Assets</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Track {firstName}'s belongings like wheelchairs,
                            electronics, and other items.
                        </p>
                        {canEdit && (
                            <Button
                                size="sm"
                                className="mt-3 gap-1.5 bg-primary hover:bg-primary"
                                onClick={() => setShowForm(true)}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Add First Asset
                            </Button>
                        )}
                    </CardContent>
                </Card>
            ) : filtered.length === 0 ? (
                <Card className="border-dashed">
                    <CardContent className="flex flex-col items-center justify-center py-8">
                        <Search className="mb-2 h-8 w-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            No assets match your filters
                        </p>
                        <Button
                            variant="link"
                            size="sm"
                            onClick={() => {
                                setSearch('');
                                setFilterCategory('all');
                                setFilterStatus('all');
                            }}
                        >
                            Clear filters
                        </Button>
                    </CardContent>
                </Card>
            ) : groupByCategory ? (
                <div className="space-y-4">
                    {Object.entries(grouped).map(([catKey, catAssets]) => {
                        const catConfig = ASSET_CATEGORIES[catKey];
                        return (
                            <div key={catKey}>
                                <div className="mb-2 flex items-center gap-2">
                                    <span className="text-lg">
                                        {catConfig?.icon ?? '📦'}
                                    </span>
                                    <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        {catConfig?.label ?? 'Other'}
                                    </span>
                                    <Badge
                                        variant="secondary"
                                        className="text-[10px]"
                                    >
                                        {catAssets.length}
                                    </Badge>
                                </div>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {catAssets.map(renderAssetCard)}
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {filtered.map(renderAssetCard)}
                </div>
            )}
        </div>
    );
}

// ─── Calendar Tab ────────────────────────────────────
const CAL_STYLES = `
.fc { --fc-border-color: transparent; --fc-today-bg-color: transparent; --fc-neutral-bg-color: transparent; --fc-page-bg-color: transparent; --fc-non-business-color: transparent; font-family: inherit; }
.fc .fc-scrollgrid, .fc .fc-scrollgrid-section > td, .fc .fc-scrollgrid-section > th { border: none !important; }
.fc table, .fc th, .fc td { border: none !important; }
.fc .fc-col-header { margin-bottom: 0.25rem; }
.fc .fc-col-header-cell { padding: 0.5rem 0; vertical-align: middle; }
.fc .fc-col-header-cell-cushion { display: flex; flex-direction: column; align-items: center; gap: 4px; text-decoration: none !important; padding: 0.375rem 0.75rem; border-radius: 1rem; }
.fc .fc-col-header-cell-cushion .fc-col-header-cell-content, .fc .fc-col-header-cell-cushion { font-weight: 500; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: hsl(var(--muted-foreground) / 0.6); }
.fc .fc-day-today .fc-col-header-cell-cushion { background: hsl(var(--primary)); color: white !important; border-radius: 1rem; font-weight: 700; }
.fc .fc-timegrid-axis-cushion, .fc .fc-timegrid-slot-label-cushion { font-size: 0.7rem; font-weight: 500; color: hsl(var(--muted-foreground) / 0.45); padding-right: 0.75rem; }
.fc .fc-timegrid-slot { height: 2.5em; }
.fc .fc-timegrid-slot-lane { border-top: 1px dotted rgba(139, 92, 246, 0.12) !important; }
.fc .fc-timegrid-slot-minor { border-top: 1px dotted rgba(139, 92, 246, 0.06) !important; }
.fc .fc-timegrid-col { border-right: 1px dotted rgba(139, 92, 246, 0.1) !important; }
.fc .fc-timegrid-col:last-child { border-right: none !important; }
.fc .fc-timegrid-divider, .fc .fc-timegrid-axis, .fc .fc-timegrid-body, .fc .fc-timegrid-slots td, .fc .fc-timegrid-slot-label { border: none !important; }
.fc .fc-timegrid-slots tr:not(:first-child) .fc-timegrid-slot-lane { border-top: 1px solid hsl(var(--border) / 0.1) !important; }
.fc .fc-event, .fc .fc-event-mirror { border: none !important; border-radius: 0.5rem !important; cursor: pointer; transition: all 0.15s ease; overflow: hidden; }
.fc .fc-event:hover { transform: scale(1.01); z-index: 10 !important; box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
.fc .fc-timegrid-event { border-radius: 0.5rem !important; margin: 1px 3px; min-height: 1.25em; border-left: 3px solid rgba(0,0,0,0.15) !important; }
.fc .fc-timegrid-event .fc-event-main { padding: 0.2rem 0.4rem; font-size: 0.7rem; line-height: 1.3; }
.fc .fc-daygrid-event { border-radius: 0.375rem !important; padding: 1px 6px; margin: 1px 2px; font-size: 0.7rem; line-height: 1.4; }
.fc .fc-daygrid-body { border: none !important; }
.fc .fc-scrollgrid-section-header td { border-bottom: 1px solid hsl(var(--border) / 0.15) !important; }
.fc .fc-highlight { background: hsl(var(--primary) / 0.06) !important; border: 2px dashed hsl(var(--primary) / 0.25) !important; border-radius: 0.625rem; }
.fc .fc-now-indicator-line { border-color: #ef4444 !important; border-width: 2px !important; z-index: 4; }
.fc .fc-now-indicator-arrow { border-color: #ef4444 !important; border-width: 5px !important; }
.fc .fc-day-today { background: hsl(var(--primary) / 0.02) !important; }
.fc .fc-daygrid-day-number { font-weight: 700; font-size: 0.85rem; padding: 0.375rem; color: hsl(var(--foreground)); }
.fc .fc-day-today .fc-daygrid-day-number { background: hsl(var(--primary)); color: white; border-radius: 9999px; width: 1.75rem; height: 1.75rem; display: inline-flex; align-items: center; justify-content: center; margin: 0.25rem; }
.fc .fc-daygrid-day { border-right: 1px dotted rgba(139, 92, 246, 0.1) !important; border-bottom: 1px dotted rgba(139, 92, 246, 0.1) !important; min-height: 5rem; }
.fc .fc-more-link { font-size: 0.7rem; font-weight: 600; color: hsl(var(--primary)); padding: 2px 4px; }
.fc .fc-popover { background: white !important; border: 1px solid #e2e8f0 !important; border-radius: 0.75rem !important; box-shadow: 0 10px 40px rgba(0,0,0,0.2) !important; z-index: 9999 !important; overflow: hidden; }
.fc .fc-popover-header { background: #f1f5f9 !important; padding: 0.625rem 0.75rem !important; font-weight: 600 !important; font-size: 0.875rem !important; color: #1e293b !important; border-bottom: 1px solid #e2e8f0 !important; }
.fc .fc-popover-body { padding: 0.5rem !important; max-height: 300px; overflow-y: auto; background: white !important; }
.fc .fc-popover-body .fc-daygrid-event { margin: 2px 0 !important; }
.fc .fc-popover-close { color: #64748b !important; font-size: 1.25rem !important; }
.dark .fc .fc-popover { background: #1e293b !important; border-color: #334155 !important; }
.dark .fc .fc-popover-header { background: #0f172a !important; color: #e2e8f0 !important; border-bottom-color: #334155 !important; }
.dark .fc .fc-popover-body { background: #1e293b !important; }
.fc .fc-list { border: 1px solid hsl(var(--border) / 0.2) !important; border-radius: 1rem; overflow: hidden; }
.fc .fc-list-event:hover td { background-color: hsl(var(--accent)); }
.fc .fc-list-day-cushion { background: hsl(var(--muted) / 0.15); font-weight: 600; }
.fc .fc-daygrid-day-events { max-height: 6rem; overflow: hidden; }
.calendar-context-menu { position: fixed; z-index: 99999; min-width: 200px; background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; box-shadow: 0 10px 40px rgba(0,0,0,0.2); padding: 0.375rem; }
.calendar-context-menu button { display: flex; align-items: center; gap: 0.5rem; width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; transition: background 0.1s; text-align: left; border: none; background: none; cursor: pointer; color: #1e293b; }
.calendar-context-menu button:hover { background: #f1f5f9; }
.calendar-context-menu hr { margin: 0.25rem 0; border-color: #e2e8f0; }
.dark .calendar-context-menu { background: #1e293b; border-color: #334155; }
.dark .calendar-context-menu button { color: #e2e8f0; }
.dark .calendar-context-menu button:hover { background: #334155; }
`;

const CAL_CATEGORIES = [
    {
        dot: 'bg-status-info',
        label: 'Shifts',
        icon: CalendarDays,
        bg: 'bg-status-info-bg',
    },
    {
        dot: 'bg-status-success',
        label: 'Family Visits',
        icon: Users,
        bg: 'bg-status-success-bg',
    },
    {
        dot: 'bg-status-critical',
        label: 'Medications',
        icon: Pill,
        bg: 'bg-status-critical-bg dark:bg-status-critical-bg',
    },
    {
        dot: 'bg-status-warning',
        label: 'GP Visits',
        icon: Stethoscope,
        bg: 'bg-status-warning-bg',
    },
    {
        dot: 'bg-primary',
        label: 'Specialist',
        icon: Heart,
        bg: 'bg-primary/10 dark:bg-primary/40',
    },
    {
        dot: 'bg-status-info',
        label: 'Activities',
        icon: Calendar,
        bg: 'bg-status-info-bg',
    },
    {
        dot: 'bg-primary/70',
        label: 'Family Notes',
        icon: ListTodo,
        bg: 'bg-primary/10 dark:bg-primary/40',
    },
];

const CAL_APPT_TYPES = [
    { value: 'gp_visit', label: 'GP Visit' },
    { value: 'specialist', label: 'Specialist' },
    { value: 'therapy', label: 'Therapy' },
    { value: 'activity', label: 'Activity' },
    { value: 'reminder', label: 'Reminder' },
    { value: 'other', label: 'Other' },
];

type CalViewKey = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek';
const CAL_VIEWS: { key: CalViewKey; label: string }[] = [
    { key: 'dayGridMonth', label: 'Month' },
    { key: 'timeGridWeek', label: 'Week' },
    { key: 'timeGridDay', label: 'Day' },
    { key: 'listWeek', label: 'List' },
];

function pad2(n: number) {
    return String(n).padStart(2, '0');
}
function toLocalISO(d: Date) {
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

function renderCalEventContent(eventInfo: {
    event: any;
    view: any;
    timeText: string;
}) {
    const props = eventInfo.event.extendedProps;
    const isTime = eventInfo.view.type.includes('timeGrid');
    const isDay = eventInfo.view.type === 'timeGridDay';
    return (
        <div className="flex h-full flex-col overflow-hidden">
            <span
                className={`truncate leading-tight font-bold ${isDay ? 'text-sm' : 'text-xs'}`}
            >
                {eventInfo.event.title}
            </span>
            {isTime && (
                <span
                    className={`truncate opacity-70 ${isDay ? 'text-xs' : 'text-[10px]'}`}
                >
                    {eventInfo.timeText}
                </span>
            )}
            {isTime && props.location && (
                <span className="mt-auto flex items-center gap-0.5 truncate text-[10px] opacity-50">
                    <MapPin className="h-2.5 w-2.5 shrink-0" />
                    {props.location}
                </span>
            )}
        </div>
    );
}

export function ClientCalendarTab({
    clientId,
    clientFirstName,
    initialEvents = [],
}: {
    clientId: number;
    clientFirstName: string;
    initialEvents?: any[];
}) {
    const calRef = useRef<FullCalendar>(null);
    const [currentView, setCurrentView] = useState<CalViewKey>('timeGridWeek');
    const [calTitle, setCalTitle] = useState('');
    const [ctxMenu, setCtxMenu] = useState<{
        x: number;
        y: number;
        date: Date;
    } | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [calForm, setCalForm] = useState({
        title: '',
        appointment_type: 'gp_visit',
        starts_at: '',
        ends_at: '',
        location: '',
        provider_name: '',
        description: '',
        share_with_family: true,
    });
    const [detail, setDetail] = useState<any>(null);
    const [calEvents, setCalEvents] = useState<any[]>(initialEvents);

    useEffect(() => {
        const close = () => setCtxMenu(null);
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    const goToday = useCallback(() => calRef.current?.getApi().today(), []);
    const goPrev = useCallback(() => calRef.current?.getApi().prev(), []);
    const goNext = useCallback(() => calRef.current?.getApi().next(), []);
    const changeView = useCallback((view: CalViewKey) => {
        calRef.current?.getApi().changeView(view);
        setCurrentView(view);
    }, []);

    // Fetch new events when navigating to different date ranges
    const fetchEvents = useCallback(
        async (info: any, successCallback: any, failureCallback: any) => {
            // First try AJAX fetch, fall back to initial events
            try {
                const token = (
                    document.querySelector(
                        'meta[name="csrf-token"]',
                    ) as HTMLMetaElement | null
                )?.content;
                const params = new URLSearchParams({
                    start: info.startStr,
                    end: info.endStr,
                });
                const res = await fetch(
                    `/clients/${clientId}/calendar/events?${params.toString()}`,
                    {
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                        },
                    },
                );
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                successCallback(Array.isArray(data) ? data : []);
            } catch (e) {
                console.error('Calendar fetch error (using server data):', e);
                // Fall back to server-provided initial events
                successCallback(calEvents);
            }
        },
        [clientId, calEvents],
    );

    const submitAppointment = async () => {
        if (!calForm.title.trim() || !calForm.starts_at) return;
        const token = (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content;
        await fetch(`/clients/${clientId}/calendar/appointments`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            credentials: 'same-origin',
            body: JSON.stringify(calForm),
        });
        setCreateOpen(false);
        calRef.current?.getApi().refetchEvents();
    };

    const openCreateFromCtx = () => {
        if (ctxMenu) {
            const end = new Date(ctxMenu.date);
            end.setHours(end.getHours() + 1);
            setCalForm({
                ...calForm,
                starts_at: toLocalISO(ctxMenu.date),
                ends_at: toLocalISO(end),
                title: '',
                description: '',
                location: '',
                provider_name: '',
                appointment_type: 'gp_visit',
                share_with_family: true,
            });
        }
        setCtxMenu(null);
        setCreateOpen(true);
    };

    return (
        <div className="space-y-4">
            <style dangerouslySetInnerHTML={{ __html: CAL_STYLES }} />

            <div className="flex gap-5">
                {/* Sidebar */}
                <div className="hidden w-52 shrink-0 space-y-3 lg:block">
                    <Card className="overflow-hidden">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-semibold">
                                {clientFirstName}'s Calendar
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-0.5 pb-4">
                            {CAL_CATEGORIES.map((cat) => {
                                const Icon = cat.icon;
                                return (
                                    <div
                                        key={cat.label}
                                        className={`flex items-center gap-3 rounded-lg px-3 py-2 ${cat.bg}`}
                                    >
                                        <span
                                            className={`h-2.5 w-2.5 rounded-full ${cat.dot}`}
                                        />
                                        <Icon className="h-3.5 w-3.5 opacity-50" />
                                        <span className="text-sm font-medium">
                                            {cat.label}
                                        </span>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                </div>

                {/* Main */}
                <div className="min-w-0 flex-1">
                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-3">
                            <h2 className="text-xl font-bold tracking-tight">
                                {calTitle}
                            </h2>
                            <div className="flex items-center">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={goPrev}
                                    className="h-8 w-8 rounded-full transition-colors hover:bg-muted"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={goNext}
                                    className="h-8 w-8 rounded-full transition-colors hover:bg-muted"
                                >
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={goToday}
                                className="h-auto rounded-full px-4 py-1 text-sm font-semibold shadow-sm transition-colors hover:bg-accent"
                            >
                                Today
                            </Button>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                size="sm"
                                className="gap-1.5"
                                onClick={() => {
                                    setCalForm({
                                        ...calForm,
                                        starts_at: toLocalISO(new Date()),
                                        ends_at: '',
                                        title: '',
                                    });
                                    setCreateOpen(true);
                                }}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Schedule
                            </Button>
                            <div className="inline-flex items-center gap-1 rounded-full border bg-muted/20 p-1">
                                {CAL_VIEWS.map((v) => (
                                    <Button
                                        key={v.key}
                                        type="button"
                                        variant="ghost"
                                        onClick={() => changeView(v.key)}
                                        className={`h-auto rounded-full px-3 py-1 text-xs font-semibold transition-all ${currentView === v.key ? 'bg-foreground text-background shadow' : 'text-muted-foreground hover:text-foreground'}`}
                                    >
                                        {v.label}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div
                        className="overflow-hidden rounded-2xl border bg-card shadow-sm"
                        onContextMenu={(e) => {
                            const target = e.target as HTMLElement;
                            if (
                                !target.closest(
                                    '.fc-timegrid-slot-lane, .fc-daygrid-day, .fc-timegrid-col',
                                )
                            )
                                return;
                            e.preventDefault();
                            setCtxMenu({
                                x: e.clientX,
                                y: e.clientY,
                                date: new Date(),
                            });
                        }}
                    >
                        <FullCalendar
                            ref={calRef}
                            plugins={[
                                dayGridPlugin,
                                timeGridPlugin,
                                listPlugin,
                                interactionPlugin,
                            ]}
                            initialView="timeGridWeek"
                            headerToolbar={false}
                            events={fetchEvents}
                            eventClick={(info) =>
                                setDetail({
                                    title: info.event.title,
                                    start: info.event.start,
                                    end: info.event.end,
                                    ...info.event.extendedProps,
                                })
                            }
                            datesSet={(arg) => {
                                setCalTitle(arg.view.title);
                                setCurrentView(arg.view.type as CalViewKey);
                            }}
                            select={(arg) => {
                                setCalForm({
                                    ...calForm,
                                    starts_at: toLocalISO(arg.start),
                                    ends_at: toLocalISO(arg.end),
                                    title: '',
                                    description: '',
                                    location: '',
                                    provider_name: '',
                                    appointment_type: 'gp_visit',
                                    share_with_family: true,
                                });
                                setCreateOpen(true);
                                calRef.current?.getApi().unselect();
                            }}
                            height="auto"
                            timeZone="local"
                            slotMinTime="00:00:00"
                            slotMaxTime="24:00:00"
                            scrollTime="07:00:00"
                            allDaySlot={true}
                            nowIndicator={true}
                            eventContent={renderCalEventContent}
                            selectable={true}
                            selectMirror={true}
                            businessHours={{
                                daysOfWeek: [1, 2, 3, 4, 5],
                                startTime: '06:00',
                                endTime: '22:00',
                            }}
                            slotDuration="00:30:00"
                            dayMaxEvents={4}
                            moreLinkClick="popover"
                            eventMaxStack={3}
                            slotEventOverlap={false}
                            eventOverlap={false}
                            stickyHeaderDates={true}
                            firstDay={1}
                            eventTimeFormat={{
                                hour: '2-digit',
                                minute: '2-digit',
                                meridiem: false,
                            }}
                        />
                    </div>
                </div>
            </div>

            {/* Context Menu */}
            {ctxMenu && (
                <div
                    className="calendar-context-menu"
                    style={{ top: ctxMenu.y, left: ctxMenu.x }}
                    onClick={(e) => e.stopPropagation()}
                >
                    {/* eslint-disable-next-line no-restricted-syntax -- Calendar context menu styling targets native menu buttons. */}
                    <button onClick={openCreateFromCtx}>
                        <Plus className="h-4 w-4 text-primary" />
                        <span>Schedule Appointment</span>
                    </button>
                    <hr />
                    {/* eslint-disable-next-line no-restricted-syntax -- Calendar context menu styling targets native menu buttons. */}
                    <button
                        onClick={() => {
                            setCtxMenu(null);
                            changeView('timeGridDay');
                        }}
                    >
                        <Calendar className="h-4 w-4 text-muted-foreground" />
                        <span>View Day</span>
                    </button>
                </div>
            )}

            {/* Event Detail */}
            {detail && (
                <Card className="border-primary/20">
                    <CardContent className="p-4">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-sm font-semibold">
                                    {detail.title}
                                </h3>
                                <p className="mt-1 text-xs text-muted-foreground capitalize">
                                    {detail.type?.replace(/_/g, ' ')}
                                    {detail.appointment_type
                                        ? ` — ${detail.appointment_type.replace(/_/g, ' ')}`
                                        : ''}
                                </p>
                                {detail.start && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {new Date(detail.start).toLocaleString(
                                            'en-NZ',
                                            {
                                                weekday: 'short',
                                                day: 'numeric',
                                                month: 'short',
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            },
                                        )}
                                        {detail.end
                                            ? ` — ${new Date(detail.end).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}`
                                            : ''}
                                    </p>
                                )}
                                {detail.location && (
                                    <p className="mt-1 text-xs">
                                        <MapPin className="mr-1 inline h-3 w-3" />
                                        {detail.location}
                                    </p>
                                )}
                                {detail.provider_name && (
                                    <p className="mt-0.5 text-xs">
                                        <Stethoscope className="mr-1 inline h-3 w-3" />
                                        {detail.provider_name}
                                    </p>
                                )}
                                {detail.staff_name && (
                                    <p className="mt-0.5 text-xs">
                                        <Users className="mr-1 inline h-3 w-3" />
                                        {detail.staff_name}
                                    </p>
                                )}
                                {detail.medication_name && (
                                    <p className="mt-0.5 text-xs">
                                        <Pill className="mr-1 inline h-3 w-3" />
                                        {detail.medication_name}
                                        {detail.dosage
                                            ? ` — ${detail.dosage}`
                                            : ''}
                                    </p>
                                )}
                                {detail.description && (
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {detail.description}
                                    </p>
                                )}
                                {detail.notes && (
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {detail.notes}
                                    </p>
                                )}
                            </div>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setDetail(null)}
                            >
                                Close
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Create Appointment Dialog */}
            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Schedule Appointment</DialogTitle>
                        <DialogDescription>
                            Add a new appointment or reminder to this client’s
                            calendar.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Title *</Label>
                                <Input
                                    value={calForm.title}
                                    onChange={(e) =>
                                        setCalForm({
                                            ...calForm,
                                            title: e.target.value,
                                        })
                                    }
                                    placeholder="GP Visit - Dr. Patel"
                                    autoFocus
                                />
                            </div>
                            <div>
                                <Label>Type</Label>
                                <Select
                                    value={calForm.appointment_type}
                                    onValueChange={(v) =>
                                        setCalForm({
                                            ...calForm,
                                            appointment_type: v,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CAL_APPT_TYPES.map((t) => (
                                            <SelectItem
                                                key={t.value}
                                                value={t.value}
                                            >
                                                {t.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Start *</Label>
                                <Input
                                    type="datetime-local"
                                    value={calForm.starts_at}
                                    onChange={(e) =>
                                        setCalForm({
                                            ...calForm,
                                            starts_at: e.target.value,
                                        })
                                    }
                                />
                            </div>
                            <div>
                                <Label>End</Label>
                                <Input
                                    type="datetime-local"
                                    value={calForm.ends_at}
                                    onChange={(e) =>
                                        setCalForm({
                                            ...calForm,
                                            ends_at: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Location</Label>
                                <Input
                                    value={calForm.location}
                                    onChange={(e) =>
                                        setCalForm({
                                            ...calForm,
                                            location: e.target.value,
                                        })
                                    }
                                    placeholder="Riverside Medical Centre"
                                />
                            </div>
                            <div>
                                <Label>Provider</Label>
                                <Input
                                    value={calForm.provider_name}
                                    onChange={(e) =>
                                        setCalForm({
                                            ...calForm,
                                            provider_name: e.target.value,
                                        })
                                    }
                                    placeholder="Dr. Patel"
                                />
                            </div>
                        </div>
                        <div>
                            <Label>Notes</Label>
                            <Textarea
                                value={calForm.description}
                                onChange={(e) =>
                                    setCalForm({
                                        ...calForm,
                                        description: e.target.value,
                                    })
                                }
                                rows={2}
                            />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={calForm.share_with_family}
                                onCheckedChange={(v) =>
                                    setCalForm({
                                        ...calForm,
                                        share_with_family: !!v,
                                    })
                                }
                            />
                            Share with family portal
                        </label>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setCreateOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                !calForm.title.trim() || !calForm.starts_at
                            }
                            onClick={submitAppointment}
                        >
                            <Plus className="mr-2 h-4 w-4" />
                            Create
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
