<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class NotificationTemplateSeeder extends Seeder
{
    public static function defaults(): array
    {
        return [
            [
                'type' => 'email',
                'key' => 'welcome_email',
                'name' => 'Welcome Email',
                'category' => 'system',
                'subject' => 'Welcome to {organisation}',
                'body' => "Hi {name},\n\nWelcome to {organisation}! Your account has been created.\n\nYou can log in at: {login_url}\n\nIf you have any questions, please contact your coordinator.\n\nRegards,\n{organisation}",
                'merge_fields' => ['name', 'email', 'organisation', 'login_url'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'email',
                'key' => 'password_reset',
                'name' => 'Password Reset',
                'category' => 'system',
                'subject' => 'Reset your password — {organisation}',
                'body' => "Hi {name},\n\nWe received a request to reset your password.\n\nClick here to reset: {reset_url}\n\nThis link expires in {expiry}.\n\nIf you didn't request this, ignore this email.\n\nRegards,\n{organisation}",
                'merge_fields' => ['name', 'reset_url', 'expiry', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'email',
                'key' => 'shift_reminder',
                'name' => 'Shift Reminder',
                'category' => 'operations',
                'subject' => 'Shift Reminder — {date}',
                'body' => "Hi {name},\n\nThis is a reminder of your upcoming shift:\n\nDate: {date}\nTime: {start_time} — {end_time}\nClient: {client}\nLocation: {location}\n\nPlease arrive 10 minutes early.\n\nRegards,\n{organisation}",
                'merge_fields' => ['name', 'date', 'start_time', 'end_time', 'client', 'location', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'email',
                'key' => 'incident_alert',
                'name' => 'Incident Alert',
                'category' => 'incidents',
                'subject' => 'Incident Alert — {incident_type}',
                'body' => "Hi {name},\n\nA new incident has been reported:\n\nType: {incident_type}\nSeverity: {severity}\nReported by: {reporter}\n\nPlease review and take appropriate action.\n\nRegards,\n{organisation}",
                'merge_fields' => ['name', 'incident_type', 'severity', 'reporter', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'email',
                'key' => 'leave_approved',
                'name' => 'Leave Approved',
                'category' => 'hr',
                'subject' => 'Leave Request Approved',
                'body' => "Hi {name},\n\nYour leave request has been approved:\n\nType: {leave_type}\nDates: {dates}\nApproved by: {approver}\n\nRegards,\n{organisation}",
                'merge_fields' => ['name', 'leave_type', 'dates', 'approver', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'email',
                'key' => 'leave_declined',
                'name' => 'Leave Declined',
                'category' => 'hr',
                'subject' => 'Leave Request Declined',
                'body' => "Hi {name},\n\nYour leave request has been declined:\n\nType: {leave_type}\nDates: {dates}\nReason: {reason}\n\nPlease contact your manager to discuss alternatives.\n\nRegards,\n{organisation}",
                'merge_fields' => ['name', 'leave_type', 'dates', 'reason', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'email',
                'key' => 'timesheet_reminder',
                'name' => 'Timesheet Reminder',
                'category' => 'operations',
                'subject' => 'Timesheet Due — {period}',
                'body' => "Hi {name},\n\nYour timesheet for {period} is due by {due_date}.\n\nPlease submit your hours at your earliest convenience.\n\nRegards,\n{organisation}",
                'merge_fields' => ['name', 'period', 'due_date', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'email',
                'key' => 'document_expiry',
                'name' => 'Document Expiry',
                'category' => 'system',
                'subject' => 'Document Expiring — {document_name}',
                'body' => "Hi {name},\n\n{document_name} is expiring on {expiry_date} ({days_remaining} days remaining).\n\nPlease arrange renewal.\n\nRegards,\n{organisation}",
                'merge_fields' => ['name', 'document_name', 'expiry_date', 'days_remaining', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'email',
                'key' => 'consent_reminder',
                'name' => 'Consent Reminder',
                'category' => 'operations',
                'subject' => 'Consent Required — {consent_type}',
                'body' => "Hi {name},\n\nA consent review is required for {consent_type}.\n\nPlease review and update at your earliest convenience.\n\nRegards,\n{organisation}",
                'merge_fields' => ['name', 'consent_type', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'email',
                'key' => 'invoice_sent',
                'name' => 'Invoice Sent',
                'category' => 'operations',
                'subject' => 'Invoice {invoice_number}',
                'body' => "Hi {name},\n\nInvoice {invoice_number} for {amount} has been sent to {recipient}.\n\nRegards,\n{organisation}",
                'merge_fields' => ['name', 'invoice_number', 'amount', 'recipient', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'sms',
                'key' => 'shift_reminder_sms',
                'name' => 'Shift Reminder SMS',
                'category' => 'operations',
                'subject' => null,
                'body' => "Reminder: Shift on {date} {start_time}-{end_time} for {client} at {location}. — {organisation}",
                'merge_fields' => ['date', 'start_time', 'end_time', 'client', 'location', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'sms',
                'key' => 'emergency_alert_sms',
                'name' => 'Emergency Alert SMS',
                'category' => 'incidents',
                'subject' => null,
                'body' => "URGENT: {alert_type} at {location}. Contact: {contact_number}. — {organisation}",
                'merge_fields' => ['alert_type', 'location', 'contact_number', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
            [
                'type' => 'sms',
                'key' => 'availability_request_sms',
                'name' => 'Availability Request SMS',
                'category' => 'operations',
                'subject' => null,
                'body' => "Hi {name}, are you available for a shift on {date} {start_time}-{end_time}? Reply YES/NO. — {organisation}",
                'merge_fields' => ['name', 'date', 'start_time', 'end_time', 'organisation'],
                'is_active' => true,
                'is_system' => true,
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::defaults() as $template) {
            NotificationTemplate::updateOrCreate(
                ['key' => $template['key']],
                $template,
            );
        }
    }
}
