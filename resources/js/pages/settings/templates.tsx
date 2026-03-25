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
import { Textarea } from '@/components/ui/textarea';
import SettingsLayout from '@/layouts/settings/layout';
import { Head } from '@inertiajs/react';
import { Mail, MessageSquare, Pencil } from 'lucide-react';
import { useState } from 'react';

interface EmailTemplate {
    id: string;
    name: string;
    description: string;
    subject: string;
    body: string;
    mergeFields: string[];
}

interface SmsTemplate {
    id: string;
    name: string;
    description: string;
    body: string;
    mergeFields: string[];
}

const defaultEmailTemplates: EmailTemplate[] = [
    { id: 'welcome', name: 'Welcome Email', description: 'Sent to new users upon registration', subject: 'Welcome to {organisation}', body: 'Hi {name},\n\nWelcome to {organisation}. Your account has been created successfully.', mergeFields: ['{name}', '{email}', '{organisation}', '{login_url}'] },
    { id: 'password-reset', name: 'Password Reset', description: 'Password reset request notification', subject: 'Reset your password', body: 'Hi {name},\n\nClick the link below to reset your password.\n\n{reset_url}', mergeFields: ['{name}', '{reset_url}', '{expiry}'] },
    { id: 'shift-reminder', name: 'Shift Reminder', description: 'Upcoming shift reminder sent to staff', subject: 'Shift Reminder: {date}', body: 'Hi {name},\n\nThis is a reminder that you have a shift on {date} from {start_time} to {end_time} with {client}.', mergeFields: ['{name}', '{date}', '{start_time}', '{end_time}', '{client}', '{location}'] },
    { id: 'incident-alert', name: 'Incident Alert', description: 'Notification when an incident is reported', subject: 'Incident Reported: {incident_type}', body: 'An incident has been reported.\n\nType: {incident_type}\nClient: {client}\nDate: {date}\nReported by: {reporter}', mergeFields: ['{incident_type}', '{client}', '{date}', '{reporter}', '{severity}'] },
    { id: 'leave-approved', name: 'Leave Approved', description: 'Notification when leave is approved', subject: 'Leave Approved: {dates}', body: 'Hi {name},\n\nYour leave request for {dates} has been approved by {approver}.', mergeFields: ['{name}', '{dates}', '{leave_type}', '{approver}'] },
    { id: 'leave-declined', name: 'Leave Declined', description: 'Notification when leave is declined', subject: 'Leave Request Declined', body: 'Hi {name},\n\nYour leave request for {dates} has been declined.\n\nReason: {reason}', mergeFields: ['{name}', '{dates}', '{leave_type}', '{reason}'] },
    { id: 'timesheet-reminder', name: 'Timesheet Reminder', description: 'Reminder to submit timesheets', subject: 'Timesheet Due: {period}', body: 'Hi {name},\n\nPlease submit your timesheet for {period} by {due_date}.', mergeFields: ['{name}', '{period}', '{due_date}'] },
    { id: 'document-expiry', name: 'Document Expiry', description: 'Alert when a document is about to expire', subject: 'Document Expiring: {document_name}', body: 'Hi {name},\n\nThe following document is expiring on {expiry_date}:\n\n{document_name}', mergeFields: ['{name}', '{document_name}', '{expiry_date}', '{days_remaining}'] },
    { id: 'consent-reminder', name: 'Consent Reminder', description: 'Reminder to obtain or renew consent', subject: 'Consent Required: {client}', body: 'Hi {name},\n\nConsent is required for {client} regarding {consent_type}.\n\nPlease action this by {due_date}.', mergeFields: ['{name}', '{client}', '{consent_type}', '{due_date}'] },
    { id: 'invoice-sent', name: 'Invoice Sent', description: 'Notification when an invoice is sent', subject: 'Invoice #{invoice_number}', body: 'Hi {name},\n\nInvoice #{invoice_number} for {amount} has been sent to {recipient}.', mergeFields: ['{name}', '{invoice_number}', '{amount}', '{recipient}', '{due_date}'] },
];

const defaultSmsTemplates: SmsTemplate[] = [
    { id: 'shift-reminder-sms', name: 'Shift Reminder SMS', description: 'SMS reminder for upcoming shifts', body: 'Reminder: You have a shift on {date} at {start_time} with {client}. Reply CONFIRM to acknowledge.', mergeFields: ['{name}', '{date}', '{start_time}', '{client}'] },
    { id: 'emergency-alert-sms', name: 'Emergency Alert SMS', description: 'Urgent alert for emergencies', body: 'URGENT: {alert_type} at {location}. Please respond immediately. Contact: {contact_number}', mergeFields: ['{alert_type}', '{location}', '{contact_number}'] },
    { id: 'availability-request-sms', name: 'Availability Request SMS', description: 'Request staff availability for shifts', body: 'Hi {name}, can you cover a shift on {date} {start_time}-{end_time}? Reply YES or NO.', mergeFields: ['{name}', '{date}', '{start_time}', '{end_time}'] },
];

