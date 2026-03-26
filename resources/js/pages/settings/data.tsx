import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    CheckCircle,
    Clock,
    Download,
    FileSpreadsheet,
    FileText,
    Globe,
    Info,
    Loader2,
    Shield,
    Trash2,
    Upload,
    Users,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

// ---------------------------------------------------------------------------
// Static data
// ---------------------------------------------------------------------------

const exportModules = [
    { id: 'clients', label: 'Clients', count: 342 },
    { id: 'care-plans', label: 'Care Plans', count: 289 },
    { id: 'service-agreements', label: 'Service Agreements', count: 156 },
    { id: 'shifts', label: 'Shifts & Timesheets', count: 12840 },
    { id: 'progress-notes', label: 'Progress Notes', count: 8430 },
    { id: 'incidents', label: 'Incidents', count: 67 },
    { id: 'documents', label: 'Documents', count: 1205 },
    { id: 'hr-records', label: 'HR Records', count: 184 },
    { id: 'staff-credentials', label: 'Staff Credentials', count: 920 },
    { id: 'sites', label: 'Sites & Locations', count: 18 },
    { id: 'fleet', label: 'Fleet & Assets', count: 34 },
    { id: 'governance', label: 'Governance', count: 45 },
    { id: 'audit-logs', label: 'Audit Logs', count: 54200 },
    { id: 'notifications', label: 'Notifications', count: 31000 },
];

interface RecentExport {
    id: string;
    date: string;
    modules: string[];
    format: string;
    size: string;
    status: 'completed' | 'processing' | 'failed';
}

const recentExports: RecentExport[] = [
    { id: '1', date: '2026-03-25', modules: ['Clients', 'Care Plans', 'Progress Notes'], format: 'CSV', size: '12.4 MB', status: 'completed' },
    { id: '2', date: '2026-03-22', modules: ['Shifts & Timesheets'], format: 'Excel', size: '8.7 MB', status: 'completed' },
    { id: '3', date: '2026-03-20', modules: ['All Modules'], format: 'JSON', size: '—', status: 'processing' },
    { id: '4', date: '2026-03-18', modules: ['Audit Logs'], format: 'CSV', size: '—', status: 'failed' },
    { id: '5', date: '2026-03-01', modules: ['All Modules'], format: 'JSON', size: '28.7 MB', status: 'completed' },
];

interface RetentionRow {
    id: string;
    label: string;
    options: { value: string; label: string }[];
    defaultValue: string;
    count: string;
}

const retentionRows: RetentionRow[] = [
    { id: 'audit-logs', label: 'Audit logs', options: [{ value: '1yr', label: '1 year' }, { value: '2yr', label: '2 years' }, { value: '5yr', label: '5 years' }, { value: '7yr', label: '7 years' }, { value: 'forever', label: 'Forever' }], defaultValue: '5yr', count: '54,200' },
    { id: 'timesheets', label: 'Completed timesheets', options: [{ value: '2yr', label: '2 years' }, { value: '5yr', label: '5 years' }, { value: '7yr', label: '7 years' }, { value: 'forever', label: 'Forever' }], defaultValue: '7yr', count: '12,840' },
    { id: 'incidents', label: 'Closed incidents', options: [{ value: '2yr', label: '2 years' }, { value: '5yr', label: '5 years' }, { value: '7yr', label: '7 years' }, { value: 'forever', label: 'Forever' }], defaultValue: '5yr', count: '67' },
    { id: 'archived-clients', label: 'Archived clients', options: [{ value: '1yr', label: '1 year' }, { value: '2yr', label: '2 years' }, { value: '5yr', label: '5 years' }, { value: 'never', label: 'Never auto-delete' }], defaultValue: 'never', count: '23' },
    { id: 'notifications', label: 'Old notifications', options: [{ value: '30d', label: '30 days' }, { value: '90d', label: '90 days' }, { value: '1yr', label: '1 year' }, { value: 'forever', label: 'Forever' }], defaultValue: '90d', count: '31,000' },
    { id: 'session-logs', label: 'Session logs', options: [{ value: '30d', label: '30 days' }, { value: '90d', label: '90 days' }, { value: '1yr', label: '1 year' }], defaultValue: '90d', count: '18,430' },
    { id: 'deleted-docs', label: 'Deleted documents (trash)', options: [{ value: '30d', label: '30 days' }, { value: '90d', label: '90 days' }, { value: 'never', label: 'Never' }], defaultValue: '30d', count: '14' },
];

