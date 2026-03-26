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
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    Building2,
    CheckCircle,
    Clock,
    Download,
    Eye,
    FileSpreadsheet,
    FileText,
    Globe,
    Info,
    Loader2,
    Lock,
    Plus,
    Shield,
    ShieldAlert,
    Trash2,
    Upload,
    Users,
    XCircle,
    Database,
    Landmark,
    Settings,
} from 'lucide-react';
import { useState } from 'react';

// ---------------------------------------------------------------------------
// Static data -- Export modules grouped by category
// ---------------------------------------------------------------------------

interface ExportModule {
    id: string;
    label: string;
    count: number;
    encrypted?: boolean;
}

interface ExportCategory {
    name: string;
    encrypted?: boolean;
    modules: ExportModule[];
}

const exportCategories: ExportCategory[] = [
    {
        name: 'Client & Care',
        modules: [
            { id: 'clients', label: 'Clients & Personal Information', count: 342 },
            { id: 'care-plans', label: 'Care Plans & Goals', count: 289 },
            { id: 'service-agreements', label: 'Service Agreements & Funding', count: 156 },
            { id: 'progress-notes', label: 'Progress Notes', count: 8430 },
            { id: 'client-consents', label: 'Client Consents & Withdrawals', count: 412 },
            { id: 'client-funds', label: 'Client Funds & Transactions', count: 1870 },
        ],
    },
    {
        name: 'Medical & Health',
        encrypted: true,
        modules: [
            { id: 'medical-profiles', label: 'Medical Profiles & Conditions', count: 318, encrypted: true },
            { id: 'medications', label: 'Medications & MAR Records', count: 2640, encrypted: true },
            { id: 'emergency-contacts', label: 'Emergency Contacts', count: 685, encrypted: true },
            { id: 'risk-assessments', label: 'Risk Assessments', count: 198, encrypted: true },
        ],
    },
    {
        name: 'Operations',
        modules: [
            { id: 'shifts', label: 'Shifts & Timesheets', count: 12840 },
            { id: 'rostering', label: 'Rostering & Availability', count: 3200 },
            { id: 'incidents', label: 'Incidents & Investigations', count: 67 },
            { id: 'safeguarding', label: 'Safeguarding Records', count: 23 },
            { id: 'documents', label: 'Documents & Files', count: 1205 },
        ],
    },
    {
        name: 'HR & People',
        modules: [
            { id: 'staff-records', label: 'Staff Records & Credentials', count: 920 },
            { id: 'leave-attendance', label: 'Leave & Attendance', count: 4100 },
            { id: 'training', label: 'Training & Compliance', count: 1560 },
            { id: 'payroll', label: 'Payroll & Expenses', count: 7300 },
        ],
    },
    {
        name: 'Sites & Compliance',
        modules: [
            { id: 'sites', label: 'Sites & Locations', count: 18 },
            { id: 'certifications', label: 'Certifications & Compliance Checks', count: 42 },
            { id: 'respite', label: 'Respite Bookings', count: 156 },
        ],
    },
    {
        name: 'Other',
        modules: [
            { id: 'fleet', label: 'Fleet & Assets', count: 34 },
            { id: 'governance', label: 'Governance & Board', count: 45 },
            { id: 'audit-logs', label: 'Audit Logs', count: 54200 },
            { id: 'notifications', label: 'Notifications', count: 31000 },
            { id: 'control-room', label: 'Control Room Logs', count: 2890 },
        ],
    },
];

const allModuleIds = exportCategories.flatMap((c) => c.modules.map((m) => m.id));
const medicalModuleIds = exportCategories
    .filter((c) => c.encrypted)
    .flatMap((c) => c.modules.map((m) => m.id));

// ---------------------------------------------------------------------------
// Recent exports
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// DSAR placeholder data
// ---------------------------------------------------------------------------

interface DsarRequest {
    id: string;
    type: string;
    requester: string;
    dateReceived: string;
    dueDate: string;
    workingDaysLeft: number;
    status: 'new' | 'in_progress' | 'completed' | 'overdue';
    assignedTo: string;
}

const placeholderDsarRequests: DsarRequest[] = [
    { id: 'DSAR-001', type: 'Access', requester: 'Sarah Thompson', dateReceived: '2026-03-20', dueDate: '2026-04-17', workingDaysLeft: 16, status: 'in_progress', assignedTo: 'Emma Wilson' },
    { id: 'DSAR-002', type: 'Erasure', requester: 'David Chen', dateReceived: '2026-03-18', dueDate: '2026-04-15', workingDaysLeft: 14, status: 'new', assignedTo: 'Unassigned' },
    { id: 'DSAR-003', type: 'Correction', requester: 'Maria Garcia (wh\u0101nau)', dateReceived: '2026-03-05', dueDate: '2026-04-02', workingDaysLeft: 5, status: 'in_progress', assignedTo: 'James Lee' },
    { id: 'DSAR-004', type: 'Access', requester: 'John Williams', dateReceived: '2026-02-10', dueDate: '2026-03-10', workingDaysLeft: -12, status: 'overdue', assignedTo: 'Emma Wilson' },
    { id: 'DSAR-005', type: 'Portability', requester: 'Lisa Brown', dateReceived: '2026-02-25', dueDate: '2026-03-25', workingDaysLeft: 0, status: 'completed', assignedTo: 'James Lee' },
];

