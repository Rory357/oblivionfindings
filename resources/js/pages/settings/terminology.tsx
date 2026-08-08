import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';

type Props = {
    defaults: Record<string, string>;
    overrides: Record<string, string | null>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Terminology', href: '/settings/terminology' },
];

// Terminology rows: each has a human label, singular key, plural key
const TERM_ROWS = [
    {
        label: 'Client',
        singularKey: 'client.singular',
        pluralKey: 'client.plural',
    },
    { label: 'Site', singularKey: 'site.singular', pluralKey: 'site.plural' },
    {
        label: 'Asset',
        singularKey: 'asset.singular',
        pluralKey: 'asset.plural',
    },
    {
        label: 'Staff Member',
        singularKey: 'staff.singular',
        pluralKey: 'staff.plural',
    },
    {
        label: 'Support Worker',
        singularKey: 'worker.singular',
        pluralKey: 'worker.plural',
    },
    {
        label: 'Shift',
        singularKey: 'shift.singular',
        pluralKey: 'shift.plural',
    },
    {
        label: 'Timesheet',
        singularKey: 'timesheet.singular',
        pluralKey: 'timesheet.plural',
    },
    {
        label: 'Medication',
        singularKey: 'medication.singular',
        pluralKey: 'medication.plural',
    },
    {
        label: 'Incident',
        singularKey: 'incident.singular',
        pluralKey: 'incident.plural',
    },
    { label: 'Risk', singularKey: 'risk.singular', pluralKey: 'risk.plural' },
    { label: 'Note', singularKey: 'note.singular', pluralKey: 'note.plural' },
    {
        label: 'Timeline',
        singularKey: 'timeline.singular',
        pluralKey: 'timeline.plural',
    },
    {
        label: 'Document',
        singularKey: 'document.singular',
        pluralKey: 'document.plural',
    },
    {
        label: 'Report',
        singularKey: 'report.singular',
        pluralKey: 'report.plural',
    },
    {
        label: 'Respite',
        singularKey: 'respite.singular',
        pluralKey: 'respite.plural',
    },
    {
        label: 'Emergency Access',
        singularKey: 'emergency_access.singular',
        pluralKey: 'emergency_access.plural',
    },
];

const ALL_KEYS = TERM_ROWS.flatMap((r) => [r.singularKey, r.pluralKey]);

// Presets for different industry contexts
const PRESETS: Record<string, Record<string, string>> = {
    'Disability Support': {
        'client.singular': 'Client',
        'client.plural': 'Clients',
        'staff.singular': 'Staff Member',
        'staff.plural': 'Staff Members',
        'worker.singular': 'Support Worker',
        'worker.plural': 'Support Workers',
        'site.singular': 'Site',
        'site.plural': 'Sites',
        'asset.singular': 'Asset',
        'asset.plural': 'Assets',
        'shift.singular': 'Shift',
        'shift.plural': 'Shifts',
        'timesheet.singular': 'Timesheet',
        'timesheet.plural': 'Timesheets',
        'medication.singular': 'Medication',
        'medication.plural': 'Medications',
        'incident.singular': 'Incident',
        'incident.plural': 'Incidents',
        'risk.singular': 'Risk',
        'risk.plural': 'Risks',
        'note.singular': 'Note',
        'note.plural': 'Notes',
        'timeline.singular': 'Timeline',
        'timeline.plural': 'Timelines',
        'document.singular': 'Document',
        'document.plural': 'Documents',
        'report.singular': 'Report',
        'report.plural': 'Reports',
        'respite.singular': 'Respite',
        'respite.plural': 'Respite',
        'emergency_access.singular': 'Emergency Access',
        'emergency_access.plural': 'Emergency Access',
    },
    'Aged Care': {
        'client.singular': 'Resident',
        'client.plural': 'Residents',
        'staff.singular': 'Carer',
        'staff.plural': 'Carers',
        'worker.singular': 'Care Worker',
        'worker.plural': 'Care Workers',
        'site.singular': 'Facility',
        'site.plural': 'Facilities',
        'asset.singular': 'Asset',
        'asset.plural': 'Assets',
        'shift.singular': 'Shift',
        'shift.plural': 'Shifts',
        'timesheet.singular': 'Timesheet',
        'timesheet.plural': 'Timesheets',
        'medication.singular': 'Medication',
        'medication.plural': 'Medications',
        'incident.singular': 'Incident',
        'incident.plural': 'Incidents',
        'risk.singular': 'Risk',
        'risk.plural': 'Risks',
        'note.singular': 'Note',
        'note.plural': 'Notes',
        'timeline.singular': 'Timeline',
        'timeline.plural': 'Timelines',
        'document.singular': 'Document',
        'document.plural': 'Documents',
        'report.singular': 'Report',
        'report.plural': 'Reports',
        'respite.singular': 'Respite',
        'respite.plural': 'Respite',
        'emergency_access.singular': 'Emergency Access',
        'emergency_access.plural': 'Emergency Access',
    },
    'Mental Health': {
        'client.singular': 'Consumer',
        'client.plural': 'Consumers',
        'staff.singular': 'Clinician',
        'staff.plural': 'Clinicians',
        'worker.singular': 'Peer Support Worker',
        'worker.plural': 'Peer Support Workers',
        'site.singular': 'Service',
        'site.plural': 'Services',
        'asset.singular': 'Asset',
        'asset.plural': 'Assets',
        'shift.singular': 'Session',
        'shift.plural': 'Sessions',
        'timesheet.singular': 'Timesheet',
        'timesheet.plural': 'Timesheets',
        'medication.singular': 'Medication',
        'medication.plural': 'Medications',
        'incident.singular': 'Incident',
        'incident.plural': 'Incidents',
        'risk.singular': 'Risk',
        'risk.plural': 'Risks',
        'note.singular': 'Note',
        'note.plural': 'Notes',
        'timeline.singular': 'Timeline',
        'timeline.plural': 'Timelines',
        'document.singular': 'Document',
        'document.plural': 'Documents',
        'report.singular': 'Report',
        'report.plural': 'Reports',
        'respite.singular': 'Respite',
        'respite.plural': 'Respite',
        'emergency_access.singular': 'Emergency Access',
        'emergency_access.plural': 'Emergency Access',
    },
    Generic: {
        'client.singular': 'Customer',
        'client.plural': 'Customers',
        'staff.singular': 'Employee',
        'staff.plural': 'Employees',
        'worker.singular': 'Worker',
        'worker.plural': 'Workers',
        'site.singular': 'Location',
        'site.plural': 'Locations',
        'asset.singular': 'Asset',
        'asset.plural': 'Assets',
        'shift.singular': 'Shift',
        'shift.plural': 'Shifts',
        'timesheet.singular': 'Timesheet',
        'timesheet.plural': 'Timesheets',
        'medication.singular': 'Medication',
        'medication.plural': 'Medications',
        'incident.singular': 'Incident',
        'incident.plural': 'Incidents',
        'risk.singular': 'Risk',
        'risk.plural': 'Risks',
        'note.singular': 'Note',
        'note.plural': 'Notes',
        'timeline.singular': 'Timeline',
        'timeline.plural': 'Timelines',
        'document.singular': 'Document',
        'document.plural': 'Documents',
        'report.singular': 'Report',
        'report.plural': 'Reports',
        'respite.singular': 'Respite',
        'respite.plural': 'Respite',
        'emergency_access.singular': 'Emergency Access',
        'emergency_access.plural': 'Emergency Access',
    },
};