export default function Templates() {
    const [emailTemplates, setEmailTemplates] = useState(defaultEmailTemplates);
    const [smsTemplates, setSmsTemplates] = useState(defaultSmsTemplates);
    const [editingEmail, setEditingEmail] = useState<EmailTemplate | null>(null);
    const [editingSms, setEditingSms] = useState<SmsTemplate | null>(null);
    const [editSubject, setEditSubject] = useState('');
    const [editBody, setEditBody] = useState('');
    const [testSent, setTestSent] = useState(false);

    function openEmailEdit(template: EmailTemplate) {
        setEditingEmail(template);
        setEditSubject(template.subject);
        setEditBody(template.body);
        setTestSent(false);
    }

    function openSmsEdit(template: SmsTemplate) {
        setEditingSms(template);
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

    function saveSms() {
        if (!editingSms) return;
        setSmsTemplates((prev) =>
            prev.map((t) => (t.id === editingSms.id ? { ...t, body: editBody } : t)),
        );
        setEditingSms(null);
    }

    function handleSendTest() {
        setTestSent(true);
        setTimeout(() => setTestSent(false), 3000);
    }

    return (
        <SettingsLayout>
            <Head title="Email & SMS Templates" />

            <div className="space-y-6">
                {/* Email Templates */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Mail className="h-5 w-5 text-violet-600" />
                            <div>
                                <CardTitle>Email Templates</CardTitle>
                                <CardDescription>Customise the email notifications sent by the system</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {emailTemplates.map((template) => (
                                <Card key={template.id} className="border-muted">
                                    <CardContent className="p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold">{template.name}</p>
                                                <p className="mt-0.5 text-xs text-muted-foreground">{template.description}</p>
                                            </div>
                                            <Button variant="outline" size="sm" onClick={() => openEmailEdit(template)}>
                                                <Pencil className="mr-1.5 h-3 w-3" />
                                                Edit
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* SMS Templates */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <MessageSquare className="h-5 w-5 text-violet-600" />
                            <div>
                                <CardTitle>SMS Templates</CardTitle>
                                <CardDescription>Configure SMS message templates</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {smsTemplates.map((template) => (
                                <Card key={template.id} className="border-muted">
                                    <CardContent className="p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold">{template.name}</p>
                                                <p className="mt-0.5 text-xs text-muted-foreground">{template.description}</p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {template.body.length}/160 characters
                                                </p>
                                            </div>
                                            <Button variant="outline" size="sm" onClick={() => openSmsEdit(template)}>
                                                <Pencil className="mr-1.5 h-3 w-3" />
                                                Edit
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Email Edit Dialog */}
            <Dialog open={!!editingEmail} onOpenChange={(open) => !open && setEditingEmail(null)}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Edit Email Template: {editingEmail?.name}</DialogTitle>
                        <DialogDescription>{editingEmail?.description}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <Label htmlFor="email-subject">Subject</Label>
                            <Input
                                id="email-subject"
                                value={editSubject}
                                onChange={(e) => setEditSubject(e.target.value)}
                                className="mt-1"
                            />
                        </div>
                        <div>
                            <Label htmlFor="email-body">Body</Label>
                            <Textarea
                                id="email-body"
                                value={editBody}
                                onChange={(e) => setEditBody(e.target.value)}
                                rows={10}
                                className="mt-1 font-mono text-sm"
                            />
                        </div>
                        <div>
                            <Label>Available Merge Fields</Label>
                            <div className="mt-1.5 flex flex-wrap gap-1.5">
                                {editingEmail?.mergeFields.map((field) => (
                                    <Badge key={field} variant="secondary" className="font-mono text-xs">
                                        {field}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    </div>
                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button variant="outline" onClick={handleSendTest} disabled={testSent}>
                            {testSent ? 'Test Sent!' : 'Send Test'}
                        </Button>
                        <Button onClick={saveEmail} className="bg-violet-600 hover:bg-violet-700">
                            Save
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* SMS Edit Dialog */}
            <Dialog open={!!editingSms} onOpenChange={(open) => !open && setEditingSms(null)}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit SMS Template: {editingSms?.name}</DialogTitle>
                        <DialogDescription>{editingSms?.description}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <Label htmlFor="sms-body">Message</Label>
                            <Textarea
                                id="sms-body"
                                value={editBody}
                                onChange={(e) => setEditBody(e.target.value)}
                                rows={4}
                                maxLength={160}
                                className="mt-1 font-mono text-sm"
                            />
                            <p className={`mt-1 text-xs ${editBody.length > 160 ? 'text-red-600 font-medium' : 'text-muted-foreground'}`}>
                                {editBody.length}/160 characters
                            </p>
                        </div>
                        <div>
                            <Label>Available Merge Fields</Label>
                            <div className="mt-1.5 flex flex-wrap gap-1.5">
                                {editingSms?.mergeFields.map((field) => (
                                    <Badge key={field} variant="secondary" className="font-mono text-xs">
                                        {field}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    </div>
                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button variant="outline" onClick={handleSendTest} disabled={testSent}>
                            {testSent ? 'Test Sent!' : 'Send Test'}
                        </Button>
                        <Button onClick={saveSms} className="bg-violet-600 hover:bg-violet-700">
                            Save
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SettingsLayout>
    );
}
