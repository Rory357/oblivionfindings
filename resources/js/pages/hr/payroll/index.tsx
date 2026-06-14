import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Banknote, Download, Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface PayrollRun {
    id: number;
    period_start: string;
    period_end: string;
    status: 'draft' | 'locked' | 'exported';
    total_hours: number;
    total_gross: number;
    items_count: number;
    created_at: string;
    locked_at: string | null;
    exported_at: string | null;
    gl_posted_at: string | null;
    net_paid_at: string | null;
    export_profile: {
        id: number;
        name: string;
        provider_key: string | null;
    } | null;
    validation_errors: string[];
}

interface PayrollExportProfile {
    id: number;
    name: string;
    provider_key: string | null;
    description: string | null;
    delimiter: string;
    enclosure: string;
    line_ending: string;
    include_headers: boolean;
    is_default: boolean;
    mappings: Array<{ header: string; source: string; value?: unknown }>;
}

interface ExportFieldOption {
    value: string;
    label: string;
}

interface Props {
    runs: {
        data: PayrollRun[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    profiles: PayrollExportProfile[];
    exportFieldOptions: ExportFieldOption[];
    can: { manage: boolean; export_data: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Payroll', href: '/hr/payroll' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Draft',
    },
    locked: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'Locked',
    },
    exported: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Exported',
    },
};

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(amount);
}

function toDateInputValue(date: Date): string {
    const offsetDate = new Date(
        date.getTime() - date.getTimezoneOffset() * 60000,
    );
    return offsetDate.toISOString().slice(0, 10);
}