export default function TerminologyPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const initial = Object.fromEntries(
        ALL_KEYS.map((k) => [
            k,
            (props.overrides?.[k] ?? props.defaults?.[k] ?? '').toString(),
        ]),
    );

    const form = useForm<{ labels: Record<string, string> }>({
        labels: initial,
    });

    if (!can?.settings?.manageTerminology) {
        return (
            <SettingsLayout>
                <HeadingSmall title="Terminology" description="" />
                <div className="rounded-md border p-4 text-sm">
                    You don't have permission to manage terminology.
                </div>
            </SettingsLayout>
        );
    }

    const applyPreset = (presetName: string) => {
        const preset = PRESETS[presetName];
        if (!preset) return;
        form.setData('labels', { ...form.data.labels, ...preset });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Terminology" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Terminology"
                        description="Customise labels used throughout the application to match your organisation's language."
                    />

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put('/settings/terminology');
                        }}
                        className="space-y-6"
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle>Label Configuration</CardTitle>
                                <CardDescription>
                                    Customise terminology used throughout the
                                    application to match your organisation's
                                    language.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                {/* Preset buttons */}
                                <div className="space-y-2">
                                    <p className="text-sm font-medium text-muted-foreground">
                                        Template presets
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {Object.keys(PRESETS).map((name) => (
                                            <Button
                                                key={name}
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    applyPreset(name)
                                                }
                                                className="hover:border-primary hover:text-primary"
                                            >
                                                {name}
                                                {name ===
                                                    'Disability Support' && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="ml-1.5 text-[10px]"
                                                    >
                                                        Default
                                                    </Badge>
                                                )}
                                            </Button>
                                        ))}
                                    </div>
                                </div>

                                {/* Terminology table */}
                                <div className="rounded-lg border">
                                    <div className="grid grid-cols-4 gap-4 border-b bg-muted/50 px-4 py-2.5 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        <div>Label</div>
                                        <div>Singular</div>
                                        <div>Plural</div>
                                        <div>Default</div>
                                    </div>
                                    <div className="divide-y">
                                        {TERM_ROWS.map((row) => (
                                            <div
                                                key={row.label}
                                                className="grid grid-cols-4 items-center gap-4 px-4 py-3"
                                            >
                                                <div className="text-sm font-medium">
                                                    {row.label}
                                                </div>
                                                <div>
                                                    <Input
                                                        value={
                                                            form.data.labels[
                                                                row.singularKey
                                                            ] ?? ''
                                                        }
                                                        placeholder={
                                                            props.defaults?.[
                                                                row.singularKey
                                                            ] ?? ''
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'labels',
                                                                {
                                                                    ...form.data
                                                                        .labels,
                                                                    [row.singularKey]:
                                                                        e.target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                        className="h-8 text-sm"
                                                    />
                                                </div>
                                                <div>
                                                    <Input
                                                        value={
                                                            form.data.labels[
                                                                row.pluralKey
                                                            ] ?? ''
                                                        }
                                                        placeholder={
                                                            props.defaults?.[
                                                                row.pluralKey
                                                            ] ?? ''
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'labels',
                                                                {
                                                                    ...form.data
                                                                        .labels,
                                                                    [row.pluralKey]:
                                                                        e.target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                        className="h-8 text-sm"
                                                    />
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {props.defaults?.[
                                                        row.singularKey
                                                    ] ?? ''}{' '}
                                                    /{' '}
                                                    {props.defaults?.[
                                                        row.pluralKey
                                                    ] ?? ''}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <p className="text-xs text-muted-foreground">
                                    Changes will be reflected across all modules
                                    immediately.
                                </p>
                            </CardContent>
                        </Card>

                        <div className="flex items-center gap-2">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="bg-primary hover:bg-primary"
                            >
                                Save Changes
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => form.reset()}
                            >
                                Reset
                            </Button>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
