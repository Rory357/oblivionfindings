import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
    Eye,
    Mail,
    MessageSquare,
    Pencil,
    RotateCcw,
    Search,
    Send,
    Settings2,
} from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface EmailTemplate {
    id: string;
    name: string;
    description: string;
    subject: string;
    body: string;
    category: 'operations' | 'hr' | 'incidents' | 'system';
    mergeFields: string[];
}

interface SmsTemplate {
    id: string;
    name: string;
    description: string;
    body: string;
    category: 'operations' | 'hr' | 'incidents' | 'system';
    mergeFields: string[];
}

interface TemplateSettings {
    emailSignature: string;
    logoEnabled: boolean;
    headerColour: string;
    unsubscribeLink: boolean;
    replyToAddress: string;
}

// ---------------------------------------------------------------------------
// Default data
// ---------------------------------------------------------------------------

const defaultEmailTemplates: EmailTemplate[] = [
    { id: 'welcome', name: 'Welcome Email', description: 'Sent to new users upon registration', subject: 'Welcome to {organisation}', body: 'Hi {name},\n\nWelcome to {organisation}. Your account has been created successfully.', category: 'system', mergeFields: ['{name}', '{email}', '{organisation}', '{login_url}'] },
    { id: 'password-reset', name: 'Password Reset', description: 'Password reset request notification', subject: 'Reset your password', body: 'Hi {name},\n\nClick the link below to reset your password.\n\n{reset_url}', category: 'system', mergeFields: ['{name}', '{reset_url}', '{expiry}'] },
    { id: 'shift-reminder', name: 'Shift Reminder', description: 'Upcoming shift reminder sent to staff', subject: 'Shift Reminder: {date}', body: 'Hi {name},\n\nThis is a reminder that you have a shift on {date} from {start_time} to {end_time} with {client}.', category: 'operations', mergeFields: ['{name}', '{date}', '{start_time}', '{end_time}', '{client}', '{location}'] },
    { id: 'incident-alert', name: 'Incident Alert', description: 'Notification when an incident is reported', subject: 'Incident Reported: {incident_type}', body: 'An incident has been reported.\n\nType: {incident_type}\nClient: {client}\nDate: {date}\nReported by: {reporter}', category: 'incidents', mergeFields: ['{incident_type}', '{client}', '{date}', '{reporter}', '{severity}'] },
    { id: 'leave-approved', name: 'Leave Approved', description: 'Notification when leave is approved', subject: 'Leave Approved: {dates}', body: 'Hi {name},\n\nYour leave request for {dates} has been approved by {approver}.', category: 'hr', mergeFields: ['{name}', '{dates}', '{leave_type}', '{approver}'] },
    { id: 'leave-declined', name: 'Leave Declined', description: 'Notification when leave is declined', subject: 'Leave Request Declined', body: 'Hi {name},\n\nYour leave request for {dates} has been declined.\n\nReason: {reason}', category: 'hr', mergeFields: ['{name}', '{dates}', '{leave_type}', '{reason}'] },
    { id: 'timesheet-reminder', name: 'Timesheet Reminder', description: 'Reminder to submit timesheets', subject: 'Timesheet Due: {period}', body: 'Hi {name},\n\nPlease submit your timesheet for {period} by {due_date}.', category: 'hr', mergeFields: ['{name}', '{period}', '{due_date}'] },
    { id: 'document-expiry', name: 'Document Expiry', description: 'Alert when a document is about to expire', subject: 'Document Expiring: {document_name}', body: 'Hi {name},\n\nThe following document is expiring on {expiry_date}:\n\n{document_name}', category: 'operations', mergeFields: ['{name}', '{document_name}', '{expiry_date}', '{days_remaining}'] },
    { id: 'consent-reminder', name: 'Consent Reminder', description: 'Reminder to obtain or renew consent', subject: 'Consent Required: {client}', body: 'Hi {name},\n\nConsent is required for {client} regarding {consent_type}.\n\nPlease action this by {due_date}.', category: 'operations', mergeFields: ['{name}', '{client}', '{consent_type}', '{due_date}'] },
    { id: 'invoice-sent', name: 'Invoice Sent', description: 'Notification when an invoice is sent', subject: 'Invoice #{invoice_number}', body: 'Hi {name},\n\nInvoice #{invoice_number} for {amount} has been sent to {recipient}.', category: 'operations', mergeFields: ['{name}', '{invoice_number}', '{amount}', '{recipient}', '{due_date}'] },
];

