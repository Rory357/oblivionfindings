<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrOnboardingEmail;
use App\Domain\Hr\Models\HrOnboardingEmailLog;
use App\Domain\Hr\Services\OnboardingEmailService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OnboardingEmailController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly OnboardingEmailService $emailService,
    ) {}

    /**
     * List email templates.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $templates = HrOnboardingEmail::query()
            ->with('creator:id,name')
            ->orderBy('send_days_before_start')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/onboarding/emails', [
            'templates' => $templates,
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }

    /**
     * Create a new email template.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $data = $request->validate([
            'template_name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
            'send_days_before_start' => ['required', 'integer', 'min:-90', 'max:90'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        HrOnboardingEmail::create([
            'tenant_id' => $this->resolveHrTenantIdForUser($user),
            'template_name' => $data['template_name'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'send_days_before_start' => $data['send_days_before_start'],
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Onboarding email template created.');
    }

    /**
     * Update an email template.
     */
    public function update(Request $request, HrOnboardingEmail $email)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $data = $request->validate([
            'template_name' => ['sometimes', 'string', 'max:255'],
            'subject' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:50000'],
            'send_days_before_start' => ['sometimes', 'integer', 'min:-90', 'max:90'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $email->update($data);

        return redirect()->back()->with('success', 'Onboarding email template updated.');
    }

    /**
     * Delete an email template.
     */
    public function destroy(Request $request, HrOnboardingEmail $email)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $email->delete();

        return redirect()->back()->with('success', 'Onboarding email template deleted.');
    }

    /**
     * Preview a rendered email.
     */
    public function preview(Request $request, HrOnboardingEmail $email)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $sampleData = $this->emailService->sampleData();

        return Inertia::render('hr/onboarding/emails', [
            'templates' => HrOnboardingEmail::query()
                ->with('creator:id,name')
                ->orderBy('send_days_before_start')
                ->paginate(20),
            'preview' => [
                'id' => $email->id,
                'template_name' => $email->template_name,
                'subject' => $this->emailService->render($email->subject, $sampleData),
                'body' => $this->emailService->render($email->body, $sampleData),
            ],
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }

    /**
     * View sent email log.
     */
    public function log(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $logs = HrOnboardingEmailLog::query()
            ->with([
                'onboardingEmail:id,template_name,subject',
                'employeeProfile:id,user_id,employee_number',
                'employeeProfile.user:id,name',
            ])
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('hr/onboarding/emails', [
            'templates' => HrOnboardingEmail::query()
                ->with('creator:id,name')
                ->orderBy('send_days_before_start')
                ->paginate(20),
            'emailLog' => $logs,
            'showLog' => true,
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }
}