function formatDate(value: string | null): string {
    if (!value) {
        return '\u2014';
    }

    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

export default function PayrollIndex({
    runs,
    profiles,
    exportFieldOptions,
    can,
}: Props) {
    const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
    const [isProfileDialogOpen, setIsProfileDialogOpen] = useState(false);
    const [editingProfileId, setEditingProfileId] = useState<number | null>(
        null,
    );
    const [selectedProfileByRun, setSelectedProfileByRun] = useState<
        Record<number, string>
    >({});
    const [profileSubmitting, setProfileSubmitting] = useState(false);
    const [profileJsonError, setProfileJsonError] = useState<string | null>(
        null,
    );
    const page = usePage<{ errors?: Record<string, string | string[]> }>();
    const { data, setData, post, processing, errors, clearErrors, reset } =
        useForm({
            period_start: '',
            period_end: '',
            notes: '',
        });
    const {
        data: profileData,
        setData: setProfileData,
        errors: profileErrors,
        clearErrors: clearProfileErrors,
        reset: resetProfileForm,
    } = useForm({
        name: '',
        provider_key: '',
        description: '',
        delimiter: ',',
        enclosure: '"',
        line_ending: '\\n',
        include_headers: true,
        is_default: false,
        mappings_json: '[]',
    });

    const lockError = page.props?.errors?.lock;
    const periodError = page.props?.errors?.period;
    const exportError = page.props?.errors?.export;
    const profileMappingsError = (
        profileErrors as Record<string, string | undefined>
    ).mappings;
    const defaultProfile =
        profiles.find((profile) => profile.is_default) ?? null;

    function handleExport(runId: number) {
        const selectedProfileId =
            selectedProfileByRun[runId] ||
            (defaultProfile ? String(defaultProfile.id) : '');
        router.post(
            `/hr/payroll/runs/${runId}/export`,
            selectedProfileId ? { profile_id: Number(selectedProfileId) } : {},
            { preserveScroll: true },
        );
    }

    function openCreateRunDialog() {
        const periodStart = new Date();
        const periodEnd = new Date(periodStart);
        periodEnd.setDate(periodEnd.getDate() + 13);

        clearErrors();
        setData({
            period_start: toDateInputValue(periodStart),
            period_end: toDateInputValue(periodEnd),
            notes: '',
        });
        setIsCreateDialogOpen(true);
    }

    function openCreateProfileDialog() {
        clearProfileErrors();
        setProfileJsonError(null);
        setEditingProfileId(null);
        resetProfileForm();
        const defaultMappings = [
            { header: 'Employee ID', source: 'employee_number' },
            { header: 'Employee Name', source: 'name' },
            { header: 'Regular Hours', source: 'regular_hours' },
            { header: 'Overtime Hours', source: 'overtime_hours' },
            { header: 'Gross Pay', source: 'gross_pay' },
        ];
        setProfileData({
            name: '',
            provider_key: '',
            description: '',
            delimiter: ',',
            enclosure: '"',
            line_ending: '\\n',
            include_headers: true,
            is_default: profiles.length === 0,
            mappings_json: JSON.stringify(defaultMappings, null, 2),
        });
        setIsProfileDialogOpen(true);
    }

    function openEditProfileDialog(profile: PayrollExportProfile) {
        clearProfileErrors();
        setProfileJsonError(null);
        setEditingProfileId(profile.id);
        setProfileData({
            name: profile.name,
            provider_key: profile.provider_key ?? '',
            description: profile.description ?? '',
            delimiter: profile.delimiter || ',',
            enclosure: profile.enclosure || '"',
            line_ending:
                profile.line_ending === '\r\n'
                    ? '\\r\\n'
                    : profile.line_ending === '\r'
                      ? '\\r'
                      : '\\n',
            include_headers: profile.include_headers,
            is_default: profile.is_default,
            mappings_json: JSON.stringify(profile.mappings ?? [], null, 2),
        });
        setIsProfileDialogOpen(true);
    }

    function handleSetDefaultProfile(profileId: number) {
        router.post(
            `/hr/payroll/export-profiles/${profileId}/set-default`,
            {},
            { preserveScroll: true },
        );
    }

    function handleProfileSubmit(event: FormEvent) {
        event.preventDefault();
        setProfileJsonError(null);

        let parsedMappings: unknown;
        try {
            parsedMappings = JSON.parse(profileData.mappings_json || '[]');
        } catch {
            setProfileJsonError('Mappings JSON is invalid.');
            return;
        }

        const payload = {
            name: profileData.name,
            provider_key: profileData.provider_key || null,
            description: profileData.description || null,
            delimiter: profileData.delimiter || ',',
            enclosure: profileData.enclosure || '"',
            line_ending: profileData.line_ending || '\\n',
            include_headers: profileData.include_headers,
            is_default: profileData.is_default,
            mappings: Array.isArray(parsedMappings) ? parsedMappings : [],
        };

        setProfileSubmitting(true);

        if (editingProfileId) {
            router.put(
                `/hr/payroll/export-profiles/${editingProfileId}`,
                payload,
                {
                    preserveScroll: true,
                    onFinish: () => setProfileSubmitting(false),
                    onSuccess: () => {
                        setIsProfileDialogOpen(false);
                        setEditingProfileId(null);
                    },
                },
            );
            return;
        }

        router.post('/hr/payroll/export-profiles', payload, {
            preserveScroll: true,
            onFinish: () => setProfileSubmitting(false),
            onSuccess: () => {
                setIsProfileDialogOpen(false);
            },
        });
    }

    function handleCreateRunSubmit(event: FormEvent) {
        event.preventDefault();

        post('/hr/payroll/runs', {
            preserveScroll: true,
            onSuccess: () => {
                setIsCreateDialogOpen(false);
                reset();
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payroll" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={Banknote}
                        title="Payroll Runs"
                        description="Manage payroll periods, lock runs, and export to your payroll provider."
                        stats={[
                            { label: 'Total runs', value: runs.total },
                            { label: 'Drafts', value: runs.data.filter((r) => r.status === 'draft').length },
                            { label: 'Locked', value: runs.data.filter((r) => r.status === 'locked').length },
                            { label: 'Exported', value: runs.data.filter((r) => r.status === 'exported').length },
                        ]}
                        actions={
                            can.manage ? (
                                <Button onClick={openCreateRunDialog}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create Run
                                </Button>
                            ) : undefined
                        }
                    />
                }
            >
                <div className="text-xs text-muted-foreground">
                    Tip: use the Create Run button to enter period dates and
                    generate draft payroll items.
                </div>

                {lockError ? (
                    <Card className="border-status-critical/40 bg-status-critical">
                        <CardContent className="py-3 text-sm text-status-critical">
                            {Array.isArray(lockError)
                                ? lockError.join(' ')
                                : lockError}
                        </CardContent>
                    </Card>
                ) : null}
                {exportError ? (
                    <Card className="border-status-critical/40 bg-status-critical">
                        <CardContent className="py-3 text-sm text-status-critical">
                            {Array.isArray(exportError)
                                ? exportError.join(' ')
                                : exportError}
                        </CardContent>
                    </Card>
                ) : null}

                <Dialog
                    open={isCreateDialogOpen}
                    onOpenChange={setIsCreateDialogOpen}
                >
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Create Payroll Run</DialogTitle>
                            <DialogDescription>
                                Enter the payroll period dates to generate a
                                draft run.
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            onSubmit={handleCreateRunSubmit}
                            className="space-y-4"
                        >
                            <div className="space-y-2">
                                <Label htmlFor="period_start">
                                    Period start
                                </Label>
                                <Input
                                    id="period_start"
                                    type="date"
                                    value={data.period_start}
                                    onChange={(event) =>
                                        setData(
                                            'period_start',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                {(errors.period_start ||
                                    (typeof periodError === 'string'
                                        ? periodError
                                        : null)) && (
                                    <p className="text-xs text-status-critical">
                                        {errors.period_start ||
                                            (typeof periodError === 'string'
                                                ? periodError
                                                : null)}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="period_end">Period end</Label>
                                <Input
                                    id="period_end"
                                    type="date"
                                    value={data.period_end}
                                    min={data.period_start || undefined}
                                    onChange={(event) =>
                                        setData(
                                            'period_end',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                {errors.period_end && (
                                    <p className="text-xs text-status-critical">
                                        {errors.period_end}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="notes">Notes (optional)</Label>
                                <Input
                                    id="notes"
                                    value={data.notes}
                                    onChange={(event) =>
                                        setData('notes', event.target.value)
                                    }
                                    placeholder="Optional payroll notes"
                                />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setIsCreateDialogOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create Run'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                <Dialog
                    open={isProfileDialogOpen}
                    onOpenChange={setIsProfileDialogOpen}
                >
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>
                                {editingProfileId
                                    ? 'Edit Export Profile'
                                    : 'Create Export Profile'}
                            </DialogTitle>
                            <DialogDescription>
                                Configure payroll export columns and separators
                                for your payroll provider.
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            onSubmit={handleProfileSubmit}
                            className="space-y-4"
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="profile_name">
                                        Profile name
                                    </Label>
                                    <Input
                                        id="profile_name"
                                        value={profileData.name}
                                        onChange={(event) =>
                                            setProfileData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                    {profileErrors.name && (
                                        <p className="text-xs text-status-critical">
                                            {profileErrors.name}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="provider_key">
                                        Provider key (optional)
                                    </Label>
                                    <Input
                                        id="provider_key"
                                        value={profileData.provider_key}
                                        onChange={(event) =>
                                            setProfileData(
                                                'provider_key',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="myob, xero, custom"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="delimiter">Delimiter</Label>
                                    <Input
                                        id="delimiter"
                                        value={profileData.delimiter}
                                        onChange={(event) =>
                                            setProfileData(
                                                'delimiter',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="enclosure">
                                        Text enclosure
                                    </Label>
                                    <Input
                                        id="enclosure"
                                        value={profileData.enclosure}
                                        onChange={(event) =>
                                            setProfileData(
                                                'enclosure',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="line_ending">
                                        Line ending
                                    </Label>
                                    <Select
                                        value={profileData.line_ending}
                                        onValueChange={(value) =>
                                            setProfileData('line_ending', value)
                                        }
                                    >
                                        <SelectTrigger id="line_ending">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="\\n">
                                                LF (\n)
                                            </SelectItem>
                                            <SelectItem value="\\r\\n">
                                                CRLF (\r\n)
                                            </SelectItem>
                                            <SelectItem value="\\r">
                                                CR (\r)
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="description">
                                        Description
                                    </Label>
                                    <Input
                                        id="description"
                                        value={profileData.description}
                                        onChange={(event) =>
                                            setProfileData(
                                                'description',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-6 rounded-md border p-3">
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={profileData.include_headers}
                                        onCheckedChange={(checked) =>
                                            setProfileData(
                                                'include_headers',
                                                Boolean(checked),
                                            )
                                        }
                                    />
                                    <span>Include headers</span>
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={profileData.is_default}
                                        onCheckedChange={(checked) =>
                                            setProfileData(
                                                'is_default',
                                                Boolean(checked),
                                            )
                                        }
                                    />
                                    <span>Set as default profile</span>
                                </label>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="mappings_json">
                                    Mappings JSON
                                </Label>
                                <Textarea
                                    id="mappings_json"
                                    rows={10}
                                    value={profileData.mappings_json}
                                    onChange={(event) =>
                                        setProfileData(
                                            'mappings_json',
                                            event.target.value,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Use field sources:{' '}
                                    {exportFieldOptions
                                        .map((field) => field.value)
                                        .join(', ')}
                                    , plus <code>static</code>.
                                </p>
                                {(profileErrors.mappings_json ||
                                    profileMappingsError) && (
                                    <p className="text-xs text-status-critical">
                                        {profileErrors.mappings_json ||
                                            profileMappingsError}
                                    </p>
                                )}
                                {profileJsonError && (
                                    <p className="text-xs text-status-critical">
                                        {profileJsonError}
                                    </p>
                                )}
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        setIsProfileDialogOpen(false)
                                    }
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={profileSubmitting}
                                >
                                    {profileSubmitting
                                        ? 'Saving...'
                                        : editingProfileId
                                          ? 'Update Profile'
                                          : 'Create Profile'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                <Card>
                    <CardContent className="py-4">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="text-base font-semibold">
                                Payroll Export Profiles
                            </h2>
                            {can.manage && (
                                <Button
                                    variant="outline"
                                    onClick={openCreateProfileDialog}
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    New Export Profile
                                </Button>
                            )}
                        </div>
                        {profiles.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No export profiles configured yet. The default
                                payroll CSV schema will be used.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {profiles.map((profile) => (
                                    <div
                                        key={profile.id}
                                        className="flex items-center justify-between rounded-md border p-3"
                                    >
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">
                                                    {profile.name}
                                                </span>
                                                {profile.is_default && (
                                                    <Badge variant="outline">
                                                        Default
                                                    </Badge>
                                                )}
                                                {profile.provider_key ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-xs text-muted-foreground"
                                                    >
                                                        {profile.provider_key}
                                                    </Badge>
                                                ) : null}
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {profile.mappings?.length ?? 0}{' '}
                                                mappings, delimiter "
                                                {profile.delimiter}", enclosure
                                                "{profile.enclosure}"
                                            </p>
                                        </div>
                                        {can.manage && (
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        openEditProfileDialog(
                                                            profile,
                                                        )
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                                {!profile.is_default && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            handleSetDefaultProfile(
                                                                profile.id,
                                                            )
                                                        }
                                                    >
                                                        Set Default
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Period
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Total Hours
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Total Gross
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Items
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Created
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {runs.data.map((run) => {
                                    const config =
                                        statusConfig[run.status] ||
                                        statusConfig.draft;
                                    return (
                                        <tr
                                            key={run.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3">
                                                <span className="font-medium">
                                                    {formatDate(
                                                        run.period_start,
                                                    )}{' '}
                                                    -{' '}
                                                    {formatDate(run.period_end)}
                                                </span>
                                                {run.validation_errors?.length >
                                                0 ? (
                                                    <div className="mt-1 text-xs text-status-critical">
                                                        {
                                                            run
                                                                .validation_errors[0]
                                                        }
                                                        {run.validation_errors
                                                            .length > 1
                                                            ? ` (+${run.validation_errors.length - 1} more)`
                                                            : ''}
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant="outline"
                                                    className={config.className}
                                                >
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {run.total_hours.toFixed(1)}h
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium">
                                                {formatCurrency(
                                                    run.total_gross,
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {run.items_count}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {formatDate(run.created_at)}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    {run.status === 'draft' &&
                                                        can.manage && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    router.post(
                                                                        `/hr/payroll/runs/${run.id}/lock`,
                                                                        {},
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Lock
                                                            </Button>
                                                        )}
                                                    {can.manage &&
                                                        run.gl_posted_at &&
                                                        !run.net_paid_at && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    router.post(
                                                                        `/hr/payroll/runs/${run.id}/pay`,
                                                                        {},
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Pay net
                                                            </Button>
                                                        )}
                                                    {run.net_paid_at && (
                                                        <span className="inline-flex items-center rounded-md bg-status-success-bg px-2 py-1 text-xs font-semibold text-status-success">
                                                            Paid
                                                        </span>
                                                    )}
                                                    {can.export_data &&
                                                        run.status ===
                                                            'locked' && (
                                                            <div className="flex items-center gap-2">
                                                                {profiles.length >
                                                                    0 && (
                                                                    <Select
                                                                        value={
                                                                            selectedProfileByRun[
                                                                                run
                                                                                    .id
                                                                            ] ||
                                                                            (defaultProfile
                                                                                ? String(
                                                                                      defaultProfile.id,
                                                                                  )
                                                                                : undefined)
                                                                        }
                                                                        onValueChange={(
                                                                            value,
                                                                        ) =>
                                                                            setSelectedProfileByRun(
                                                                                (
                                                                                    previous,
                                                                                ) => ({
                                                                                    ...previous,
                                                                                    [run.id]:
                                                                                        value,
                                                                                }),
                                                                            )
                                                                        }
                                                                    >
                                                                        <SelectTrigger className="h-8 w-[180px]">
                                                                            <SelectValue placeholder="Default mapping" />
                                                                        </SelectTrigger>
                                                                        <SelectContent>
                                                                            {profiles.map(
                                                                                (
                                                                                    profile,
                                                                                ) => (
                                                                                    <SelectItem
                                                                                        key={
                                                                                            profile.id
                                                                                        }
                                                                                        value={String(
                                                                                            profile.id,
                                                                                        )}
                                                                                    >
                                                                                        {
                                                                                            profile.name
                                                                                        }
                                                                                    </SelectItem>
                                                                                ),
                                                                            )}
                                                                        </SelectContent>
                                                                    </Select>
                                                                )}
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        handleExport(
                                                                            run.id,
                                                                        )
                                                                    }
                                                                >
                                                                    <Download className="mr-1 h-3 w-3" />
                                                                    Export
                                                                </Button>
                                                            </div>
                                                        )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {runs.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No payroll runs found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {runs.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(runs.current_page - 1) * runs.per_page + 1} to{' '}
                            {Math.min(
                                runs.current_page * runs.per_page,
                                runs.total,
                            )}{' '}
                            of {runs.total} results
                        </p>
                        <LaravelPagination links={runs.links} />
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