// ---------------------------------------------------------------------------
// Breach log placeholder data
// ---------------------------------------------------------------------------

interface DataBreach {
    id: string;
    date: string;
    type: string;
    severity: 'low' | 'medium' | 'high' | 'critical';
    individualsAffected: number;
    commissionerNotified: boolean;
    status: 'investigating' | 'contained' | 'resolved' | 'reported';
}

const placeholderBreaches: DataBreach[] = [
    { id: 'BRE-001', date: '2026-03-15', type: 'Employee error', severity: 'medium', individualsAffected: 3, commissionerNotified: false, status: 'investigating' },
    { id: 'BRE-002', date: '2026-02-28', type: 'Unauthorised access', severity: 'high', individualsAffected: 12, commissionerNotified: true, status: 'reported' },
    { id: 'BRE-003', date: '2026-01-10', type: 'Data loss', severity: 'low', individualsAffected: 1, commissionerNotified: false, status: 'resolved' },
];

// ---------------------------------------------------------------------------
// Third-party processor placeholder data
// ---------------------------------------------------------------------------

interface DataProcessor {
    id: string;
    company: string;
    contact: string;
    purposes: string[];
    dataCategories: string[];
    agreementStatus: 'dpa_signed' | 'standard_terms' | 'negotiating' | 'no_agreement';
    country: string;
    countryFlag: string;
    reviewDate: string;
    overdue: boolean;
}

const placeholderProcessors: DataProcessor[] = [
    { id: '1', company: 'Xero', contact: 'Account Manager', purposes: ['Payroll integration'], dataCategories: ['Employment', 'Financial'], agreementStatus: 'dpa_signed', country: 'New Zealand', countryFlag: '\ud83c\uddf3\ud83c\uddff', reviewDate: '2026-09-15', overdue: false },
    { id: '2', company: 'Amazon Web Services', contact: 'Enterprise Support', purposes: ['Cloud hosting', 'Backup'], dataCategories: ['Personal info', 'Health data', 'Financial', 'Employment'], agreementStatus: 'dpa_signed', country: 'Australia', countryFlag: '\ud83c\udde6\ud83c\uddfa', reviewDate: '2026-12-01', overdue: false },
    { id: '3', company: 'SendGrid', contact: 'API Support', purposes: ['Email delivery'], dataCategories: ['Personal info'], agreementStatus: 'standard_terms', country: 'USA', countryFlag: '\ud83c\uddfa\ud83c\uddf8', reviewDate: '2026-06-30', overdue: false },
    { id: '4', company: 'Twilio', contact: 'Support Team', purposes: ['SMS delivery'], dataCategories: ['Personal info'], agreementStatus: 'negotiating', country: 'USA', countryFlag: '\ud83c\uddfa\ud83c\uddf8', reviewDate: '2026-04-01', overdue: false },
    { id: '5', company: 'Auth0', contact: 'Security Team', purposes: ['SSO/Authentication'], dataCategories: ['Personal info'], agreementStatus: 'dpa_signed', country: 'USA', countryFlag: '\ud83c\uddfa\ud83c\uddf8', reviewDate: '2025-12-15', overdue: true },
    { id: '6', company: 'Google Calendar', contact: 'Workspace Admin', purposes: ['Calendar sync'], dataCategories: ['Personal info'], agreementStatus: 'standard_terms', country: 'USA', countryFlag: '\ud83c\uddfa\ud83c\uddf8', reviewDate: '2026-08-01', overdue: false },
];