const defaultSmsTemplates: SmsTemplate[] = [
    { id: 'shift-reminder-sms', name: 'Shift Reminder SMS', description: 'SMS reminder for upcoming shifts', body: 'Reminder: You have a shift on {date} at {start_time} with {client}. Reply CONFIRM to acknowledge.', category: 'operations', mergeFields: ['{name}', '{date}', '{start_time}', '{client}'] },
    { id: 'emergency-alert-sms', name: 'Emergency Alert SMS', description: 'Urgent alert for emergencies', body: 'URGENT: {alert_type} at {location}. Please respond immediately. Contact: {contact_number}', category: 'incidents', mergeFields: ['{alert_type}', '{location}', '{contact_number}'] },
    { id: 'availability-request-sms', name: 'Availability Request SMS', description: 'Request staff availability for shifts', body: 'Hi {name}, can you cover a shift on {date} {start_time}-{end_time}? Reply YES or NO.', category: 'operations', mergeFields: ['{name}', '{date}', '{start_time}', '{end_time}'] },
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const categoryLabels: Record<string, string> = {
    all: 'All',
    operations: 'Operations',
    hr: 'HR',
    incidents: 'Incidents',
    system: 'System',
};

const categoryColours: Record<string, string> = {
    operations: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    hr: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    incidents: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    system: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
};

const iconBgColours: Record<string, string> = {
    operations: 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400',
    hr: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400',
    incidents: 'bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400',
    system: 'bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-400',
};

function smsColourClass(len: number) {
    if (len <= 160) return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300';
    if (len <= 320) return 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300';
    return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
}

function smsSegments(len: number) {
    if (len === 0) return 0;
    return Math.ceil(len / 160);
}

/** Render a template body with sample data for preview. */
function renderPreview(text: string) {
    const sampleData: Record<string, string> = {
        '{name}': 'Sarah Thompson',
        '{email}': 'sarah@example.co.nz',
        '{organisation}': 'Kiwi Care Ltd',
        '{login_url}': 'https://app.kiwicare.co.nz/login',
        '{reset_url}': 'https://app.kiwicare.co.nz/reset/abc123',
        '{expiry}': '24 hours',
        '{date}': '28 Mar 2026',
        '{start_time}': '07:00',
        '{end_time}': '15:00',
        '{client}': 'James Wilson',
        '{location}': '42 Queen Street, Auckland',
        '{incident_type}': 'Medication Error',
        '{reporter}': 'Sarah Thompson',
        '{severity}': 'Medium',
        '{dates}': '1 Apr – 5 Apr 2026',
        '{leave_type}': 'Annual Leave',
        '{approver}': 'Mike Chen',
        '{reason}': 'Insufficient staffing coverage',
        '{period}': 'Week ending 28 Mar 2026',
        '{due_date}': '30 Mar 2026',
        '{document_name}': 'First Aid Certificate',
        '{expiry_date}': '15 Apr 2026',
        '{days_remaining}': '18',
        '{consent_type}': 'Medication Administration',
        '{invoice_number}': 'INV-2026-0042',
        '{amount}': '$2,450.00',
        '{recipient}': 'NASC Waikato',
        '{alert_type}': 'Fire Evacuation',
        '{contact_number}': '021 555 0123',
    };
    let result = text;
    for (const [key, val] of Object.entries(sampleData)) {
        result = result.replaceAll(key, val);
    }
    return result;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function Templates() {
    const [emailTemplates, setEmailTemplates] = useState(defaultEmailTemplates);
    const [smsTemplates, setSmsTemplates] = useState(defaultSmsTemplates);
    const [editingEmail, setEditingEmail] = useState<EmailTemplate | null>(null);
    const [editingSms, setEditingSms] = useState<SmsTemplate | null>(null);
    const [editSubject, setEditSubject] = useState('');
    const [editBody, setEditBody] = useState('');
    const [testSent, setTestSent] = useState(false);

    // Filters
    const [emailSearch, setEmailSearch] = useState('');
    const [emailCategory, setEmailCategory] = useState('all');
    const [smsSearch, setSmsSearch] = useState('');

    // Preview
    const [previewingEmail, setPreviewingEmail] = useState<EmailTemplate | null>(null);
    const [previewingSms, setPreviewingSms] = useState<SmsTemplate | null>(null);

    // Settings
    const [settings, setSettings] = useState<TemplateSettings>({
        emailSignature: 'Kind regards,\nThe Kiwi Care Team\ninfo@kiwicare.co.nz | 0800 555 123',
        logoEnabled: true,
        headerColour: '#7c3aed',
        unsubscribeLink: true,
        replyToAddress: 'noreply@kiwicare.co.nz',
    });

    // Refs for cursor-position merge field insertion
    const subjectRef = useRef<HTMLInputElement>(null);
    const bodyRef = useRef<HTMLTextAreaElement>(null);
    const smsBodyRef = useRef<HTMLTextAreaElement>(null);

    // --------------------------------------------------
    // Email handlers
    // --------------------------------------------------

    function openEmailEdit(template: EmailTemplate) {
        setEditingEmail(template);
        setEditSubject(template.subject);
        setEditBody(template.body);
        setTestSent(false);
    }

    function saveEmail() {
        if (!editingEmail) return;
        setEmailTemplates((prev) =>
            prev.map((t) => (t.id === editingEmail.id ? { ...t, subject: editSubject, body: editBody } : t)),
        );
        setEditingEmail(null);
    }

    function resetEmail() {
        if (!editingEmail) return;
        const original = defaultEmailTemplates.find((t) => t.id === editingEmail.id);
        if (original) {
            setEditSubject(original.subject);
            setEditBody(original.body);
        }
    }

    // --------------------------------------------------
    // SMS handlers
    // --------------------------------------------------

    function openSmsEdit(template: SmsTemplate) {
        setEditingSms(template);
        setEditBody(template.body);
        setTestSent(false);
    }

    function saveSms() {
        if (!editingSms) return;
        setSmsTemplates((prev) =>
            prev.map((t) => (t.id === editingSms.id ? { ...t, body: editBody } : t)),
        );
        setEditingSms(null);
    }

    function resetSms() {
        if (!editingSms) return;
        const original = defaultSmsTemplates.find((t) => t.id === editingSms.id);
        if (original) {
            setEditBody(original.body);
        }
    }

    // --------------------------------------------------
    // Merge field insertion
    // --------------------------------------------------

    const insertAtCursor = useCallback(
        (field: string, target: 'subject' | 'body' | 'sms') => {
            if (target === 'subject') {
                const el = subjectRef.current;
                if (!el) return;
                const start = el.selectionStart ?? editSubject.length;
                const end = el.selectionEnd ?? start;
                const next = editSubject.slice(0, start) + field + editSubject.slice(end);
                setEditSubject(next);
                requestAnimationFrame(() => {
                    el.focus();
                    el.setSelectionRange(start + field.length, start + field.length);
                });
            } else if (target === 'body') {
                const el = bodyRef.current;
                if (!el) return;
                const start = el.selectionStart ?? editBody.length;
                const end = el.selectionEnd ?? start;
                const next = editBody.slice(0, start) + field + editBody.slice(end);
                setEditBody(next);
                requestAnimationFrame(() => {
                    el.focus();
                    el.setSelectionRange(start + field.length, start + field.length);
                });
            } else {
                const el = smsBodyRef.current;
                if (!el) return;
                const start = el.selectionStart ?? editBody.length;
                const end = el.selectionEnd ?? start;
                const next = editBody.slice(0, start) + field + editBody.slice(end);
                setEditBody(next);
                requestAnimationFrame(() => {
                    el.focus();
                    el.setSelectionRange(start + field.length, start + field.length);
                });
            }
        },
        [editSubject, editBody],
    );

    // --------------------------------------------------
    // Test send
    // --------------------------------------------------

    function handleSendTest() {
        setTestSent(true);
        setTimeout(() => setTestSent(false), 3000);
    }

    // --------------------------------------------------
    // Filtered lists
    // --------------------------------------------------

    const filteredEmails = emailTemplates.filter((t) => {
        const matchesSearch =
            !emailSearch ||
            t.name.toLowerCase().includes(emailSearch.toLowerCase()) ||
            t.description.toLowerCase().includes(emailSearch.toLowerCase());
        const matchesCategory = emailCategory === 'all' || t.category === emailCategory;
        return matchesSearch && matchesCategory;
    });

    const filteredSms = smsTemplates.filter((t) => {
        return (
            !smsSearch ||
            t.name.toLowerCase().includes(smsSearch.toLowerCase()) ||
            t.description.toLowerCase().includes(smsSearch.toLowerCase())
        );
    });

    // --------------------------------------------------
    // Stats
    // --------------------------------------------------

    const totalTemplates = emailTemplates.length + smsTemplates.length;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Settings', href: '/settings' },
        { title: 'Templates' },
    ];

    // --------------------------------------------------
    // Render
    // --------------------------------------------------

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Email & SMS Templates" />
            <SettingsLayout>
                <div className="space-y-8">
                    {/* Header */}
                    <div>
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-900/40">
                                <Mail className="h-5 w-5 text-violet-600 dark:text-violet-400" />
                            </div>
                            <div>
                                <h1 className="text-2xl font-bold tracking-tight">Email & SMS Templates</h1>
                                <p className="text-sm text-muted-foreground">
                                    Customise notification templates sent across all modules. Use merge fields to personalise content.
                                </p>
                            </div>
                        </div>

                        {/* Stats */}
                        <div className="mt-5 grid grid-cols-3 gap-4">
                            <div className="rounded-lg border bg-indigo-50 p-4 dark:bg-indigo-950/30">
                                <p className="text-xs font-medium text-indigo-600 dark:text-indigo-400">Total Templates</p>
                                <p className="mt-1 text-2xl font-bold text-indigo-700 dark:text-indigo-300">{totalTemplates}</p>
                            </div>
                            <div className="rounded-lg border bg-blue-50 p-4 dark:bg-blue-950/30">
                                <p className="text-xs font-medium text-blue-600 dark:text-blue-400">Email Templates</p>
                                <p className="mt-1 text-2xl font-bold text-blue-700 dark:text-blue-300">{emailTemplates.length}</p>
                            </div>
                            <div className="rounded-lg border bg-emerald-50 p-4 dark:bg-emerald-950/30">
                                <p className="text-xs font-medium text-emerald-600 dark:text-emerald-400">SMS Templates</p>
                                <p className="mt-1 text-2xl font-bold text-emerald-700 dark:text-emerald-300">{smsTemplates.length}</p>
                            </div>
                        </div>
                    </div>

                    {/* Tabs */}
                    <TabsRoot defaultValue="email">
                        <TabsList className="grid w-full grid-cols-3">
                            <TabsTrigger value="email" className="gap-2">
                                <Mail className="h-4 w-4" />
                                Email Templates
                            </TabsTrigger>
                            <TabsTrigger value="sms" className="gap-2">
                                <MessageSquare className="h-4 w-4" />
                                SMS Templates
                            </TabsTrigger>
                            <TabsTrigger value="settings" className="gap-2">
                                <Settings2 className="h-4 w-4" />
                                Template Settings
                            </TabsTrigger>
                        </TabsList>

                        {/* ============================================================ */}
                        {/* TAB 1: Email Templates                                       */}
                        {/* ============================================================ */}
                        <TabsContent value="email" className="mt-6">
                            <div className="space-y-4">
                                {/* Filters */}
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                    <div className="relative flex-1">
                                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            placeholder="Search email templates..."
                                            value={emailSearch}
                                            onChange={(e) => setEmailSearch(e.target.value)}
                                            className="pl-9"
                                        />
                                    </div>
                                    <Select value={emailCategory} onValueChange={setEmailCategory}>
                                        <SelectTrigger className="w-full sm:w-44">
                                            <SelectValue placeholder="Category" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(categoryLabels).map(([value, label]) => (
                                                <SelectItem key={value} value={value}>
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Template list */}
                                <div className="space-y-3">
                                    {filteredEmails.length === 0 && (
                                        <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                                            No email templates match your search.
                                        </div>
                                    )}
                                    {filteredEmails.map((template) => (
                                        <Card key={template.id} className="transition-colors hover:border-violet-200 dark:hover:border-violet-800">
                                            <CardContent className="p-5">
                                                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                                    {/* Left: icon + name + description */}
                                                    <div className="flex items-start gap-3 lg:w-1/3">
                                                        <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${iconBgColours[template.category]}`}>
                                                            <Mail className="h-4 w-4" />
                                                        </div>
                                                        <div className="min-w-0">
                                                            <div className="flex items-center gap-2">
                                                                <p className="text-sm font-semibold">{template.name}</p>
                                                                <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${categoryColours[template.category]}`}>
                                                                    {categoryLabels[template.category]}
                                                                </span>
                                                            </div>
                                                            <p className="mt-0.5 text-xs text-muted-foreground">{template.description}</p>
                                                        </div>
                                                    </div>

                                                    {/* Centre: subject preview */}
                                                    <div className="min-w-0 flex-1 lg:px-4">
                                                        <p className="mb-1.5 text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Subject</p>
                                                        <div className="rounded-md bg-muted/50 px-3 py-1.5">
                                                            <p className="truncate font-mono text-xs text-foreground/80">{template.subject}</p>
                                                        </div>
                                                        {/* Merge fields */}
                                                        <div className="mt-2 flex flex-wrap gap-1">
                                                            {template.mergeFields.map((field) => (
                                                                <span
                                                                    key={field}
                                                                    className="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 font-mono text-[10px] text-violet-700 dark:bg-violet-900/40 dark:text-violet-300"
                                                                >
                                                                    {field}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </div>

                                                    {/* Right: actions */}
                                                    <div className="flex shrink-0 items-center gap-2">
                                                        <Button variant="outline" size="sm" onClick={() => openEmailEdit(template)}>
                                                            <Pencil className="mr-1.5 h-3 w-3" />
                                                            Edit
                                                        </Button>
                                                        <Button variant="outline" size="sm" onClick={() => setPreviewingEmail(template)}>
                                                            <Eye className="mr-1.5 h-3 w-3" />
                                                            Preview
                                                        </Button>
                                                        <Button variant="outline" size="sm" onClick={handleSendTest}>
                                                            <Send className="mr-1.5 h-3 w-3" />
                                                            Send Test
                                                        </Button>
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    ))}
                                </div>
                            </div>
                        </TabsContent>

                        {/* ============================================================ */}
                        {/* TAB 2: SMS Templates                                         */}
                        {/* ============================================================ */}
                        <TabsContent value="sms" className="mt-6">
                            <div className="space-y-4">
                                {/* Search */}
                                <div className="relative">
                                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        placeholder="Search SMS templates..."
                                        value={smsSearch}
                                        onChange={(e) => setSmsSearch(e.target.value)}
                                        className="pl-9"
                                    />
                                </div>

                                {/* Template list */}
                                <div className="space-y-3">
                                    {filteredSms.length === 0 && (
                                        <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                                            No SMS templates match your search.
                                        </div>
                                    )}
                                    {filteredSms.map((template) => (
                                        <Card key={template.id} className="transition-colors hover:border-violet-200 dark:hover:border-violet-800">
                                            <CardContent className="p-5">
                                                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                                    {/* Left: icon + info */}
                                                    <div className="flex items-start gap-3 lg:w-1/4">
                                                        <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${iconBgColours[template.category]}`}>
                                                            <MessageSquare className="h-4 w-4" />
                                                        </div>
                                                        <div className="min-w-0">
                                                            <p className="text-sm font-semibold">{template.name}</p>
                                                            <p className="mt-0.5 text-xs text-muted-foreground">{template.description}</p>
                                                        </div>
                                                    </div>

                                                    {/* Centre: body preview + char count */}
                                                    <div className="min-w-0 flex-1 lg:px-4">
                                                        <div className="rounded-md bg-muted/50 px-3 py-2">
                                                            <p className="font-mono text-xs text-foreground/80 line-clamp-2">{template.body}</p>
                                                        </div>
                                                        <div className="mt-2 flex items-center gap-2">
                                                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${smsColourClass(template.body.length)}`}>
                                                                {template.body.length} chars
                                                            </span>
                                                            <span className="text-[10px] text-muted-foreground">
                                                                {smsSegments(template.body.length)} segment{smsSegments(template.body.length) !== 1 ? 's' : ''}
                                                            </span>
                                                        </div>
                                                    </div>

                                                    {/* Right: actions */}
                                                    <div className="flex shrink-0 items-center gap-2">
                                                        <Button variant="outline" size="sm" onClick={() => openSmsEdit(template)}>
                                                            <Pencil className="mr-1.5 h-3 w-3" />
                                                            Edit
                                                        </Button>
                                                        <Button variant="outline" size="sm" onClick={() => setPreviewingSms(template)}>
                                                            <Eye className="mr-1.5 h-3 w-3" />
                                                            Preview
                                                        </Button>
                                                        <Button variant="outline" size="sm" onClick={handleSendTest}>
                                                            <Send className="mr-1.5 h-3 w-3" />
                                                            Send Test
                                                        </Button>
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    ))}
                                </div>
                            </div>
                        </TabsContent>

                        {/* ============================================================ */}
                        {/* TAB 3: Template Settings                                     */}
                        {/* ============================================================ */}
                        <TabsContent value="settings" className="mt-6">
                            <div className="space-y-6">
                                {/* Email Signature */}
                                <Card>
                                    <CardContent className="p-6">
                                        <Label className="text-sm font-semibold">Organisation Email Signature</Label>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            Default footer appended to all outgoing emails.
                                        </p>
                                        <Textarea
                                            value={settings.emailSignature}
                                            onChange={(e) => setSettings({ ...settings, emailSignature: e.target.value })}
                                            rows={4}
                                            className="mt-3 font-mono text-sm"
                                        />
                                    </CardContent>
                                </Card>

                                {/* Logo toggle */}
                                <Card>
                                    <CardContent className="flex items-center justify-between p-6">
                                        <div>
                                            <Label className="text-sm font-semibold">Logo in Emails</Label>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                Display your organisation logo at the top of all emails.
                                            </p>
                                        </div>
                                        <Switch
                                            checked={settings.logoEnabled}
                                            onCheckedChange={(checked) => setSettings({ ...settings, logoEnabled: checked })}
                                        />
                                    </CardContent>
                                </Card>

                                {/* Email colour theme */}
                                <Card>
                                    <CardContent className="p-6">
                                        <Label className="text-sm font-semibold">Email Header Colour</Label>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            Colour used for the header bar in email templates.
                                        </p>
                                        <div className="mt-3 flex items-center gap-3">
                                            <input
                                                type="color"
                                                value={settings.headerColour}
                                                onChange={(e) => setSettings({ ...settings, headerColour: e.target.value })}
                                                className="h-10 w-14 cursor-pointer rounded-md border p-1"
                                            />
                                            <Input
                                                value={settings.headerColour}
                                                onChange={(e) => setSettings({ ...settings, headerColour: e.target.value })}
                                                className="w-32 font-mono text-sm"
                                            />
                                            <div
                                                className="h-10 flex-1 rounded-md border"
                                                style={{ backgroundColor: settings.headerColour }}
                                            />
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Unsubscribe link */}
                                <Card>
                                    <CardContent className="flex items-center justify-between p-6">
                                        <div>
                                            <Label className="text-sm font-semibold">Unsubscribe Link</Label>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                Include an unsubscribe link at the bottom of all emails.
                                            </p>
                                        </div>
                                        <Switch
                                            checked={settings.unsubscribeLink}
                                            onCheckedChange={(checked) => setSettings({ ...settings, unsubscribeLink: checked })}
                                        />
                                    </CardContent>
                                </Card>

                                {/* Reply-to address */}
                                <Card>
                                    <CardContent className="p-6">
                                        <Label className="text-sm font-semibold">Reply-to Address</Label>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            Default reply-to address for all outgoing emails.
                                        </p>
                                        <Input
                                            type="email"
                                            value={settings.replyToAddress}
                                            onChange={(e) => setSettings({ ...settings, replyToAddress: e.target.value })}
                                            className="mt-3"
                                            placeholder="noreply@example.co.nz"
                                        />
                                    </CardContent>
                                </Card>

                                {/* Save settings */}
                                <div className="flex justify-end">
                                    <Button className="bg-violet-600 hover:bg-violet-700">
                                        Save Settings
                                    </Button>
                                </div>
                            </div>
                        </TabsContent>
                    </TabsRoot>
                </div>

                {/* ================================================================== */}
                {/* Email Edit Dialog                                                   */}
                {/* ================================================================== */}
                <Dialog open={!!editingEmail} onOpenChange={(open) => !open && setEditingEmail(null)}>
                    <DialogContent className="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Edit Email Template: {editingEmail?.name}</DialogTitle>
                            <DialogDescription>{editingEmail?.description}</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
                            {/* Editor side */}
                            <div className="space-y-5">
                                {/* Subject */}
                                <div>
                                    <Label htmlFor="email-subject" className="text-sm font-medium">Subject</Label>
                                    <div className="mt-1.5 flex flex-wrap gap-1 mb-2">
                                        <span className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mr-1 self-center">Insert:</span>
                                        {editingEmail?.mergeFields.map((field) => (
                                            <button
                                                key={`subj-${field}`}
                                                type="button"
                                                onClick={() => insertAtCursor(field, 'subject')}
                                                className="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 font-mono text-[10px] text-violet-700 transition-colors hover:bg-violet-200 dark:bg-violet-900/40 dark:text-violet-300 dark:hover:bg-violet-900/60 cursor-pointer"
                                            >
                                                {field}
                                            </button>
                                        ))}
                                    </div>
                                    <Input
                                        id="email-subject"
                                        ref={subjectRef}
                                        value={editSubject}
                                        onChange={(e) => setEditSubject(e.target.value)}
                                    />
                                </div>

                                {/* Body */}
                                <div>
                                    <Label htmlFor="email-body" className="text-sm font-medium">Body</Label>
                                    <div className="mt-1.5 flex flex-wrap gap-1 mb-2">
                                        <span className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mr-1 self-center">Insert:</span>
                                        {editingEmail?.mergeFields.map((field) => (
                                            <button
                                                key={`body-${field}`}
                                                type="button"
                                                onClick={() => insertAtCursor(field, 'body')}
                                                className="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 font-mono text-[10px] text-violet-700 transition-colors hover:bg-violet-200 dark:bg-violet-900/40 dark:text-violet-300 dark:hover:bg-violet-900/60 cursor-pointer"
                                            >
                                                {field}
                                            </button>
                                        ))}
                                    </div>
                                    <Textarea
                                        id="email-body"
                                        ref={bodyRef}
                                        value={editBody}
                                        onChange={(e) => setEditBody(e.target.value)}
                                        rows={12}
                                        className="font-mono text-sm"
                                    />
                                    <p className="mt-1 text-xs text-muted-foreground text-right">
                                        {editBody.length} characters
                                    </p>
                                </div>
                            </div>

                            {/* Live Preview side */}
                            <div className="rounded-lg border bg-muted/30 p-4">
                                <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Live Preview</p>
                                {/* Mini email frame */}
                                <div className="rounded-md border bg-background overflow-hidden">
                                    <div className="h-2 w-full" style={{ backgroundColor: settings.headerColour }} />
                                    <div className="p-3 space-y-2">
                                        <p className="text-xs font-semibold text-foreground">{renderPreview(editSubject)}</p>
                                        <hr />
                                        <div className="whitespace-pre-wrap text-xs text-foreground/80 leading-relaxed">
                                            {renderPreview(editBody)}
                                        </div>
                                        {settings.emailSignature && (
                                            <>
                                                <hr />
                                                <div className="whitespace-pre-wrap text-[10px] text-muted-foreground leading-relaxed">
                                                    {settings.emailSignature}
                                                </div>
                                            </>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <DialogFooter className="mt-4 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <button
                                type="button"
                                onClick={resetEmail}
                                className="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors"
                            >
                                <RotateCcw className="h-3 w-3" />
                                Reset to Default
                            </button>
                            <div className="flex items-center gap-2">
                                <Button variant="outline" onClick={handleSendTest} disabled={testSent}>
                                    <Send className="mr-1.5 h-3.5 w-3.5" />
                                    {testSent ? 'Test Sent!' : 'Send Test Email'}
                                </Button>
                                <Button variant="outline" onClick={() => setEditingEmail(null)}>
                                    Cancel
                                </Button>
                                <Button onClick={saveEmail} className="bg-violet-600 hover:bg-violet-700">
                                    Save Template
                                </Button>
                            </div>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* ================================================================== */}
                {/* Email Preview Dialog                                                */}
                {/* ================================================================== */}
                <Dialog open={!!previewingEmail} onOpenChange={(open) => !open && setPreviewingEmail(null)}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Preview: {previewingEmail?.name}</DialogTitle>
                            <DialogDescription>Rendered with sample data</DialogDescription>
                        </DialogHeader>
                        {previewingEmail && (
                            <div className="rounded-md border bg-background overflow-hidden">
                                <div className="h-3 w-full" style={{ backgroundColor: settings.headerColour }} />
                                {settings.logoEnabled && (
                                    <div className="flex items-center gap-2 border-b px-4 py-3">
                                        <div className="h-8 w-8 rounded-md bg-violet-600 flex items-center justify-center">
                                            <Mail className="h-4 w-4 text-white" />
                                        </div>
                                        <span className="text-sm font-semibold">Kiwi Care Ltd</span>
                                    </div>
                                )}
                                <div className="p-5 space-y-3">
                                    <p className="text-sm font-semibold">{renderPreview(previewingEmail.subject)}</p>
                                    <hr />
                                    <div className="whitespace-pre-wrap text-sm text-foreground/80 leading-relaxed">
                                        {renderPreview(previewingEmail.body)}
                                    </div>
                                    {settings.emailSignature && (
                                        <>
                                            <hr />
                                            <div className="whitespace-pre-wrap text-xs text-muted-foreground leading-relaxed">
                                                {settings.emailSignature}
                                            </div>
                                        </>
                                    )}
                                    {settings.unsubscribeLink && (
                                        <p className="text-[10px] text-muted-foreground text-center pt-2 border-t">
                                            <span className="underline cursor-pointer">Unsubscribe</span> from these notifications
                                        </p>
                                    )}
                                </div>
                            </div>
                        )}
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setPreviewingEmail(null)}>
                                Close
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* ================================================================== */}
                {/* SMS Edit Dialog                                                     */}
                {/* ================================================================== */}
                <Dialog open={!!editingSms} onOpenChange={(open) => !open && setEditingSms(null)}>
                    <DialogContent className="sm:max-w-xl">
                        <DialogHeader>
                            <DialogTitle>Edit SMS Template: {editingSms?.name}</DialogTitle>
                            <DialogDescription>{editingSms?.description}</DialogDescription>
                        </DialogHeader>
                        <div className="space-y-5">
                            <div>
                                <Label htmlFor="sms-body" className="text-sm font-medium">Message</Label>
                                <div className="mt-1.5 flex flex-wrap gap-1 mb-2">
                                    <span className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mr-1 self-center">Insert:</span>
                                    {editingSms?.mergeFields.map((field) => (
                                        <button
                                            key={`sms-${field}`}
                                            type="button"
                                            onClick={() => insertAtCursor(field, 'sms')}
                                            className="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 font-mono text-[10px] text-violet-700 transition-colors hover:bg-violet-200 dark:bg-violet-900/40 dark:text-violet-300 dark:hover:bg-violet-900/60 cursor-pointer"
                                        >
                                            {field}
                                        </button>
                                    ))}
                                </div>
                                <Textarea
                                    id="sms-body"
                                    ref={smsBodyRef}
                                    value={editBody}
                                    onChange={(e) => setEditBody(e.target.value)}
                                    rows={6}
                                    className="font-mono text-sm"
                                />
                                {/* Character & segment counter */}
                                <div className="mt-2 flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${smsColourClass(editBody.length)}`}>
                                            {editBody.length} / 160 characters
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {smsSegments(editBody.length)} segment{smsSegments(editBody.length) !== 1 ? 's' : ''}
                                        </span>
                                    </div>
                                    {editBody.length > 160 && (
                                        <span className="text-[10px] text-amber-600 dark:text-amber-400">
                                            Multi-segment SMS — higher cost
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>

                        <DialogFooter className="mt-4 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <button
                                type="button"
                                onClick={resetSms}
                                className="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors"
                            >
                                <RotateCcw className="h-3 w-3" />
                                Reset to Default
                            </button>
                            <div className="flex items-center gap-2">
                                <Button variant="outline" onClick={handleSendTest} disabled={testSent}>
                                    <Send className="mr-1.5 h-3.5 w-3.5" />
                                    {testSent ? 'Test Sent!' : 'Send Test SMS'}
                                </Button>
                                <Button variant="outline" onClick={() => setEditingSms(null)}>
                                    Cancel
                                </Button>
                                <Button onClick={saveSms} className="bg-violet-600 hover:bg-violet-700">
                                    Save Template
                                </Button>
                            </div>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* ================================================================== */}
                {/* SMS Preview Dialog                                                  */}
                {/* ================================================================== */}
                <Dialog open={!!previewingSms} onOpenChange={(open) => !open && setPreviewingSms(null)}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Preview: {previewingSms?.name}</DialogTitle>
                            <DialogDescription>Rendered with sample data</DialogDescription>
                        </DialogHeader>
                        {previewingSms && (
                            <div className="mx-auto w-72">
                                {/* Phone mockup */}
                                <div className="rounded-2xl border-2 border-muted bg-muted/30 p-4">
                                    <div className="rounded-xl bg-background p-4 shadow-sm">
                                        <div className="rounded-lg bg-emerald-100 px-3 py-2.5 dark:bg-emerald-900/30">
                                            <p className="text-sm text-foreground leading-relaxed">
                                                {renderPreview(previewingSms.body)}
                                            </p>
                                        </div>
                                        <p className="mt-1.5 text-right text-[10px] text-muted-foreground">
                                            Now
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setPreviewingSms(null)}>
                                Close
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