const importTypes = [
    { id: 'clients', title: 'Import Clients', description: 'Upload CSV with client details', icon: Users },
    { id: 'staff', title: 'Import Staff', description: 'Upload CSV with staff records', icon: Users },
    { id: 'shifts', title: 'Import Shifts', description: 'Upload historical shift data', icon: Clock },
    { id: 'documents', title: 'Import Documents', description: 'Bulk upload documents', icon: FileText },
];

// ---------------------------------------------------------------------------
// Breadcrumbs
// ---------------------------------------------------------------------------

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Data & Privacy' },
];

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function Data() {
    // Export state
    const [selectedModules, setSelectedModules] = useState<string[]>([]);
    const [exportFormat, setExportFormat] = useState('csv');
    const [exportDateFrom, setExportDateFrom] = useState('');
    const [exportDateTo, setExportDateTo] = useState('');
    const [includeArchived, setIncludeArchived] = useState(false);
    const [includeDeleted, setIncludeDeleted] = useState(false);

    // Retention state
    const [retentionValues, setRetentionValues] = useState<Record<string, string>>(() =>
        Object.fromEntries(retentionRows.map((r) => [r.id, r.defaultValue])),
    );

    // Privacy & consent state
    const [anonymisation, setAnonymisation] = useState(false);
    const [consentRequired, setConsentRequired] = useState(true);
    const [dataPortability, setDataPortability] = useState(true);
    const [rightToErasure, setRightToErasure] = useState(true);
    const [privacyUrl, setPrivacyUrl] = useState('');
    const [dpoName, setDpoName] = useState('');
    const [privacyEmail, setPrivacyEmail] = useState('');

    // NZ regulatory state
    const [privacyActMode, setPrivacyActMode] = useState(true);
    const [nzdsfReporting, setNzdsfReporting] = useState(false);
    const [healthInfoCode, setHealthInfoCode] = useState(true);
    const [dataSovereignty, setDataSovereignty] = useState('nz-only');

    // Danger zone dialogs
    const [showPurgeDialog, setShowPurgeDialog] = useState(false);
    const [purgeConfirmText, setPurgeConfirmText] = useState('');
    const [showDeleteOrgDialog, setShowDeleteOrgDialog] = useState(false);
    const [deleteOrgConfirmText, setDeleteOrgConfirmText] = useState('');

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    function toggleModule(id: string) {
        setSelectedModules((prev) =>
            prev.includes(id) ? prev.filter((m) => m !== id) : [...prev, id],
        );
    }

    function toggleAllModules() {
        if (selectedModules.length === exportModules.length) {
            setSelectedModules([]);
        } else {
            setSelectedModules(exportModules.map((m) => m.id));
        }
    }

    function handleExport() {
        // UI-only placeholder — would trigger backend export via router.post
    }

    function statusBadge(status: RecentExport['status']) {
        switch (status) {
            case 'completed':
                return (
                    <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-400">
                        <CheckCircle className="mr-1 h-3 w-3" /> Completed
                    </Badge>
                );
            case 'processing':
                return (
                    <Badge variant="outline" className="border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-400">
                        <Loader2 className="mr-1 h-3 w-3 animate-spin" /> Processing
                    </Badge>
                );
            case 'failed':
                return (
                    <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-400">
                        <XCircle className="mr-1 h-3 w-3" /> Failed
                    </Badge>
                );
        }
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Data & Privacy" />
            <SettingsLayout>
                <div className="space-y-8">

                    {/* ==========================================================
                        SECTION 1 — Data Export
                    ========================================================== */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-900/40">
                                    <Download className="h-5 w-5 text-violet-600" />
                                </div>
                                <div>
                                    <CardTitle>Data Export</CardTitle>
                                    <CardDescription>
                                        Download your organisation's data for compliance, backup, or migration
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Module selection */}
                            <div>
                                <div className="mb-3 flex items-center justify-between">
                                    <Label className="text-sm font-medium">Modules to export</Label>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-auto px-2 py-1 text-xs text-violet-600"
                                        onClick={toggleAllModules}
                                    >
                                        {selectedModules.length === exportModules.length ? 'Deselect all' : 'Select all'}
                                    </Button>
                                </div>
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    {exportModules.map((mod) => (
                                        <label
                                            key={mod.id}
                                            className="flex cursor-pointer items-center gap-2 rounded-md border p-2.5 transition hover:bg-muted/50"
                                        >
                                            <Checkbox
                                                checked={selectedModules.includes(mod.id)}
                                                onCheckedChange={() => toggleModule(mod.id)}
                                            />
                                            <span className="flex-1 text-sm">{mod.label}</span>
                                            <Badge variant="secondary" className="text-xs tabular-nums">
                                                {mod.count.toLocaleString()}
                                            </Badge>
                                        </label>
                                    ))}
                                </div>
                            </div>

                            {/* Format selection */}
                            <div>
                                <Label className="mb-2 block text-sm font-medium">Export format</Label>
                                <div className="flex gap-4">
                                    {[
                                        { value: 'csv', label: 'CSV' },
                                        { value: 'json', label: 'JSON' },
                                        { value: 'excel', label: 'Excel' },
                                    ].map((fmt) => (
                                        <label key={fmt.value} className="flex cursor-pointer items-center gap-2 text-sm">
                                            <input
                                                type="radio"
                                                name="export-format"
                                                value={fmt.value}
                                                checked={exportFormat === fmt.value}
                                                onChange={() => setExportFormat(fmt.value)}
                                                className="accent-violet-600"
                                            />
                                            {fmt.label}
                                        </label>
                                    ))}
                                </div>
                            </div>

                            {/* Date range */}
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="export-from">From date (optional)</Label>
                                    <Input
                                        id="export-from"
                                        type="date"
                                        value={exportDateFrom}
                                        onChange={(e) => setExportDateFrom(e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="export-to">To date (optional)</Label>
                                    <Input
                                        id="export-to"
                                        type="date"
                                        value={exportDateTo}
                                        onChange={(e) => setExportDateTo(e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            {/* Toggles */}
                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="include-archived" className="text-sm">
                                        Include archived records
                                    </Label>
                                    <Switch id="include-archived" checked={includeArchived} onCheckedChange={setIncludeArchived} />
                                </div>
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="include-deleted" className="text-sm">
                                        Include deleted records (soft-deleted)
                                    </Label>
                                    <Switch id="include-deleted" checked={includeDeleted} onCheckedChange={setIncludeDeleted} />
                                </div>
                            </div>

                            {/* Export button */}
                            <Button
                                onClick={handleExport}
                                disabled={selectedModules.length === 0}
                                className="bg-violet-600 hover:bg-violet-700"
                            >
                                <Download className="mr-2 h-4 w-4" />
                                Export Selected Data
                            </Button>

                            {/* Recent exports table */}
                            <div className="space-y-3 pt-2">
                                <h4 className="text-sm font-medium">Recent Exports</h4>
                                <div className="overflow-x-auto rounded-lg border">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="px-4 py-2.5 text-left font-medium">Date</th>
                                                <th className="px-4 py-2.5 text-left font-medium">Modules</th>
                                                <th className="px-4 py-2.5 text-left font-medium">Format</th>
                                                <th className="px-4 py-2.5 text-left font-medium">Size</th>
                                                <th className="px-4 py-2.5 text-left font-medium">Status</th>
                                                <th className="px-4 py-2.5 text-right font-medium" />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {recentExports.map((exp) => (
                                                <tr key={exp.id} className="border-b last:border-0">
                                                    <td className="whitespace-nowrap px-4 py-2.5">{exp.date}</td>
                                                    <td className="px-4 py-2.5 text-muted-foreground">{exp.modules.join(', ')}</td>
                                                    <td className="px-4 py-2.5">{exp.format}</td>
                                                    <td className="px-4 py-2.5 tabular-nums">{exp.size}</td>
                                                    <td className="px-4 py-2.5">{statusBadge(exp.status)}</td>
                                                    <td className="px-4 py-2.5 text-right">
                                                        {exp.status === 'completed' && (
                                                            <Button variant="ghost" size="sm" className="h-auto px-2 py-1 text-xs text-violet-600">
                                                                <Download className="mr-1 h-3 w-3" /> Download
                                                            </Button>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {/* Privacy notice */}
                            <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-400">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    Exported data may contain personal information. Handle in accordance with the Privacy Act 2020.
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* ==========================================================
                        SECTION 2 — Data Retention
                    ========================================================== */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-900/40">
                                    <Archive className="h-5 w-5 text-violet-600" />
                                </div>
                                <div>
                                    <CardTitle>Data Retention</CardTitle>
                                    <CardDescription>
                                        Configure automatic data cleanup policies to manage storage and comply with regulations
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="px-4 py-2.5 text-left font-medium">Data Type</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Current Policy</th>
                                            <th className="px-4 py-2.5 text-right font-medium">Records</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {retentionRows.map((row) => (
                                            <tr key={row.id} className="border-b last:border-0">
                                                <td className="px-4 py-2.5 font-medium">{row.label}</td>
                                                <td className="px-4 py-2.5">
                                                    <Select
                                                        value={retentionValues[row.id]}
                                                        onValueChange={(v) =>
                                                            setRetentionValues((prev) => ({ ...prev, [row.id]: v }))
                                                        }
                                                    >
                                                        <SelectTrigger className="h-8 w-44">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {row.options.map((opt) => (
                                                                <SelectItem key={opt.value} value={opt.value}>
                                                                    {opt.label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </td>
                                                <td className="px-4 py-2.5 text-right tabular-nums text-muted-foreground">
                                                    {row.count}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <Button className="bg-violet-600 hover:bg-violet-700">Save Retention Policies</Button>

                            <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-400">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    Changes to retention policies will take effect on the next scheduled cleanup. Data deleted by retention policies cannot be recovered.
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* ==========================================================
                        SECTION 3 — Privacy & Consent
                    ========================================================== */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-900/40">
                                    <Shield className="h-5 w-5 text-violet-600" />
                                </div>
                                <div>
                                    <CardTitle>Privacy & Consent</CardTitle>
                                    <CardDescription>
                                        Manage privacy settings in accordance with the NZ Privacy Act 2020
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            {/* Toggles */}
                            <div className="space-y-4">
                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium">Enable client data anonymisation</p>
                                        <p className="text-xs text-muted-foreground">
                                            Replace personal details with anonymised identifiers when archiving records
                                        </p>
                                    </div>
                                    <Switch checked={anonymisation} onCheckedChange={setAnonymisation} />
                                </div>

                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium">Require explicit consent before data collection</p>
                                        <p className="text-xs text-muted-foreground">
                                            Show consent prompt when creating new client records
                                        </p>
                                    </div>
                                    <Switch checked={consentRequired} onCheckedChange={setConsentRequired} />
                                </div>

                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium">Allow data portability requests</p>
                                        <p className="text-xs text-muted-foreground">
                                            Enable clients/whanau to request a copy of their data
                                        </p>
                                    </div>
                                    <Switch checked={dataPortability} onCheckedChange={setDataPortability} />
                                </div>

                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium">Right to erasure (deletion requests)</p>
                                        <p className="text-xs text-muted-foreground">
                                            Allow clients to request deletion of their personal data
                                        </p>
                                    </div>
                                    <Switch checked={rightToErasure} onCheckedChange={setRightToErasure} />
                                </div>
                            </div>

                            {/* Text inputs */}
                            <div className="space-y-4 pt-1">
                                <div>
                                    <Label htmlFor="privacy-url">Privacy Policy URL</Label>
                                    <Input
                                        id="privacy-url"
                                        value={privacyUrl}
                                        onChange={(e) => setPrivacyUrl(e.target.value)}
                                        placeholder="https://yourorganisation.co.nz/privacy"
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="dpo-name">Data Protection Officer</Label>
                                    <Input
                                        id="dpo-name"
                                        value={dpoName}
                                        onChange={(e) => setDpoName(e.target.value)}
                                        placeholder="Name and contact details"
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="privacy-email">Privacy Officer Email</Label>
                                    <Input
                                        id="privacy-email"
                                        type="email"
                                        value={privacyEmail}
                                        onChange={(e) => setPrivacyEmail(e.target.value)}
                                        placeholder="privacy@yourorganisation.co.nz"
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <Button className="bg-violet-600 hover:bg-violet-700">Save Privacy Settings</Button>
                        </CardContent>
                    </Card>

                    {/* ==========================================================
                        SECTION 4 — NZ Regulatory Compliance
                    ========================================================== */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-900/40">
                                    <Globe className="h-5 w-5 text-violet-600" />
                                </div>
                                <div>
                                    <CardTitle>NZ Regulatory Compliance</CardTitle>
                                    <CardDescription>
                                        Settings specific to New Zealand privacy and disability support regulations
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <div className="space-y-4">
                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium">Privacy Act 2020 compliance mode</p>
                                        <p className="text-xs text-muted-foreground">
                                            Enforce stricter data handling rules as required by the NZ Privacy Act
                                        </p>
                                    </div>
                                    <Switch checked={privacyActMode} onCheckedChange={setPrivacyActMode} />
                                </div>

                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium">NZDSF (NZ Disability Strategy Framework) reporting</p>
                                        <p className="text-xs text-muted-foreground">
                                            Enable data collection fields required for NZDSF reporting
                                        </p>
                                    </div>
                                    <Switch checked={nzdsfReporting} onCheckedChange={setNzdsfReporting} />
                                </div>

                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium">Health Information Privacy Code</p>
                                        <p className="text-xs text-muted-foreground">
                                            Apply additional protections to health-related data (medical records, medications)
                                        </p>
                                    </div>
                                    <Switch checked={healthInfoCode} onCheckedChange={setHealthInfoCode} />
                                </div>

                                <div>
                                    <Label>Data sovereignty</Label>
                                    <p className="mb-2 text-xs text-muted-foreground">
                                        Restrict where data can be stored and processed
                                    </p>
                                    <Select value={dataSovereignty} onValueChange={setDataSovereignty}>
                                        <SelectTrigger className="w-56">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="nz-only">New Zealand only</SelectItem>
                                            <SelectItem value="au-nz">Australia & NZ</SelectItem>
                                            <SelectItem value="none">No restriction</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            {/* Info card */}
                            <div className="flex items-start gap-2 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-400">
                                <Info className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    Under the Privacy Act 2020, individuals have the right to access, correct, and request deletion
                                    of their personal information. Ensure your organisation has processes in place to handle these
                                    requests within 20 working days.
                                </span>
                            </div>

                            <Button className="bg-violet-600 hover:bg-violet-700">Save Compliance Settings</Button>
                        </CardContent>
                    </Card>

                    {/* ==========================================================
                        SECTION 5 — Data Import
                    ========================================================== */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-900/40">
                                    <Upload className="h-5 w-5 text-violet-600" />
                                </div>
                                <div>
                                    <CardTitle>Data Import</CardTitle>
                                    <CardDescription>Import data from other systems or spreadsheets</CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                <FileSpreadsheet className="h-4 w-4" />
                                Supported formats: CSV, JSON, Excel (.xlsx)
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {importTypes.map((imp) => {
                                    const Icon = imp.icon;
                                    return (
                                        <div
                                            key={imp.id}
                                            className="flex flex-col items-center gap-3 rounded-lg border border-dashed p-5 text-center transition hover:border-violet-300 hover:bg-violet-50/50 dark:hover:border-violet-700 dark:hover:bg-violet-950/20"
                                        >
                                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-violet-100 dark:bg-violet-900/40">
                                                <Icon className="h-5 w-5 text-violet-600" />
                                            </div>
                                            <div>
                                                <p className="text-sm font-medium">{imp.title}</p>
                                                <p className="mt-0.5 text-xs text-muted-foreground">{imp.description}</p>
                                            </div>
                                            <Button variant="outline" size="sm" className="mt-1">
                                                <Upload className="mr-1.5 h-3 w-3" /> Choose File
                                            </Button>
                                        </div>
                                    );
                                })}
                            </div>

                            <div className="flex items-start gap-2 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-400">
                                <Info className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    Data imports are validated before processing. You'll be able to review and confirm before any
                                    records are created.
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* ==========================================================
                        SECTION 6 — Danger Zone
                    ========================================================== */}
                    <Card className="border-red-200 dark:border-red-900">
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-red-100 dark:bg-red-900/40">
                                    <Trash2 className="h-5 w-5 text-red-600" />
                                </div>
                                <div>
                                    <CardTitle className="text-red-600">Danger Zone</CardTitle>
                                    <CardDescription>Irreversible actions that affect all organisation data</CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Purge deleted records */}
                            <div className="flex items-center justify-between rounded-lg border border-red-200 p-4 dark:border-red-900">
                                <div>
                                    <p className="text-sm font-medium">Purge All Deleted Records</p>
                                    <p className="text-xs text-muted-foreground">
                                        Permanently remove all soft-deleted records across all modules
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    className="border-red-300 text-red-600 hover:bg-red-50 hover:text-red-700 dark:border-red-800 dark:hover:bg-red-950/30"
                                    onClick={() => setShowPurgeDialog(true)}
                                >
                                    Purge Records
                                </Button>
                            </div>

                            {/* Reset demo data */}
                            <div className="flex items-center justify-between rounded-lg border border-red-200 p-4 dark:border-red-900">
                                <div>
                                    <p className="text-sm font-medium">Reset Demo Data</p>
                                    <p className="text-xs text-muted-foreground">
                                        Remove all demo/seed data and start fresh (demo mode only)
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    className="border-red-300 text-red-600 hover:bg-red-50 hover:text-red-700 dark:border-red-800 dark:hover:bg-red-950/30"
                                >
                                    Reset Demo
                                </Button>
                            </div>

                            {/* Delete organisation */}
                            <div className="flex items-center justify-between rounded-lg border border-red-200 p-4 dark:border-red-900">
                                <div>
                                    <p className="text-sm font-medium">Delete Organisation</p>
                                    <p className="text-xs text-muted-foreground">
                                        Permanently delete your entire organisation and all associated data
                                    </p>
                                </div>
                                <Button variant="destructive" onClick={() => setShowDeleteOrgDialog(true)}>
                                    Delete Organisation
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* ==============================================================
                    DIALOGS
                ============================================================== */}

                {/* Purge confirmation dialog */}
                <Dialog open={showPurgeDialog} onOpenChange={setShowPurgeDialog}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle className="text-red-600">Purge All Deleted Records</DialogTitle>
                            <DialogDescription>
                                This will permanently remove all soft-deleted records across all modules. This action cannot be
                                undone.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-3">
                            <Label htmlFor="purge-confirm">
                                Type <span className="font-mono font-bold">PURGE</span> to confirm
                            </Label>
                            <Input
                                id="purge-confirm"
                                value={purgeConfirmText}
                                onChange={(e) => setPurgeConfirmText(e.target.value)}
                                placeholder="PURGE"
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setShowPurgeDialog(false);
                                    setPurgeConfirmText('');
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                disabled={purgeConfirmText !== 'PURGE'}
                                onClick={() => {
                                    setShowPurgeDialog(false);
                                    setPurgeConfirmText('');
                                }}
                            >
                                Purge All Records
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Delete organisation confirmation dialog */}
                <Dialog open={showDeleteOrgDialog} onOpenChange={setShowDeleteOrgDialog}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle className="text-red-600">Delete Organisation</DialogTitle>
                            <DialogDescription>
                                This will permanently delete your entire organisation including all clients, staff, records, and
                                configurations. This action cannot be undone.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-3">
                            <Label htmlFor="delete-org-confirm">Type your organisation name to confirm</Label>
                            <Input
                                id="delete-org-confirm"
                                value={deleteOrgConfirmText}
                                onChange={(e) => setDeleteOrgConfirmText(e.target.value)}
                                placeholder="Organisation name"
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setShowDeleteOrgDialog(false);
                                    setDeleteOrgConfirmText('');
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                disabled={deleteOrgConfirmText.length === 0}
                                onClick={() => {
                                    setShowDeleteOrgDialog(false);
                                    setDeleteOrgConfirmText('');
                                }}
                            >
                                Permanently Delete Organisation
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
