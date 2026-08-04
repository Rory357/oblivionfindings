<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrOnboardingEmail;
use App\Domain\Hr\Services\OnboardingEmailService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreOnboardingEmailRequest;
use App\Mail\Hr\OnboardingTemplateMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Onboarding email templates. Reads now live in the hub's Emails tab (served by
 * OnboardingController@index); this controller only handles mutations.
 */
class OnboardingEmailController extends Controller
{
    public function __construct(
        private readonly OnboardingEmailService $emailService,
    ) {}

    /**
     * Create a new email template.
     */
    public function store(StoreOnboardingEmailRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        HrOnboardingEmail::create([
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
    public function update(StoreOnboardingEmailRequest $request, HrOnboardingEmail $email)
    {
        DB::transaction(function () use ($email, $request): void {
            HrOnboardingEmail::query()
                ->lockForUpdate()
                ->findOrFail($email->getKey())
                ->update($request->validated());
        });

        return redirect()->back()->with('success', 'Onboarding email template updated.');
    }

    /**
     * Delete an email template.
     */
    public function destroy(Request $request, HrOnboardingEmail $email)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        DB::transaction(function () use ($email): void {
            HrOnboardingEmail::query()->lockForUpdate()->findOrFail($email->getKey())->delete();
        });

        return redirect()->back()->with('success', 'Onboarding email template deleted.');
    }

    /**
     * Send a test render of this template to the current user, using sample
     * merge data so what's sent matches the in-modal preview.
     */
    public function test(Request $request, HrOnboardingEmail $email)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $recipient = $user->email;
        if (! $recipient) {
            return redirect()->back()->with('error', 'Your account has no email address to send a test to.');
        }

        $sample = $this->emailService->sampleData();
        $subject = '[TEST] '.$this->emailService->render($email->subject, $sample);
        $body = $this->emailService->render($email->body, $sample);

        try {
            Mail::to($recipient)->send(new OnboardingTemplateMail($subject, $body));
        } catch (\Throwable $exception) {
            return redirect()->back()->with('error', 'Could not send the test email: '.$exception->getMessage());
        }

        return redirect()->back()->with('success', "Test email sent to {$recipient}.");
    }
}
