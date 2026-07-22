import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    ExternalLink,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
    SiteProfileEmptyState,
    SiteProfileLockedState,
} from './site-profile-states';

type RoleRequirement = {
    key: 'caregiver' | 'driver' | 'med_competent';
    minimum: number;
};

type CoverageRequirement = {
    id: number;
    name: string;
    coverage_type: 'day' | 'evening' | 'overnight' | 'custom';
    day_of_week: string;
    starts_time: string;
    ends_time: string;
    minimum_staff: number;
    service_context_id?: number | null;
    service_context?: { id: number; name: string; type?: string | null } | null;
    preferred_client_id?: number | null;
    preferred_client?: { id: number; name: string } | null;
    role_requirements: RoleRequirement[];
    allow_overstaffing: boolean;
    shift_type?: string | null;
    notes?: string | null;
};

type CoveragePreview = {
    total_windows: number;
    under_covered_windows: number;
    exact_windows: number;
    overstaffed_windows: number;
    largest_missing_staff: number;
    alerts: Array<{
        rule_name: string;
        window_label: string;
        required_staff: number;
        assigned_staff: number;
        missing_staff: number;
    }>;
};

export type SiteShiftCoverageData = {
    locked: boolean;
    href?: string | null;
    preview: CoveragePreview[] | null;
    requirements: CoverageRequirement[];
    clients: Array<{ id: number; name: string }>;
    service_contexts: Array<{ id: number; name: string; type?: string | null }>;
    can_manage: boolean;
};

type CoverageForm = {
    name: string;
    coverage_type: CoverageRequirement['coverage_type'];
    day_of_week: string;
    starts_time: string;
    ends_time: string;
    minimum_staff: number;
    service_context_id: string;
    preferred_client_id: string;
    role_requirements: RoleRequirement[];
    allow_overstaffing: boolean;
    shift_type: string;
    notes: string;
};

const EMPTY_FORM: CoverageForm = {
    name: '',
    coverage_type: 'day',
    day_of_week: 'mon',
    starts_time: '07:00',
    ends_time: '15:00',
    minimum_staff: 1,
    service_context_id: '',
    preferred_client_id: '',
    role_requirements: [{ key: 'caregiver', minimum: 1 }],
    allow_overstaffing: true,
    shift_type: 'standard',
    notes: '',
};

