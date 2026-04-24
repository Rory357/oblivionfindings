import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Switch } from '@/components/ui/switch';
import { TabsRoot, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Briefcase,
    Eye,
    Hash,
    Mail,
    MessageSquare,
    Pencil,
    RotateCcw,
    Search,
    Send,
    Settings2,
    Shield,
    Smartphone,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------
interface Template {
    id: number;
    type: 'email' | 'sms';
    key: string;
    name: string;
    category: string;
    subject: string | null;
    body: string;
    merge_fields: string[];
    is_active: boolean;
    is_system: boolean;
}

interface Props {
    templates?: Template[];
    mergeFieldRegistry?: Record<string, string[]>;
    orgName?: string;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
const CATEGORIES = ['All', 'Operations', 'HR', 'Incidents', 'System'] as const;

const CATEGORY_CONFIG: Record<string, { colour: string; bg: string; icon: typeof Briefcase }> = {
    operations: { colour: 'text-primary dark:text-primary', bg: 'bg-primary/10 dark:bg-primary/40', icon: Briefcase },
    hr: { colour: 'text-status-info dark:text-status-info', bg: 'bg-status-info-bg dark:bg-status-info', icon: Users },
    incidents: { colour: 'text-status-critical dark:text-status-critical', bg: 'bg-status-critical-bg dark:bg-status-critical', icon: AlertTriangle },
    system: { colour: 'text-foreground dark:text-muted-foreground', bg: 'bg-muted dark:bg-muted/60', icon: Shield },
};

const KEY_DESCRIPTIONS: Record<string, string> = {
    'welcome': 'Sent to new users when their account is created',
    'password-reset': 'Password reset request notification',
    'shift-reminder': 'Upcoming shift reminder sent to rostered staff',
    'incident-alert': 'Notification when an incident is reported',
    'leave-approved': 'Confirmation when leave is approved by a manager',
    'leave-declined': 'Notification when a leave request is declined',
    'timesheet-reminder': 'Reminder to submit timesheets before the due date',
    'document-expiry': 'Alert when a credential or document is about to expire',
    'consent-reminder': 'Reminder to obtain or renew client consent',
    'invoice-sent': 'Notification when an invoice is dispatched',
    'shift-reminder-sms': 'SMS reminder for upcoming rostered shifts',
    'emergency-alert-sms': 'Urgent SMS alert for critical incidents',
    'availability-request-sms': 'SMS requesting staff availability for open shifts',
    'medication-reminder': 'Reminder for upcoming medication administration',
    'handover-notes': 'Shift handover notes notification',
    'roster-published': 'Notification when a new roster is published',
    'training-due': 'Reminder when training or certification renewal is due',
    'complaint-received': 'Notification when a complaint or feedback is lodged',
    'client-review-due': 'Reminder when a client support plan review is due',
};

const SAMPLE_DATA: Record<string, string> = {
    '{name}': 'Aroha Williams',
    '{email}': 'aroha@example.co.nz',
    '{organisation}': '', // filled dynamically from orgName
    '{login_url}': 'https://app.example.co.nz/login',
    '{reset_url}': 'https://app.example.co.nz/reset/abc123',
    '{expiry}': '24 hours',
    '{date}': '28 March 2026',
    '{start_time}': '07:00',
    '{end_time}': '15:00',
    '{client}': 'Te Whare Aroha',
    '{location}': '42 Lambton Quay, Wellington',
    '{incident_type}': 'Medication Error',
    '{reporter}': 'Hemi Taupo',
    '{severity}': 'Moderate',
    '{dates}': '1 Apr - 5 Apr 2026',
    '{leave_type}': 'Annual Leave',
    '{approver}': 'Sarah Chen',
    '{reason}': 'Insufficient staffing coverage',
    '{period}': 'Week ending 29 Mar',
    '{due_date}': '31 March 2026',
    '{document_name}': 'First Aid Certificate',
    '{days_remaining}': '14',
    '{alert_type}': 'Critical Incident',
    '{contact_number}': '+64 4 555 0123',
    '{consent_type}': 'Medication Management',
    '{invoice_number}': 'INV-2026-0042',
    '{amount}': '$1,250.00',
    '{recipient}': 'NASC Funding',
};

function getCsrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function getCategoryConfig(category: string) {
    return CATEGORY_CONFIG[category?.toLowerCase()] ?? CATEGORY_CONFIG.system;
}

function smsSegments(length: number): number {
    if (length <= 160) return 1;
    return Math.ceil(length / 153);
}

function charCountColour(length: number): string {
    if (length <= 160) return 'text-status-success dark:text-status-success';
    if (length <= 320) return 'text-status-warning dark:text-status-warning';
    return 'text-status-critical dark:text-status-critical';
}

function renderWithSampleData(text: string, orgName: string): string {
    if (!text) return '';
    let result = text;
    const data = { ...SAMPLE_DATA, '{organisation}': orgName || 'Your Organisation' };
    for (const [field, value] of Object.entries(data)) {
        result = result.replaceAll(field, value);
    }
    return result;
}

// ---------------------------------------------------------------------------
// Component: StatCard
// ---------------------------------------------------------------------------
function StatCard({ label, value, colour, icon: Icon }: { label: string; value: number; colour: string; icon: typeof Mail }) {
    return (
        <Card>
            <CardContent className="flex items-center gap-4 p-4">
                <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${colour}`}>
                    <Icon className="h-5 w-5 text-white" />
                </div>
                <div>
                    <p className="text-2xl font-bold">{value}</p>
                    <p className="text-xs text-muted-foreground">{label}</p>
                </div>
            </CardContent>
        </Card>
    );
}

// ---------------------------------------------------------------------------
// Component: MergeFieldPills (clickable, inserts at cursor)
// ---------------------------------------------------------------------------
function MergeFieldPills({
    fields,
    registry,
    onInsert,
}: {
    fields: string[];
    registry: Record<string, string[]>;
    onInsert: (field: string) => void;
}) {
    // Group fields by registry category
    const grouped = useMemo(() => {
        const groups: Record<string, string[]> = {};
        const registryEntries = Object.entries(registry);

        for (const field of fields) {
            const bare = field.replace(/[{}]/g, '');
            let placed = false;
            for (const [group, groupFields] of registryEntries) {
                if (groupFields.includes(bare)) {
                    if (!groups[group]) groups[group] = [];
                    groups[group].push(field);
                    placed = true;
                    break;
                }
            }
            if (!placed) {
                if (!groups['Other']) groups['Other'] = [];
                groups['Other'].push(field);
            }
        }
        return groups;
    }, [fields, registry]);

    const groupEntries = Object.entries(grouped);

    if (groupEntries.length === 0) return null;

    return (
        <div className="space-y-2">
            {groupEntries.map(([group, groupFields]) => (
                <div key={group}>
                    <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {group}
                    </p>
                    <div className="flex flex-wrap gap-1">
                        {groupFields.map((field) => (
                            <button
                                key={field}
                                type="button"
                                onClick={() => onInsert(field)}
                                className="inline-flex items-center rounded-full border border-primary bg-primary/10 px-2 py-0.5 font-mono text-[11px] text-primary transition-colors hover:bg-primary/10 dark:border-primary/30 dark:bg-primary/30 dark:text-primary/70 dark:hover:bg-primary/50"
                            >
                                {field}
                            </button>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Component: TemplateCard
// ---------------------------------------------------------------------------
function TemplateCard({
    template,
    orgName,
    onEdit,
    onPreview,
    onSendTest,
}: {
    template: Template;
    orgName: string;
    onEdit: () => void;
    onPreview: () => void;
    onSendTest: () => void;
}) {
    const config = getCategoryConfig(template.category);
    const CatIcon = config.icon;
    const description = KEY_DESCRIPTIONS[template.key] ?? `${template.name} notification`;
    const isEmail = template.type === 'email';

    return (
        <Card className={`transition-opacity ${!template.is_active ? 'opacity-50' : ''}`}>
            <CardContent className="p-5">
                <div className="flex items-start gap-4">
                    {/* Category icon */}
                    <div className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${config.bg}`}>
                        <CatIcon className={`h-5 w-5 ${config.colour}`} />
                    </div>

                    {/* Content */}
                    <div className="min-w-0 flex-1 space-y-2">
                        {/* Name + badges */}
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-sm font-semibold">{template.name}</h3>
                            <Badge variant="outline" className="text-[10px] capitalize">
                                {template.category}
                            </Badge>
                            {template.is_active ? (
                                <Badge className="bg-status-success-bg text-[10px] text-status-success dark:bg-status-success-bg dark:text-status-success">
                                    Active
                                </Badge>
                            ) : (
                                <Badge variant="secondary" className="text-[10px]">
                                    Inactive
                                </Badge>
                            )}
                            {template.is_system && (
                                <Badge variant="outline" className="text-[10px] text-muted-foreground">
                                    System
                                </Badge>
                            )}
                        </div>

                        {/* Description */}
                        <p className="text-xs text-muted-foreground">{description}</p>

                        {/* Subject preview (email only) */}
                        {isEmail && template.subject && (
                            <div className="rounded-md bg-muted/60 px-3 py-1.5">
                                <p className="font-mono text-xs text-muted-foreground">
                                    <span className="font-semibold text-foreground/70">Subject:</span>{' '}
                                    {template.subject}
                                </p>
                            </div>
                        )}

                        {/* Body preview for SMS */}
                        {!isEmail && template.body && (
                            <p className="line-clamp-2 text-xs text-muted-foreground">{template.body}</p>
                        )}

                        {/* Merge field pills */}
                        {template.merge_fields && template.merge_fields.length > 0 && (
                            <div className="flex flex-wrap gap-1">
                                {template.merge_fields.map((field) => (
                                    <span
                                        key={field}
                                        className="inline-flex rounded-full border border-primary bg-primary/10 px-2 py-0.5 font-mono text-[10px] text-primary dark:border-primary/30 dark:bg-primary/30 dark:text-primary"
                                    >
                                        {`{${field}}`}
                                    </span>
                                ))}
                            </div>
                        )}

                        {/* SMS character count */}
                        {!isEmail && (
                            <div className="flex items-center gap-2">
                                <span className={`text-xs font-medium ${charCountColour(template.body.length)}`}>
                                    {template.body.length} chars
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    ({smsSegments(template.body.length)} segment{smsSegments(template.body.length) !== 1 ? 's' : ''})
                                </span>
                            </div>
                        )}
                    </div>

                    {/* Actions */}
                    <div className="flex shrink-0 flex-col gap-1.5 sm:flex-row">
                        <Button variant="outline" size="sm" onClick={onEdit} className="border-primary text-primary hover:bg-primary/10 dark:border-primary/30 dark:text-primary/70">
                            <Pencil className="mr-1.5 h-3 w-3" />
                            Edit
                        </Button>
                        <Button variant="outline" size="sm" onClick={onPreview}>
                            <Eye className="mr-1.5 h-3 w-3" />
                            Preview
                        </Button>
                        {isEmail && (
                            <Button variant="outline" size="sm" onClick={onSendTest}>
                                <Send className="mr-1.5 h-3 w-3" />
                                Test
                            </Button>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

// ---------------------------------------------------------------------------
// Component: PhoneMockup
// ---------------------------------------------------------------------------
function PhoneMockup({ message }: { message: string }) {
    return (
        <div className="mx-auto w-64">
            <div className="rounded-[2rem] border-4 border-slate-800 bg-muted p-4 dark:border-border dark:bg-muted">
                {/* Notch */}
                <div className="mx-auto mb-3 h-5 w-20 rounded-full bg-muted dark:bg-muted-foreground/80" />
                {/* Screen */}
                <div className="min-h-[200px] rounded-xl bg-white p-3 dark:bg-muted">
                    <p className="mb-2 text-center text-[10px] text-muted-foreground">Today 09:00</p>
                    <div className="ml-auto max-w-[85%] rounded-2xl rounded-tr-sm bg-primary px-3 py-2 text-xs text-white">
                        {message}
                    </div>
                </div>
                {/* Home bar */}
                <div className="mx-auto mt-3 h-1 w-16 rounded-full bg-muted dark:bg-muted-foreground/80" />
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Component: EmailFrame
// ---------------------------------------------------------------------------
function EmailFrame({ subject, body, headerColour }: { subject: string; body: string; headerColour?: string }) {
    return (
        <div className="overflow-hidden rounded-lg border">
            {/* Colour bar */}
            <div className="h-2" style={{ backgroundColor: headerColour || '#7c3aed' }} />
            <div className="bg-white p-5 dark:bg-muted">
                {subject && (
                    <p className="mb-3 border-b pb-2 text-sm font-semibold text-foreground">{subject}</p>
                )}
                <div
                    className="prose prose-sm max-w-none text-sm dark:prose-invert"
                    dangerouslySetInnerHTML={{ __html: body.replace(/\n/g, '<br />') }}
                />
                <div className="mt-6 border-t pt-3 text-[10px] text-muted-foreground">
                    This is an automated notification. Please do not reply directly to this email.
                </div>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Component: TemplateList (shared between Email/SMS tabs)
// ---------------------------------------------------------------------------
function TemplateList({
    templates,
    orgName,
    onEdit,
    onPreview,
    onSendTest,
}: {
    templates: Template[];
    orgName: string;
    onEdit: (t: Template) => void;
    onPreview: (t: Template) => void;
    onSendTest: (t: Template) => void;
}) {
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('All');

    const filtered = useMemo(() => {
        return templates.filter((t) => {
            const matchesSearch =
                !search ||
                t.name.toLowerCase().includes(search.toLowerCase()) ||
                t.key.toLowerCase().includes(search.toLowerCase());
            const matchesCategory =
                categoryFilter === 'All' ||
                t.category.toLowerCase() === categoryFilter.toLowerCase();
            return matchesSearch && matchesCategory;
        });
    }, [templates, search, categoryFilter]);

    return (
        <div className="space-y-4">
            {/* Filters */}
            <div className="flex flex-col gap-3 sm:flex-row">
                <div className="relative flex-1">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        placeholder="Search templates..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="pl-9"
                    />
                </div>
                <select
                    value={categoryFilter}
                    onChange={(e) => setCategoryFilter(e.target.value)}
                    className="h-9 rounded-md border border-input bg-background px-3 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    {CATEGORIES.map((cat) => (
                        <option key={cat} value={cat}>
                            {cat === 'All' ? 'All Categories' : cat}
                        </option>
                    ))}
                </select>
            </div>

            {/* Template cards */}
            {filtered.length === 0 ? (
                <Card>
                    <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                        <Search className="mb-3 h-8 w-8 text-muted-foreground/40" />
                        <p className="text-sm font-medium text-muted-foreground">No templates found</p>
                        <p className="text-xs text-muted-foreground/70">Try adjusting your search or filter</p>
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-3">
                    {filtered.map((template) => (
                        <TemplateCard
                            key={template.id}
                            template={template}
                            orgName={orgName}
                            onEdit={() => onEdit(template)}
                            onPreview={() => onPreview(template)}
                            onSendTest={() => onSendTest(template)}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Component: TemplateSettings (Tab 3)
// ---------------------------------------------------------------------------
function TemplateSettings() {
    const [signature, setSignature] = useState(() =>
        typeof window !== 'undefined' ? localStorage.getItem('tpl:signature') ?? 'Regards,\n{organisation}' : 'Regards,\n{organisation}',
    );
    const [logoEnabled, setLogoEnabled] = useState(() =>
        typeof window !== 'undefined' ? localStorage.getItem('tpl:logo') !== 'false' : true,
    );
    const [headerColour, setHeaderColour] = useState(() =>
        typeof window !== 'undefined' ? localStorage.getItem('tpl:headerColour') ?? '#7c3aed' : '#7c3aed',
    );
    const [unsubscribeLink, setUnsubscribeLink] = useState(() =>
        typeof window !== 'undefined' ? localStorage.getItem('tpl:unsubscribe') !== 'false' : true,
    );
    const [replyTo, setReplyTo] = useState(() =>
        typeof window !== 'undefined' ? localStorage.getItem('tpl:replyTo') ?? '' : '',
    );
    const [saved, setSaved] = useState(false);

    function handleSave() {
        localStorage.setItem('tpl:signature', signature);
        localStorage.setItem('tpl:logo', String(logoEnabled));
        localStorage.setItem('tpl:headerColour', headerColour);
        localStorage.setItem('tpl:unsubscribe', String(unsubscribeLink));
        localStorage.setItem('tpl:replyTo', replyTo);
        setSaved(true);
        setTimeout(() => setSaved(false), 2500);
    }

    return (
        <div className="space-y-6">
            {/* Email Appearance */}
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Email Appearance</CardTitle>
                    <CardDescription>Customise how your outgoing emails look</CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                    <div>
                        <Label htmlFor="email-signature">Email Signature</Label>
                        <Textarea
                            id="email-signature"
                            value={signature}
                            onChange={(e) => setSignature(e.target.value)}
                            rows={5}
                            placeholder={'Regards,\n{organisation}'}
                            className="mt-1.5 font-mono text-sm"
                        />
                    </div>

                    <div className="flex items-center justify-between">
                        <div>
                            <Label>Logo in Emails</Label>
                            <p className="text-xs text-muted-foreground">Display your organisation logo in email headers</p>
                        </div>
                        <Switch checked={logoEnabled} onCheckedChange={setLogoEnabled} />
                    </div>

                    <div>
                        <Label htmlFor="header-colour">Header Colour</Label>
                        <div className="mt-1.5 flex items-center gap-3">
                            <input
                                type="color"
                                id="header-colour"
                                value={headerColour}
                                onChange={(e) => setHeaderColour(e.target.value)}
                                className="h-9 w-12 cursor-pointer rounded border"
                            />
                            <Input
                                value={headerColour}
                                onChange={(e) => setHeaderColour(e.target.value)}
                                className="w-28 font-mono text-sm"
                                maxLength={7}
                            />
                            <div
                                className="h-9 flex-1 rounded-md"
                                style={{ backgroundColor: headerColour }}
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Delivery Settings */}
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Delivery Settings</CardTitle>
                    <CardDescription>Configure email delivery preferences</CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                    <div className="flex items-center justify-between">
                        <div>
                            <Label>Unsubscribe Link</Label>
                            <p className="text-xs text-muted-foreground">Include an unsubscribe link in all emails</p>
                        </div>
                        <Switch checked={unsubscribeLink} onCheckedChange={setUnsubscribeLink} />
                    </div>

                    <div>
                        <Label htmlFor="reply-to">Reply-to Address</Label>
                        <Input
                            id="reply-to"
                            type="email"
                            value={replyTo}
                            onChange={(e) => setReplyTo(e.target.value)}
                            placeholder="noreply@yourorganisation.co.nz"
                            className="mt-1.5"
                        />
                        <p className="mt-1 text-xs text-muted-foreground">
                            Leave blank to use the default system address
                        </p>
                    </div>
                </CardContent>
            </Card>

            <div className="flex items-center gap-3">
                <Button onClick={handleSave} className="bg-primary hover:bg-primary">
                    {saved ? 'Saved!' : 'Save Settings'}
                </Button>
                {saved && <span className="text-sm text-status-success">Settings saved successfully</span>}
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main Page Component
// ---------------------------------------------------------------------------
export default function Templates({ templates: rawTemplates, mergeFieldRegistry, orgName }: Props) {
    const templates = rawTemplates ?? [];
    const registry = mergeFieldRegistry ?? {};
    const org = orgName ?? 'Your Organisation';

    // Counts
    const emailTemplates = useMemo(() => templates.filter((t) => t.type === 'email'), [templates]);
    const smsTemplates = useMemo(() => templates.filter((t) => t.type === 'sms'), [templates]);

    // Edit dialog state
    const [editingTemplate, setEditingTemplate] = useState<Template | null>(null);
    const [editSubject, setEditSubject] = useState('');
    const [editBody, setEditBody] = useState('');
    const [editActive, setEditActive] = useState(true);
    const [saving, setSaving] = useState(false);
    const bodyRef = useRef<HTMLTextAreaElement>(null);

    // Preview dialog state
    const [previewTemplate, setPreviewTemplate] = useState<Template | null>(null);
    const [previewHtml, setPreviewHtml] = useState('');
    const [previewSubject, setPreviewSubject] = useState('');
    const [previewLoading, setPreviewLoading] = useState(false);

    // Flash message state
    const [flash, setFlash] = useState<string | null>(null);

    function showFlash(message: string) {
        setFlash(message);
        setTimeout(() => setFlash(null), 3500);
    }

    // ---- Edit ----
    function openEdit(template: Template) {
        setEditingTemplate(template);
        setEditSubject(template.subject ?? '');
        setEditBody(template.body ?? '');
        setEditActive(template.is_active);
    }

    function closeEdit() {
        setEditingTemplate(null);
    }

    function insertMergeField(field: string) {
        const textarea = bodyRef.current;
        if (!textarea) {
            setEditBody((prev) => prev + field);
            return;
        }
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const before = editBody.slice(0, start);
        const after = editBody.slice(end);
        const newBody = before + field + after;
        setEditBody(newBody);
        // Restore cursor after field
        requestAnimationFrame(() => {
            textarea.focus();
            const cursorPos = start + field.length;
            textarea.setSelectionRange(cursorPos, cursorPos);
        });
    }

    function handleSave() {
        if (!editingTemplate) return;
        setSaving(true);
        router.put(
            `/settings/templates/${editingTemplate.id}`,
            {
                subject: editSubject || null,
                body: editBody,
                is_active: editActive,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    closeEdit();
                    showFlash('Template updated successfully.');
                },
                onFinish: () => setSaving(false),
            },
        );
    }

    function handleReset() {
        if (!editingTemplate) return;
        router.post(
            `/settings/templates/${editingTemplate.id}/reset`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    closeEdit();
                    showFlash('Template reset to default.');
                },
            },
        );
    }

    // ---- Preview ----
    async function openPreview(template: Template) {
        setPreviewTemplate(template);
        setPreviewLoading(true);
        setPreviewHtml('');
        setPreviewSubject('');

        try {
            const res = await fetch(`/settings/templates/${template.id}/preview`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
            });
            if (res.ok) {
                const data = await res.json();
                setPreviewHtml(data.html ?? '');
                setPreviewSubject(data.subject ?? '');
            } else {
                setPreviewHtml('<p class="text-status-critical">Failed to load preview.</p>');
            }
        } catch {
            setPreviewHtml('<p class="text-status-critical">Network error loading preview.</p>');
        } finally {
            setPreviewLoading(false);
        }
    }

    function closePreview() {
        setPreviewTemplate(null);
    }

    // ---- Send Test ----
    function handleSendTest(template: Template) {
        router.post(
            `/settings/templates/${template.id}/send-test`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => showFlash('Test email sent to your inbox.'),
            },
        );
    }

    // ---- Live preview in edit dialog ----
    const livePreviewBody = useMemo(() => {
        return renderWithSampleData(editBody, org);
    }, [editBody, org]);

    const livePreviewSubject = useMemo(() => {
        return renderWithSampleData(editSubject, org);
    }, [editSubject, org]);

    const isEmail = editingTemplate?.type === 'email';
    const editMergeFields = editingTemplate?.merge_fields?.map((f) => `{${f}}`) ?? [];

    const breadcrumbs = [{ title: 'Settings', href: '/settings' }, { title: 'Templates', href: '/settings/templates' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Email & SMS Templates" />
            <SettingsLayout>

            {/* Flash toast */}
            {flash && (
                <div className="fixed right-4 top-4 z-50 animate-in fade-in slide-in-from-top-2">
                    <div className="rounded-lg border bg-status-success-bg px-4 py-3 text-sm font-medium text-status-success shadow-lg dark:bg-status-success-bg dark:text-status-success">
                        {flash}
                    </div>
                </div>
            )}

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex items-start gap-3">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 dark:bg-primary/40">
                            <Mail className="h-5 w-5 text-primary dark:text-primary" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold">Email &amp; SMS Templates</h1>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                Customise notification emails and SMS messages. Use merge fields to personalise content.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <StatCard label="Total Templates" value={templates.length} colour="bg-primary" icon={Hash} />
                    <StatCard label="Email Templates" value={emailTemplates.length} colour="bg-status-info" icon={Mail} />
                    <StatCard label="SMS Templates" value={smsTemplates.length} colour="bg-status-success" icon={Smartphone} />
                </div>

                {/* Tabs */}
                <TabsRoot defaultValue="email">
                    <TabsList className="w-full sm:w-auto">
                        <TabsTrigger value="email" className="gap-1.5">
                            <Mail className="h-3.5 w-3.5" />
                            Email Templates
                        </TabsTrigger>
                        <TabsTrigger value="sms" className="gap-1.5">
                            <MessageSquare className="h-3.5 w-3.5" />
                            SMS Templates
                        </TabsTrigger>
                        <TabsTrigger value="settings" className="gap-1.5">
                            <Settings2 className="h-3.5 w-3.5" />
                            Template Settings
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="email">
                        <TemplateList
                            templates={emailTemplates}
                            orgName={org}
                            onEdit={openEdit}
                            onPreview={openPreview}
                            onSendTest={handleSendTest}
                        />
                    </TabsContent>

                    <TabsContent value="sms">
                        <TemplateList
                            templates={smsTemplates}
                            orgName={org}
                            onEdit={openEdit}
                            onPreview={openPreview}
                            onSendTest={handleSendTest}
                        />
                    </TabsContent>

                    <TabsContent value="settings">
                        <TemplateSettings />
                    </TabsContent>
                </TabsRoot>
            </div>

            {/* ================================================================ */}
            {/* Edit Dialog                                                       */}
            {/* ================================================================ */}
            <Dialog open={!!editingTemplate} onOpenChange={(open) => !open && closeEdit()}>
                <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Edit Template &mdash; {editingTemplate?.name}</DialogTitle>
                        <DialogDescription>
                            {editingTemplate ? KEY_DESCRIPTIONS[editingTemplate.key] ?? 'Modify the template content and settings below.' : ''}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
                        {/* Left column: editor (60%) */}
                        <div className="space-y-4 lg:col-span-3">
                            {/* Subject (email only) */}
                            {isEmail && (
                                <div>
                                    <Label htmlFor="edit-subject">Subject</Label>
                                    <Input
                                        id="edit-subject"
                                        value={editSubject}
                                        onChange={(e) => setEditSubject(e.target.value)}
                                        className="mt-1.5"
                                    />
                                </div>
                            )}

                            {/* Merge fields */}
                            <div>
                                <Label className="mb-1.5 block text-xs text-muted-foreground">
                                    Click a merge field to insert at cursor position
                                </Label>
                                <MergeFieldPills
                                    fields={editMergeFields}
                                    registry={registry}
                                    onInsert={insertMergeField}
                                />
                            </div>

                            {/* Body */}
                            <div>
                                <Label htmlFor="edit-body">Body</Label>
                                <Textarea
                                    id="edit-body"
                                    ref={bodyRef}
                                    value={editBody}
                                    onChange={(e) => setEditBody(e.target.value)}
                                    rows={isEmail ? 12 : 4}
                                    className="mt-1.5 font-mono text-sm"
                                />
                                {/* SMS character counter */}
                                {!isEmail && (
                                    <div className="mt-1.5 flex items-center gap-2">
                                        <span className={`text-xs font-semibold ${charCountColour(editBody.length)}`}>
                                            {editBody.length} / 160 characters
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            ({smsSegments(editBody.length)} segment{smsSegments(editBody.length) !== 1 ? 's' : ''})
                                        </span>
                                    </div>
                                )}
                            </div>

                            {/* Active toggle */}
                            <div className="flex items-center justify-between rounded-lg border p-3">
                                <div>
                                    <Label>Active</Label>
                                    <p className="text-xs text-muted-foreground">
                                        When disabled, this template will not be sent
                                    </p>
                                </div>
                                <Switch checked={editActive} onCheckedChange={setEditActive} />
                            </div>

                            {/* Reset to default */}
                            {editingTemplate?.is_system && (
                                <button
                                    type="button"
                                    onClick={handleReset}
                                    className="inline-flex items-center gap-1.5 text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                                >
                                    <RotateCcw className="h-3 w-3" />
                                    Reset to default
                                </button>
                            )}
                        </div>

                        {/* Right column: live preview (40%) */}
                        <div className="lg:col-span-2">
                            <Label className="mb-2 block">Live Preview</Label>
                            {isEmail ? (
                                <EmailFrame
                                    subject={livePreviewSubject}
                                    body={livePreviewBody}
                                />
                            ) : (
                                <PhoneMockup message={livePreviewBody || 'Your message preview will appear here...'} />
                            )}
                            <p className="mt-2 text-center text-[10px] text-muted-foreground">
                                Merge fields are replaced with sample data
                            </p>
                        </div>
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button variant="outline" onClick={closeEdit}>
                            Cancel
                        </Button>
                        <Button
                            onClick={handleSave}
                            disabled={saving}
                            className="bg-primary hover:bg-primary"
                        >
                            {saving ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/* Preview Dialog                                                    */}
            {/* ================================================================ */}
            <Dialog open={!!previewTemplate} onOpenChange={(open) => !open && closePreview()}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Preview &mdash; {previewTemplate?.name}</DialogTitle>
                        <DialogDescription>Rendered with your actual data</DialogDescription>
                    </DialogHeader>

                    {/* Info banner */}
                    <div className="rounded-md border border-status-info/30 bg-status-info-bg px-4 py-2.5 text-xs text-status-info dark:border-status-info/30 dark:bg-status-info-bg dark:text-status-info">
                        <strong>Note:</strong> This preview is rendered using your actual account data and merge fields.
                    </div>

                    {previewLoading ? (
                        <div className="flex items-center justify-center py-12">
                            <div className="h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-violet-600" />
                        </div>
                    ) : previewTemplate?.type === 'email' ? (
                        <EmailFrame
                            subject={previewSubject}
                            body={previewHtml}
                        />
                    ) : (
                        <PhoneMockup message={previewHtml || 'No preview available.'} />
                    )}

                    <DialogFooter>
                        <Button variant="outline" onClick={closePreview}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
