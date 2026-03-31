<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\NotificationTemplate;
use App\Services\TemplateRenderService;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class NotificationTemplateController extends Controller
{
    public function __construct(
        private TemplateRenderService $renderService,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeTemplates($request);

        return Inertia::render('settings/templates', [
            'templates' => NotificationTemplate::orderBy('category')->orderBy('name')->get(),
            'orgName' => AppSetting::where('key', 'branding.name')->value('value') ?? config('app.name'),
            'mergeFieldRegistry' => $this->renderService->getAvailableMergeFields(),
        ]);
    }

    public function update(Request $request, NotificationTemplate $template)
    {
        $this->authorizeTemplates($request);

        $validated = $request->validate([
            'subject' => 'nullable|string|max:500',
            'body' => 'required|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $template->update($validated);

        return redirect()->back()->with('success', 'Template updated successfully.');
    }

    public function preview(Request $request, NotificationTemplate $template)
    {
        $this->authorizeTemplates($request);

        $user = $request->user();

        $renderedBody = $this->renderService->render($template, $user);
        $renderedSubject = $this->renderService->renderSubject($template, $user);

        return response()->json([
            'html' => $renderedBody,
            'subject' => $renderedSubject,
        ]);
    }

    public function sendTest(Request $request, NotificationTemplate $template)
    {
        $this->authorizeTemplates($request);

        $user = $request->user();

        $renderedBody = $this->renderService->render($template, $user);
        $renderedSubject = $this->renderService->renderSubject($template, $user);

        Mail::raw($renderedBody, function ($message) use ($user, $renderedSubject) {
            $message->to($user->email)
                ->subject($renderedSubject ?: 'Test Notification');
        });

        return redirect()->back()->with('success', 'Test email sent to ' . $user->email);
    }

    public function reset(Request $request, NotificationTemplate $template)
    {
        $this->authorizeTemplates($request);

        if (!$template->is_system) {
            return redirect()->back()->with('error', 'Only system templates can be reset.');
        }

        $defaults = collect(NotificationTemplateSeeder::defaults())
            ->firstWhere('key', $template->key);

        if ($defaults) {
            $template->update([
                'subject' => $defaults['subject'],
                'body' => $defaults['body'],
            ]);
        }

        return redirect()->back()->with('success', 'Template reset to default.');
    }

    private function authorizeTemplates(Request $request): void
    {
        abort_unless($request->user()?->canDo('settings.templates.manage'), 403);
    }
}
