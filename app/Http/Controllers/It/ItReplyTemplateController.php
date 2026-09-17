<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItReplyTemplateService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\SaveItReplyTemplateRequest;
use App\Models\ItReplyTemplate;
use App\Models\ItTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItReplyTemplateController extends Controller
{
    public function __construct(
        private readonly ItReplyTemplateService $templates,
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /** Active templates an agent may insert; internal templates need work access somewhere. */
    public function index(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->canDo('it.view'), 403);
        $templates = ItReplyTemplate::query()->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'audience', 'lock_version', 'updated_at'])
            ->map(fn (ItReplyTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'audience' => $template->audience,
                'lock_version' => (int) $template->lock_version,
                'updated_at' => $template->updated_at?->toIso8601String(),
            ]);

        return response()->json(['templates' => $templates, 'placeholders' => ItReplyTemplateService::PLACEHOLDERS])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Render a template for one ticket the actor can reply on. */
    public function render(Request $request, ItTicket $ticket, ItReplyTemplate $template): JsonResponse
    {
        $actor = $request->user();
        abort_unless((bool) $actor?->canDo('it.view'), 403);
        abort_unless($this->workAccess->applyViewScope(ItTicket::query(), $actor)->whereKey($ticket->id)->exists(), 404);
        $this->authorize('comment', $ticket);
        abort_unless($template->is_active, 404);
        if ($template->audience === 'internal') {
            abort_unless($this->workAccess->canWork($actor, $ticket), 403);
        }

        return response()->json([
            'body' => $this->templates->render($template, $ticket, $actor),
            'audience' => $template->audience,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(SaveItReplyTemplateRequest $request)
    {
        $this->templates->create($request->user(), $request->validated());

        return redirect()->back()->with('success', 'Reply template created.');
    }

    public function update(SaveItReplyTemplateRequest $request, ItReplyTemplate $template)
    {
        $this->templates->update($template, $request->user(), $request->validated());

        return redirect()->back()->with('success', 'Reply template updated.');
    }

    public function archive(Request $request, ItReplyTemplate $template)
    {
        abort_unless((bool) $request->user()?->canDo('it.manage'), 403);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:1'], 'active' => ['required', 'boolean']]);
        $this->templates->setActive($template, $request->user(), (bool) $data['active'], (int) $data['lock_version']);

        return redirect()->back()->with('success', $data['active'] ? 'Reply template restored.' : 'Reply template archived.');
    }
}
