<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\NotificationTemplate;
use App\Models\User;

class TemplateRenderService
{
    public function render(NotificationTemplate $template, User $user, array $context = []): string
    {
        $data = array_merge($this->globals($user), $context);

        $body = $template->body;

        foreach ($data as $key => $value) {
            $body = str_replace('{' . $key . '}', (string) $value, $body);
        }

        return $body;
    }

    public function renderSubject(NotificationTemplate $template, User $user, array $context = []): string
    {
        if ($template->subject === null) {
            return '';
        }

        $data = array_merge($this->globals($user), $context);

        $subject = $template->subject;

        foreach ($data as $key => $value) {
            $subject = str_replace('{' . $key . '}', (string) $value, $subject);
        }

        return $subject;
    }

    public function getAvailableMergeFields(): array
    {
        return [
            'Global' => ['name', 'email', 'cellphone', 'work_phone', 'organisation', 'login_url', 'date', 'year', 'app_url'],
            'Operations' => ['client', 'date', 'start_time', 'end_time', 'location', 'shift_date', 'period', 'due_date', 'consent_type', 'invoice_number', 'amount', 'recipient'],
            'Incidents' => ['incident_type', 'severity', 'reporter', 'alert_type', 'contact_number'],
            'HR' => ['leave_type', 'dates', 'approver', 'reason'],
            'System' => ['reset_url', 'expiry', 'document_name', 'expiry_date', 'days_remaining'],
        ];
    }

    private function globals(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'cellphone' => $user->cellphone ?? '',
            'work_phone' => $user->work_phone ?? '',
            'organisation' => AppSetting::where('key', 'branding.name')->value('value') ?? config('app.name'),
            'login_url' => config('app.url') . '/login',
            'date' => now()->format('d/m/Y'),
            'year' => (string) now()->year,
            'app_url' => config('app.url'),
        ];
    }
}