// ---------------------------------------------------------------------------
// Data Retention rows
// ---------------------------------------------------------------------------

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
    const [healthCustodian, setHealthCustodian] = useState('');
    const [privacyOfficer, setPrivacyOfficer] = useState('');
    const [requirePrivacyOfficerApproval, setRequirePrivacyOfficerApproval] = useState(false);
    const [logMedicalAccess, setLogMedicalAccess] = useState(true);

    // DSAR state
    const [showDsarDialog, setShowDsarDialog] = useState(false);
    const [dsarType, setDsarType] = useState('access');
    const [dsarRequesterName, setDsarRequesterName] = useState('');
    const [dsarRequesterEmail, setDsarRequesterEmail] = useState('');
    const [dsarRequesterPhone, setDsarRequesterPhone] = useState('');
    const [dsarRelationship, setDsarRelationship] = useState('self');
    const [dsarDetails, setDsarDetails] = useState('');
    const [dsarIdentityVerified, setDsarIdentityVerified] = useState(false);

    // Data Breach state
    const [showBreachDialog, setShowBreachDialog] = useState(false);
    const [breachType, setBreachType] = useState('unauthorised_access');
    const [breachSeverity, setBreachSeverity] = useState('medium');
    const [breachDescription, setBreachDescription] = useState('');
    const [breachDataTypes, setBreachDataTypes] = useState<string[]>([]);
    const [breachIndividuals, setBreachIndividuals] = useState('');
    const [breachDiscoveryDate, setBreachDiscoveryDate] = useState('');
    const [breachCommissionerNotified, setBreachCommissionerNotified] = useState(false);
    const [breachIndividualsNotified, setBreachIndividualsNotified] = useState(false);

    // Third-party processor state
    const [showProcessorDialog, setShowProcessorDialog] = useState(false);
    const [processorCompany, setProcessorCompany] = useState('');
    const [processorContact, setProcessorContact] = useState('');
    const [processorEmail, setProcessorEmail] = useState('');
    const [processorPurpose, setProcessorPurpose] = useState('cloud_hosting');
    const [processorDataCategories, setProcessorDataCategories] = useState<string[]>([]);
    const [processorAgreement, setProcessorAgreement] = useState('no_agreement');
    const [processorCountry, setProcessorCountry] = useState('nz');
    const [processorReviewDate, setProcessorReviewDate] = useState('');

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
        if (selectedModules.length === allModuleIds.length) {
            setSelectedModules([]);
        } else {
            setSelectedModules([...allModuleIds]);
        }
    }

    function handleExport() {
        // UI-only placeholder — would trigger backend export via router.post
    }

    function toggleBreachDataType(type: string) {
        setBreachDataTypes((prev) =>
            prev.includes(type) ? prev.filter((t) => t !== type) : [...prev, type],
        );
    }

    function toggleProcessorDataCategory(cat: string) {
        setProcessorDataCategories((prev) =>
            prev.includes(cat) ? prev.filter((c) => c !== cat) : [...prev, cat],
        );
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

    function dsarStatusBadge(status: DsarRequest['status']) {
        switch (status) {
            case 'new':
                return <Badge variant="outline" className="border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-400">New</Badge>;
            case 'in_progress':
                return <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-400">In Progress</Badge>;
            case 'completed':
                return (
                    <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-400">
                        <CheckCircle className="mr-1 h-3 w-3" /> Completed
                    </Badge>
                );
            case 'overdue':
                return (
                    <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-400">
                        <AlertTriangle className="mr-1 h-3 w-3" /> Overdue
                    </Badge>
                );
        }
    }

    function severityBadge(severity: DataBreach['severity']) {
        switch (severity) {
            case 'low':
                return <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-400">Low</Badge>;
            case 'medium':
                return <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-400">Medium</Badge>;
            case 'high':
                return <Badge variant="outline" className="border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-800 dark:bg-orange-950 dark:text-orange-400">High</Badge>;
            case 'critical':
                return <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-400">Critical</Badge>;
        }
    }

    function agreementBadge(status: DataProcessor['agreementStatus']) {
        switch (status) {
            case 'dpa_signed':
                return <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-400">DPA Signed</Badge>;
            case 'standard_terms':
                return <Badge variant="outline" className="border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-400">Standard Terms</Badge>;
            case 'negotiating':
                return <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-400">Under Negotiation</Badge>;
            case 'no_agreement':
                return <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-400">No Agreement</Badge>;
        }
    }

    // Computed values
    const hasMedicalSelected = selectedModules.some((id) => medicalModuleIds.includes(id));
    const dsarOpen = placeholderDsarRequests.filter((r) => r.status === 'new' || r.status === 'in_progress').length;
    const dsarCompleted = placeholderDsarRequests.filter((r) => r.status === 'completed').length;
    const dsarOverdue = placeholderDsarRequests.filter((r) => r.status === 'overdue').length;

    function calcDueDate(): string {
        const today = new Date();
        let added = 0;
        const d = new Date(today);
        while (added < 20) {
            d.setDate(d.getDate() + 1);
            const day = d.getDay();
            if (day !== 0 && day !== 6) added++;
        }
        return d.toISOString().split('T')[0];
    }

    function calc72HourDeadline(discoveryDate: string): string {
        if (!discoveryDate) return '';
        const d = new Date(discoveryDate);
        d.setHours(d.getHours() + 72);
        return d.toISOString().split('T')[0];
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Data & Privacy" />
            <SettingsLayout>
                <div className="mb-6">
                    <h2 className="text-2xl font-bold">Data & Privacy</h2>
                    <p className="mt-1 text-sm text-muted-foreground">Manage data exports, privacy requests, retention policies, and compliance settings.</p>
                </div>

                <TabsRoot defaultValue="export" className="space-y-6">
                    <TabsList className="w-full justify-start border-b bg-transparent p-0">
                        <TabsTrigger value="export" className="gap-1.5 data-[state=active]:border-b-2 data-[state=active]:border-violet-600 rounded-none"><Database className="h-3.5 w-3.5" />Export & Import</TabsTrigger>
                        <TabsTrigger value="requests" className="gap-1.5 data-[state=active]:border-b-2 data-[state=active]:border-violet-600 rounded-none"><Shield className="h-3.5 w-3.5" />Privacy Requests</TabsTrigger>
                        <TabsTrigger value="retention" className="gap-1.5 data-[state=active]:border-b-2 data-[state=active]:border-violet-600 rounded-none"><Clock className="h-3.5 w-3.5" />Retention</TabsTrigger>
                        <TabsTrigger value="compliance" className="gap-1.5 data-[state=active]:border-b-2 data-[state=active]:border-violet-600 rounded-none"><Landmark className="h-3.5 w-3.5" />Compliance</TabsTrigger>
                        <TabsTrigger value="settings" className="gap-1.5 data-[state=active]:border-b-2 data-[state=active]:border-violet-600 rounded-none"><Settings className="h-3.5 w-3.5" />Settings</TabsTrigger>
                    </TabsList>

                <TabsContent value="export" className="space-y-8">

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
                                        {selectedModules.length === allModuleIds.length ? 'Deselect all' : 'Select all'}
                                    </Button>
                                </div>

                                <div className="space-y-5">
                                    {exportCategories.map((category) => (
                                        <div key={category.name}>
                                            <h4 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                                {category.encrypted && <Lock className="h-3.5 w-3.5 text-amber-500" />}
                                                {category.name}
                                                {category.encrypted && (
                                                    <span className="text-[10px] font-normal normal-case tracking-normal text-amber-600 dark:text-amber-400">
                                                        Encrypted export
                                                    </span>
                                                )}
                                            </h4>
                                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                {category.modules.map((mod) => (
                                                    <label
                                                        key={mod.id}
                                                        className="flex cursor-pointer items-center gap-2 rounded-md border p-2.5 transition hover:bg-muted/50"
                                                    >
                                                        <Checkbox
                                                            checked={selectedModules.includes(mod.id)}
                                                            onCheckedChange={() => toggleModule(mod.id)}
                                                        />
                                                        <span className="flex flex-1 items-center gap-1.5 text-sm">
                                                            {mod.encrypted && <Lock className="h-3 w-3 shrink-0 text-amber-500" />}
                                                            {mod.label}
                                                        </span>
                                                        <Badge variant="secondary" className="text-xs tabular-nums">
                                                            {mod.count.toLocaleString()}
                                                        </Badge>
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                {/* Medical data warning */}
                                {hasMedicalSelected && (
                                    <div className="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-400">
                                        <Lock className="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>
                                            Health data exports are encrypted and require Privacy Officer approval
                                        </span>
                                    </div>
                                )}
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
                        SECTION 8 -- Data Import
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

                </TabsContent>

                <TabsContent value="requests" className="space-y-8">

                    {/* ==========================================================
                        SECTION 2 -- Data Subject Access Requests (DSAR)
                    ========================================================== */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-900/40">
                                        <Eye className="h-5 w-5 text-violet-600" />
                                    </div>
                                    <div>
                                        <CardTitle>Data Subject Access Requests (DSAR)</CardTitle>
                                        <CardDescription>
                                            Track and manage requests from individuals to access, correct, or delete their personal data (Privacy Act 2020)
                                        </CardDescription>
                                    </div>
                                </div>
                                <Button
                                    className="bg-violet-600 hover:bg-violet-700"
                                    onClick={() => setShowDsarDialog(true)}
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    New Request
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Stats row */}
                            <div className="grid grid-cols-3 gap-4">
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-center dark:border-amber-800 dark:bg-amber-950/30">
                                    <p className="text-2xl font-bold tabular-nums text-amber-700 dark:text-amber-400">{dsarOpen}</p>
                                    <p className="text-xs text-amber-600 dark:text-amber-500">Open Requests</p>
                                </div>
                                <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-center dark:border-emerald-800 dark:bg-emerald-950/30">
                                    <p className="text-2xl font-bold tabular-nums text-emerald-700 dark:text-emerald-400">{dsarCompleted}</p>
                                    <p className="text-xs text-emerald-600 dark:text-emerald-500">Completed</p>
                                </div>
                                <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-center dark:border-red-800 dark:bg-red-950/30">
                                    <p className="text-2xl font-bold tabular-nums text-red-700 dark:text-red-400">{dsarOverdue}</p>
                                    <p className="text-xs text-red-600 dark:text-red-500">Overdue (&gt;20 working days)</p>
                                </div>
                            </div>

                            {/* Request list table */}
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="px-4 py-2.5 text-left font-medium">ID</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Type</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Requester</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Received</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Due Date</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Status</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Assigned To</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {placeholderDsarRequests.map((req) => (
                                            <tr key={req.id} className="border-b last:border-0">
                                                <td className="whitespace-nowrap px-4 py-2.5 font-mono text-xs">{req.id}</td>
                                                <td className="px-4 py-2.5">
                                                    <Badge variant="outline" className="border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-800 dark:bg-violet-950 dark:text-violet-400">
                                                        {req.type}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-2.5">{req.requester}</td>
                                                <td className="whitespace-nowrap px-4 py-2.5">{req.dateReceived}</td>
                                                <td className="whitespace-nowrap px-4 py-2.5">
                                                    <span className={req.workingDaysLeft <= 0 ? 'font-semibold text-red-600' : req.workingDaysLeft <= 5 ? 'font-semibold text-amber-600' : ''}>
                                                        {req.dueDate}
                                                    </span>
                                                    <span className={`ml-1.5 text-xs ${req.workingDaysLeft <= 0 ? 'text-red-500' : req.workingDaysLeft <= 5 ? 'text-amber-500' : 'text-muted-foreground'}`}>
                                                        ({req.workingDaysLeft <= 0 ? `${Math.abs(req.workingDaysLeft)}d overdue` : `${req.workingDaysLeft}d left`})
                                                    </span>
                                                </td>
                                                <td className="px-4 py-2.5">{dsarStatusBadge(req.status)}</td>
                                                <td className="px-4 py-2.5 text-muted-foreground">{req.assignedTo}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>

                    {/* ==========================================================
                        SECTION 3 -- Data Breach Management
                    ========================================================== */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-red-100 dark:bg-red-900/40">
                                        <ShieldAlert className="h-5 w-5 text-red-600" />
                                    </div>
                                    <div>
                                        <CardTitle>Data Breach Management</CardTitle>
                                        <CardDescription>
                                            Record and manage data breaches in accordance with the Privacy Act 2020 mandatory notification requirements
                                        </CardDescription>
                                    </div>
                                </div>
                                <Button variant="destructive" onClick={() => setShowBreachDialog(true)}>
                                    <ShieldAlert className="mr-2 h-4 w-4" />
                                    Report Breach
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Breach log table */}
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="px-4 py-2.5 text-left font-medium">ID</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Date</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Type</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Severity</th>
                                            <th className="px-4 py-2.5 text-right font-medium">Individuals</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Commissioner</th>
                                            <th className="px-4 py-2.5 text-left font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {placeholderBreaches.map((breach) => (
                                            <tr key={breach.id} className="border-b last:border-0">
                                                <td className="whitespace-nowrap px-4 py-2.5 font-mono text-xs">{breach.id}</td>
                                                <td className="whitespace-nowrap px-4 py-2.5">{breach.date}</td>
                                                <td className="px-4 py-2.5">{breach.type}</td>
                                                <td className="px-4 py-2.5">{severityBadge(breach.severity)}</td>
                                                <td className="px-4 py-2.5 text-right tabular-nums">{breach.individualsAffected}</td>
                                                <td className="px-4 py-2.5">
                                                    {breach.commissionerNotified ? (
                                                        <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-400">
                                                            <CheckCircle className="mr-1 h-3 w-3" /> Notified
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                                            Pending
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 capitalize">{breach.status.replace('_', ' ')}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* 72 hour warning */}
                            <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-400">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    Under the Privacy Act 2020, serious breaches must be notified to the Privacy Commissioner as soon as practicable (within 72 hours of discovery).
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                </TabsContent>

                <TabsContent value="retention" className="space-y-8">

                    {/* ==========================================================
                        SECTION 4 -- Data Retention
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

                </TabsContent>

                <TabsContent value="compliance" className="space-y-8">

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

                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium">Require Privacy Officer approval for health data exports</p>
                                        <p className="text-xs text-muted-foreground">
                                            Exports containing medical or health data will require sign-off before downloading
                                        </p>
                                    </div>
                                    <Switch checked={requirePrivacyOfficerApproval} onCheckedChange={setRequirePrivacyOfficerApproval} />
                                </div>

                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium">Log all medical record access</p>
                                        <p className="text-xs text-muted-foreground">
                                            Creates an audit entry every time a medical profile or medication record is viewed
                                        </p>
                                    </div>
                                    <Switch checked={logMedicalAccess} onCheckedChange={setLogMedicalAccess} />
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
                            {/* Health custodian and privacy officer inputs */}
                            <div className="space-y-4 pt-1">
                                <div>
                                    <Label htmlFor="health-custodian">Health Information Custodian</Label>
                                    <p className="mb-1 text-xs text-muted-foreground">
                                        The person responsible for health data within your organisation
                                    </p>
                                    <Input
                                        id="health-custodian"
                                        value={healthCustodian}
                                        onChange={(e) => setHealthCustodian(e.target.value)}
                                        placeholder="Full name"
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="privacy-officer">Privacy Officer</Label>
                                    <p className="mb-1 text-xs text-muted-foreground">
                                        May differ from the Data Protection Officer listed in Privacy & Consent
                                    </p>
                                    <Input
                                        id="privacy-officer"
                                        value={privacyOfficer}
                                        onChange={(e) => setPrivacyOfficer(e.target.value)}
                                        placeholder="Full name"
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            {/* Breach notification info banner */}
                            <div className="flex items-start gap-2 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-400">
                                <Info className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    NZ organisations must notify the Privacy Commissioner of serious breaches within 72 hours. Use the Data Breach Management section above to track incidents.
                                </span>
                            </div>

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
                        SECTION 7 -- Third-Party Data Processors
                    ========================================================== */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-900/40">
                                        <Building2 className="h-5 w-5 text-violet-600" />
                                    </div>
                                    <div>
                                        <CardTitle>Third-Party Data Processors</CardTitle>
                                        <CardDescription>
                                            Register and monitor third parties who process personal data on your behalf
                                        </CardDescription>
                                    </div>
                                </div>
                                <Button
                                    className="bg-violet-600 hover:bg-violet-700"
                                    onClick={() => setShowProcessorDialog(true)}
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add Processor
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                {placeholderProcessors.map((proc) => (
                                    <div
                                        key={proc.id}
                                        className={`rounded-lg border p-4 ${proc.overdue ? 'border-red-200 dark:border-red-900' : ''}`}
                                    >
                                        <div className="mb-3 flex items-start justify-between">
                                            <div>
                                                <h4 className="font-medium">{proc.company}</h4>
                                                <p className="text-xs text-muted-foreground">{proc.contact}</p>
                                            </div>
                                            <span className="text-lg">{proc.countryFlag}</span>
                                        </div>

                                        <div className="mb-3 flex flex-wrap gap-1.5">
                                            {proc.purposes.map((p) => (
                                                <Badge key={p} variant="secondary" className="text-xs">
                                                    {p}
                                                </Badge>
                                            ))}
                                        </div>

                                        <div className="mb-3 flex flex-wrap gap-1.5">
                                            {proc.dataCategories.map((cat) => (
                                                <Badge key={cat} variant="outline" className="text-xs">
                                                    {cat}
                                                </Badge>
                                            ))}
                                        </div>

                                        <div className="flex items-center justify-between">
                                            <div className="space-y-1">
                                                {agreementBadge(proc.agreementStatus)}
                                                <p className={`text-xs ${proc.overdue ? 'font-semibold text-red-600' : 'text-muted-foreground'}`}>
                                                    Review: {proc.reviewDate}
                                                    {proc.overdue && ' (overdue)'}
                                                </p>
                                            </div>
                                            <div className="flex gap-2">
                                                <Button variant="ghost" size="sm" className="h-auto px-2 py-1 text-xs text-violet-600">
                                                    Edit
                                                </Button>
                                                <Button variant="ghost" size="sm" className="h-auto px-2 py-1 text-xs text-red-600">
                                                    Remove
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                </TabsContent>

                </TabsRoot>

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

                {/* DSAR New Request dialog */}
                <Dialog open={showDsarDialog} onOpenChange={setShowDsarDialog}>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>New Data Subject Access Request</DialogTitle>
                            <DialogDescription>
                                Record a new request from an individual to access, correct, or delete their personal data.
                                The 20 working day deadline will be calculated automatically.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div>
                                <Label>Request Type</Label>
                                <Select value={dsarType} onValueChange={setDsarType}>
                                    <SelectTrigger className="mt-1">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="access">Access</SelectItem>
                                        <SelectItem value="correction">Correction</SelectItem>
                                        <SelectItem value="erasure">Erasure</SelectItem>
                                        <SelectItem value="restriction">Restriction</SelectItem>
                                        <SelectItem value="portability">Portability</SelectItem>
                                        <SelectItem value="objection">Objection</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="dsar-name">Requester Name</Label>
                                    <Input
                                        id="dsar-name"
                                        value={dsarRequesterName}
                                        onChange={(e) => setDsarRequesterName(e.target.value)}
                                        placeholder="Full name"
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="dsar-email">Email</Label>
                                    <Input
                                        id="dsar-email"
                                        type="email"
                                        value={dsarRequesterEmail}
                                        onChange={(e) => setDsarRequesterEmail(e.target.value)}
                                        placeholder="email@example.co.nz"
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="dsar-phone">Phone</Label>
                                    <Input
                                        id="dsar-phone"
                                        value={dsarRequesterPhone}
                                        onChange={(e) => setDsarRequesterPhone(e.target.value)}
                                        placeholder="021 XXX XXXX"
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label>Relationship</Label>
                                    <Select value={dsarRelationship} onValueChange={setDsarRelationship}>
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="self">Self</SelectItem>
                                            <SelectItem value="whanau">Wh&#257;nau / Family</SelectItem>
                                            <SelectItem value="legal_rep">Legal Representative</SelectItem>
                                            <SelectItem value="advocate">Advocate</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="dsar-details">Details</Label>
                                <Textarea
                                    id="dsar-details"
                                    value={dsarDetails}
                                    onChange={(e) => setDsarDetails(e.target.value)}
                                    placeholder="Describe the request..."
                                    rows={3}
                                    className="mt-1"
                                />
                            </div>

                            <div className="flex items-center justify-between gap-4">
                                <div>
                                    <p className="text-sm font-medium">Identity verified</p>
                                    <p className="text-xs text-muted-foreground">
                                        Confirm the requester's identity has been verified
                                    </p>
                                </div>
                                <Switch checked={dsarIdentityVerified} onCheckedChange={setDsarIdentityVerified} />
                            </div>

                            <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                                <p className="text-muted-foreground">
                                    <strong className="text-foreground">Due date:</strong> {calcDueDate()} (20 working days from today)
                                </p>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setShowDsarDialog(false)}>
                                Cancel
                            </Button>
                            <Button
                                className="bg-violet-600 hover:bg-violet-700"
                                onClick={() => {
                                    setShowDsarDialog(false);
                                    setDsarRequesterName('');
                                    setDsarRequesterEmail('');
                                    setDsarRequesterPhone('');
                                    setDsarDetails('');
                                    setDsarIdentityVerified(false);
                                }}
                            >
                                Create Request
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Data Breach Report dialog */}
                <Dialog open={showBreachDialog} onOpenChange={setShowBreachDialog}>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle className="text-red-600">Report Data Breach</DialogTitle>
                            <DialogDescription>
                                Record a data breach incident. Serious breaches must be reported to the Privacy Commissioner within 72 hours.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Breach Type</Label>
                                    <Select value={breachType} onValueChange={setBreachType}>
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="unauthorised_access">Unauthorised access</SelectItem>
                                            <SelectItem value="data_loss">Data loss</SelectItem>
                                            <SelectItem value="system_compromise">System compromise</SelectItem>
                                            <SelectItem value="employee_error">Employee error</SelectItem>
                                            <SelectItem value="third_party">Third-party breach</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Severity</Label>
                                    <Select value={breachSeverity} onValueChange={setBreachSeverity}>
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="low">Low</SelectItem>
                                            <SelectItem value="medium">Medium</SelectItem>
                                            <SelectItem value="high">High</SelectItem>
                                            <SelectItem value="critical">Critical</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="breach-desc">Description</Label>
                                <Textarea
                                    id="breach-desc"
                                    value={breachDescription}
                                    onChange={(e) => setBreachDescription(e.target.value)}
                                    placeholder="Describe what happened..."
                                    rows={3}
                                    className="mt-1"
                                />
                            </div>

                            <div>
                                <Label className="mb-2 block">Data types affected</Label>
                                <div className="flex flex-wrap gap-3">
                                    {['Personal info', 'Health data', 'Financial', 'Login credentials', 'NHI numbers'].map((type) => (
                                        <label key={type} className="flex cursor-pointer items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={breachDataTypes.includes(type)}
                                                onCheckedChange={() => toggleBreachDataType(type)}
                                            />
                                            {type}
                                        </label>
                                    ))}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="breach-individuals">Number of individuals affected</Label>
                                    <Input
                                        id="breach-individuals"
                                        type="number"
                                        value={breachIndividuals}
                                        onChange={(e) => setBreachIndividuals(e.target.value)}
                                        placeholder="0"
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="breach-discovery">Discovery date</Label>
                                    <Input
                                        id="breach-discovery"
                                        type="date"
                                        value={breachDiscoveryDate}
                                        onChange={(e) => setBreachDiscoveryDate(e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            {breachDiscoveryDate && (
                                <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-400">
                                    <strong>Notification deadline:</strong> {calc72HourDeadline(breachDiscoveryDate)} (72 hours from discovery)
                                </div>
                            )}

                            <div className="space-y-3">
                                <div className="flex items-center justify-between gap-4">
                                    <p className="text-sm font-medium">Notified Privacy Commissioner</p>
                                    <Switch checked={breachCommissionerNotified} onCheckedChange={setBreachCommissionerNotified} />
                                </div>
                                <div className="flex items-center justify-between gap-4">
                                    <p className="text-sm font-medium">Notified affected individuals</p>
                                    <Switch checked={breachIndividualsNotified} onCheckedChange={setBreachIndividualsNotified} />
                                </div>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setShowBreachDialog(false)}>
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={() => {
                                    setShowBreachDialog(false);
                                    setBreachDescription('');
                                    setBreachDataTypes([]);
                                    setBreachIndividuals('');
                                    setBreachDiscoveryDate('');
                                    setBreachCommissionerNotified(false);
                                    setBreachIndividualsNotified(false);
                                }}
                            >
                                Report Breach
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Add Processor dialog */}
                <Dialog open={showProcessorDialog} onOpenChange={setShowProcessorDialog}>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Add Third-Party Data Processor</DialogTitle>
                            <DialogDescription>
                                Register a third party that processes personal data on behalf of your organisation.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="proc-company">Company Name</Label>
                                    <Input
                                        id="proc-company"
                                        value={processorCompany}
                                        onChange={(e) => setProcessorCompany(e.target.value)}
                                        placeholder="Company name"
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="proc-contact">Contact Name</Label>
                                    <Input
                                        id="proc-contact"
                                        value={processorContact}
                                        onChange={(e) => setProcessorContact(e.target.value)}
                                        placeholder="Contact person"
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="proc-email">Email</Label>
                                <Input
                                    id="proc-email"
                                    type="email"
                                    value={processorEmail}
                                    onChange={(e) => setProcessorEmail(e.target.value)}
                                    placeholder="contact@company.com"
                                    className="mt-1"
                                />
                            </div>

                            <div>
                                <Label>Processing Purpose</Label>
                                <Select value={processorPurpose} onValueChange={setProcessorPurpose}>
                                    <SelectTrigger className="mt-1">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="sso">SSO / Authentication</SelectItem>
                                        <SelectItem value="email">Email delivery</SelectItem>
                                        <SelectItem value="sms">SMS delivery</SelectItem>
                                        <SelectItem value="cloud_hosting">Cloud hosting</SelectItem>
                                        <SelectItem value="backup">Backup</SelectItem>
                                        <SelectItem value="analytics">Analytics</SelectItem>
                                        <SelectItem value="payroll">Payroll integration</SelectItem>
                                        <SelectItem value="calendar">Calendar sync</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="mb-2 block">Data categories shared</Label>
                                <div className="flex flex-wrap gap-3">
                                    {['Personal info', 'Health data', 'Financial', 'Employment'].map((cat) => (
                                        <label key={cat} className="flex cursor-pointer items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={processorDataCategories.includes(cat)}
                                                onCheckedChange={() => toggleProcessorDataCategory(cat)}
                                            />
                                            {cat}
                                        </label>
                                    ))}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Agreement Type</Label>
                                    <Select value={processorAgreement} onValueChange={setProcessorAgreement}>
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="dpa_signed">DPA signed</SelectItem>
                                            <SelectItem value="standard_terms">Standard terms</SelectItem>
                                            <SelectItem value="negotiating">Under negotiation</SelectItem>
                                            <SelectItem value="no_agreement">No agreement</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Country</Label>
                                    <Select value={processorCountry} onValueChange={setProcessorCountry}>
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="nz">New Zealand</SelectItem>
                                            <SelectItem value="au">Australia</SelectItem>
                                            <SelectItem value="us">USA</SelectItem>
                                            <SelectItem value="uk">UK</SelectItem>
                                            <SelectItem value="other">Other</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="proc-review">Review Date</Label>
                                <Input
                                    id="proc-review"
                                    type="date"
                                    value={processorReviewDate}
                                    onChange={(e) => setProcessorReviewDate(e.target.value)}
                                    className="mt-1"
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setShowProcessorDialog(false)}>
                                Cancel
                            </Button>
                            <Button
                                className="bg-violet-600 hover:bg-violet-700"
                                onClick={() => {
                                    setShowProcessorDialog(false);
                                    setProcessorCompany('');
                                    setProcessorContact('');
                                    setProcessorEmail('');
                                    setProcessorDataCategories([]);
                                    setProcessorReviewDate('');
                                }}
                            >
                                Add Processor
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