export function SiteProfileShiftCoverage({
    siteId,
    data,
}: {
    siteId: number;
    data: SiteShiftCoverageData;
}) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<CoverageRequirement | null>(null);
    const [deleting, setDeleting] = useState<CoverageRequirement | null>(null);
    const form = useForm<CoverageForm>(EMPTY_FORM);

    if (data.locked) return <SiteProfileLockedState label="Shift coverage" />;

    const health = data.preview?.[0] ?? null;

    const openCreate = () => {
        setEditing(null);
        form.setData(EMPTY_FORM);
        form.clearErrors();
        setDialogOpen(true);
    };

    const openEdit = (requirement: CoverageRequirement) => {
        setEditing(requirement);
        form.setData({
            name: requirement.name,
            coverage_type: requirement.coverage_type,
            day_of_week: requirement.day_of_week,
            starts_time: requirement.starts_time,
            ends_time: requirement.ends_time,
            minimum_staff: requirement.minimum_staff,
            service_context_id: requirement.service_context_id
                ? String(requirement.service_context_id)
                : '',
            preferred_client_id: requirement.preferred_client_id
                ? String(requirement.preferred_client_id)
                : '',
            role_requirements: requirement.role_requirements,
            allow_overstaffing: requirement.allow_overstaffing,
            shift_type: requirement.shift_type || 'standard',
            notes: requirement.notes || '',
        });
        form.clearErrors();
        setDialogOpen(true);
    };

    const updateRole = (key: RoleRequirement['key'], minimum: number) => {
        const without = form.data.role_requirements.filter(
            (role) => role.key !== key,
        );
        form.setData(
            'role_requirements',
            minimum > 0 ? [...without, { key, minimum }] : without,
        );
    };

    const roleMinimum = (key: RoleRequirement['key']) =>
        form.data.role_requirements.find((role) => role.key === key)?.minimum ??
        0;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setDialogOpen(false);
                router.reload({
                    only: ['shiftCoverageData'],
                    preserveScroll: true,
                });
            },
        };
        if (editing) {
            form.put(
                `/sites/${siteId}/coverage-requirements/${editing.id}`,
                options,
            );
        } else {
            form.post(`/sites/${siteId}/coverage-requirements`, options);
        }
    };

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold">Shift coverage</h2>
                    <p className="text-sm text-muted-foreground">
                        Define staffing demand here and see its live Rostering
                        impact.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {data.href ? (
                        <Button asChild variant="outline" className="min-h-11">
                            <Link href={data.href}>
                                Open Rostering{' '}
                                <ExternalLink className="ml-2 h-4 w-4" />
                            </Link>
                        </Button>
                    ) : null}
                    {data.can_manage ? (
                        <Button
                            type="button"
                            className="min-h-11"
                            onClick={openCreate}
                        >
                            <Plus className="mr-2 h-4 w-4" /> Add coverage
                            requirement
                        </Button>
                    ) : null}
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <HealthStat
                    label="Demand windows"
                    value={health?.total_windows ?? 0}
                />
                <HealthStat
                    label="Under-covered"
                    value={health?.under_covered_windows ?? 0}
                    critical
                />
                <HealthStat label="Exact" value={health?.exact_windows ?? 0} />
                <HealthStat
                    label="Overstaffed"
                    value={health?.overstaffed_windows ?? 0}
                />
                <HealthStat
                    label="Largest gap"
                    value={health?.largest_missing_staff ?? 0}
                    critical
                />
            </div>

            {health?.alerts?.length ? (
                <Card className="border-status-warning/30">
                    <CardContent className="space-y-3 p-4">
                        <h3 className="flex items-center gap-2 font-semibold">
                            <AlertTriangle className="h-4 w-4 text-status-warning" />{' '}
                            Coverage gaps needing action
                        </h3>
                        {health.alerts.map((alert, index) => (
                            <div
                                key={`${alert.rule_name}-${alert.window_label}-${index}`}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                            >
                                <div>
                                    <p className="text-sm font-semibold">
                                        {alert.rule_name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {alert.window_label}
                                    </p>
                                </div>
                                <Badge
                                    variant="outline"
                                    className="border-status-critical/30 bg-status-critical-bg text-status-critical"
                                >
                                    {alert.assigned_staff}/
                                    {alert.required_staff} assigned ·{' '}
                                    {alert.missing_staff} missing
                                </Badge>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            ) : null}

            {data.requirements.length ? (
                <div className="grid gap-3 lg:grid-cols-2">
                    {data.requirements.map((requirement) => (
                        <Card key={requirement.id}>
                            <CardContent className="space-y-3 p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-semibold">
                                            {requirement.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {requirement.day_of_week.toUpperCase()}{' '}
                                            · {requirement.starts_time}–
                                            {requirement.ends_time} · minimum{' '}
                                            {requirement.minimum_staff}
                                        </p>
                                    </div>
                                    <Badge variant="outline">
                                        {requirement.coverage_type}
                                    </Badge>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {requirement.role_requirements.map(
                                        (role) => (
                                            <Badge
                                                key={role.key}
                                                variant="secondary"
                                            >
                                                {role.key.replaceAll('_', ' ')}{' '}
                                                × {role.minimum}
                                            </Badge>
                                        ),
                                    )}
                                    {requirement.service_context ? (
                                        <Badge variant="secondary">
                                            {requirement.service_context.name}
                                        </Badge>
                                    ) : null}
                                    {requirement.preferred_client ? (
                                        <Badge variant="secondary">
                                            {requirement.preferred_client.name}
                                        </Badge>
                                    ) : null}
                                    {!requirement.allow_overstaffing ? (
                                        <Badge variant="outline">
                                            No overstaffing
                                        </Badge>
                                    ) : null}
                                </div>
                                {requirement.notes ? (
                                    <p className="text-sm text-muted-foreground">
                                        {requirement.notes}
                                    </p>
                                ) : null}
                                {data.can_manage ? (
                                    <div className="flex justify-end gap-2 border-t pt-3">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="min-h-11"
                                            onClick={() =>
                                                openEdit(requirement)
                                            }
                                        >
                                            <Pencil className="mr-2 h-4 w-4" />{' '}
                                            Edit
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="min-h-11 text-status-critical"
                                            onClick={() =>
                                                setDeleting(requirement)
                                            }
                                        >
                                            <Trash2 className="mr-2 h-4 w-4" />{' '}
                                            Remove
                                        </Button>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            ) : (
                <SiteProfileEmptyState
                    icon={CalendarClock}
                    title="No coverage requirements configured"
                    description="Add the minimum staffing, roles, service, client, and time window this Site needs."
                    action={
                        data.can_manage
                            ? {
                                  label: 'Add coverage requirement',
                                  onClick: openCreate,
                              }
                            : undefined
                    }
                />
            )}

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {editing
                                ? 'Edit coverage requirement'
                                : 'Add coverage requirement'}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <Label htmlFor="coverage-name">Name</Label>
                            <Input
                                id="coverage-name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                            />
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FieldSelect
                                label="Coverage type"
                                value={form.data.coverage_type}
                                onValue={(value) =>
                                    form.setData(
                                        'coverage_type',
                                        value as CoverageForm['coverage_type'],
                                    )
                                }
                                options={[
                                    'day',
                                    'evening',
                                    'overnight',
                                    'custom',
                                ]}
                            />
                            <FieldSelect
                                label="Day"
                                value={form.data.day_of_week}
                                onValue={(value) =>
                                    form.setData('day_of_week', value)
                                }
                                options={[
                                    'mon',
                                    'tue',
                                    'wed',
                                    'thu',
                                    'fri',
                                    'sat',
                                    'sun',
                                ]}
                            />
                            <div>
                                <Label htmlFor="coverage-start">Starts</Label>
                                <Input
                                    id="coverage-start"
                                    type="time"
                                    value={form.data.starts_time}
                                    onChange={(event) =>
                                        form.setData(
                                            'starts_time',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="coverage-end">Ends</Label>
                                <Input
                                    id="coverage-end"
                                    type="time"
                                    value={form.data.ends_time}
                                    onChange={(event) =>
                                        form.setData(
                                            'ends_time',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="coverage-minimum">
                                    Minimum staff
                                </Label>
                                <Input
                                    id="coverage-minimum"
                                    type="number"
                                    min={1}
                                    max={12}
                                    value={form.data.minimum_staff}
                                    onChange={(event) =>
                                        form.setData(
                                            'minimum_staff',
                                            Number(event.target.value),
                                        )
                                    }
                                    required
                                />
                            </div>
                            <FieldSelect
                                label="Shift type"
                                value={form.data.shift_type}
                                onValue={(value) =>
                                    form.setData('shift_type', value)
                                }
                                options={[
                                    'standard',
                                    'sleepover',
                                    'on_call',
                                    'split',
                                    'travel',
                                ]}
                            />
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <Label>Service</Label>
                                <Select
                                    value={
                                        form.data.service_context_id || 'none'
                                    }
                                    onValueChange={(value) =>
                                        form.setData(
                                            'service_context_id',
                                            value === 'none' ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Any service
                                        </SelectItem>
                                        {data.service_contexts.map(
                                            (service) => (
                                                <SelectItem
                                                    key={service.id}
                                                    value={String(service.id)}
                                                >
                                                    {service.name}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Preferred client</Label>
                                <Select
                                    value={
                                        form.data.preferred_client_id || 'none'
                                    }
                                    onValueChange={(value) =>
                                        form.setData(
                                            'preferred_client_id',
                                            value === 'none' ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Any client
                                        </SelectItem>
                                        {data.clients.map((client) => (
                                            <SelectItem
                                                key={client.id}
                                                value={String(client.id)}
                                            >
                                                {client.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div>
                            <Label>Role minimums</Label>
                            <div className="mt-2 grid gap-3 sm:grid-cols-3">
                                {(
                                    [
                                        'caregiver',
                                        'driver',
                                        'med_competent',
                                    ] as const
                                ).map((role) => (
                                    <div key={role}>
                                        <Label
                                            htmlFor={`coverage-role-${role}`}
                                            className="text-xs capitalize"
                                        >
                                            {role.replaceAll('_', ' ')}
                                        </Label>
                                        <Input
                                            id={`coverage-role-${role}`}
                                            type="number"
                                            min={0}
                                            max={12}
                                            value={roleMinimum(role)}
                                            onChange={(event) =>
                                                updateRole(
                                                    role,
                                                    Number(event.target.value),
                                                )
                                            }
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                        <div className="flex items-center justify-between rounded-lg border p-3">
                            <div>
                                <Label htmlFor="coverage-overstaffing">
                                    Allow overstaffing
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Permit staffing above the minimum.
                                </p>
                            </div>
                            <Switch
                                id="coverage-overstaffing"
                                checked={form.data.allow_overstaffing}
                                onCheckedChange={(checked) =>
                                    form.setData('allow_overstaffing', checked)
                                }
                            />
                        </div>
                        <div>
                            <Label htmlFor="coverage-notes">Notes</Label>
                            <Textarea
                                id="coverage-notes"
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                onClick={() => setDialogOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={form.processing}
                            >
                                {form.processing
                                    ? 'Saving…'
                                    : 'Save coverage requirement'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={() => {
                    if (!deleting) return;
                    router.delete(
                        `/sites/${siteId}/coverage-requirements/${deleting.id}`,
                        {
                            preserveScroll: true,
                            onSuccess: () =>
                                router.reload({
                                    only: ['shiftCoverageData'],
                                    preserveScroll: true,
                                }),
                        },
                    );
                }}
                title="Remove coverage requirement?"
                description={`${deleting?.name ?? 'This requirement'} will no longer contribute demand to Rostering.`}
                confirmText="Remove requirement"
            />
        </div>
    );
}

function HealthStat({
    label,
    value,
    critical = false,
}: {
    label: string;
    value: number;
    critical?: boolean;
}) {
    return (
        <Card
            className={
                critical && value > 0 ? 'border-status-critical/30' : undefined
            }
        >
            <CardContent className="p-4">
                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>
                <p
                    className={
                        critical && value > 0
                            ? 'mt-1 text-2xl font-bold text-status-critical'
                            : 'mt-1 text-2xl font-bold'
                    }
                >
                    {value}
                </p>
            </CardContent>
        </Card>
    );
}

function FieldSelect({
    label,
    value,
    onValue,
    options,
}: {
    label: string;
    value: string;
    onValue: (value: string) => void;
    options: string[];
}) {
    return (
        <div>
            <Label>{label}</Label>
            <Select value={value} onValueChange={onValue}>
                <SelectTrigger>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option} value={option}>
                            {option.replaceAll('_', ' ')}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
