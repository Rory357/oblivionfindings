<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ServiceContext;
use App\Models\AppSetting;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceContextController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.service_contexts.manage'), 403);

        $contexts = ServiceContext::query()
            ->with(['site:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'type', 'name', 'description', 'site_id', 'is_active', 'created_at', 'updated_at']);

        $sites = Site::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);


        $defaultContextId = ServiceContext::defaultId();
        $defaultContext = $defaultContextId
            ? ServiceContext::query()->whereKey($defaultContextId)->first(['id', 'name', 'is_active'])
            : null;

        return inertia('settings/service-contexts', [
            'contexts' => $contexts->map(fn($c) => [
                'id' => $c->id,
                'type' => $c->type?->value,
                'name' => $c->name,
                'description' => $c->description,
                'site' => $c->site ? ['id' => $c->site->id, 'name' => $c->site->name] : null,
                'site_id' => $c->site_id,
                'is_active' => (bool) $c->is_active,
            ]),
            'types' => config('service_context.types'),
            'sites' => $sites,
            'defaultContextId' => $defaultContext?->id,
            'defaultContextName' => $defaultContext?->name,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.service_contexts.manage'), 403);

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(collect(config('service_context.types'))->pluck('code')->all())],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'is_active' => ['boolean'],
        ]);

        ServiceContext::create($data);

        return back()->with('success', 'Service context created.');
    }


    public function setDefault(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.service_contexts.manage'), 403);

        $data = $request->validate([
            'default_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
        ]);

        $id = $data['default_id'] ?? null;

        // Only allow setting an ACTIVE context as the default (prevents silent inheritance of retired contexts).
        if ($id !== null) {
            $isActive = ServiceContext::query()->whereKey($id)->where('is_active', true)->exists();
            if (!$isActive) {
                return back()->with('error', 'Default service context must be active.');
            }
            AppSetting::updateOrCreate(['key' => 'service_context.default_id'], ['value' => (int) $id]);
        } else {
            AppSetting::query()->where('key', 'service_context.default_id')->delete();
        }

        return back()->with('success', 'Default service context updated.');
    }


    public function update(Request $request, ServiceContext $serviceContext)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.service_contexts.manage'), 403);

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(collect(config('service_context.types'))->pluck('code')->all())],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'is_active' => ['boolean'],
        ]);

        $serviceContext->update($data);

        return back()->with('success', 'Service context updated.');
    }
}
