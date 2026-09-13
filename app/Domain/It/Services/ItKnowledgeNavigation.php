<?php

namespace App\Domain\It\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class ItKnowledgeNavigation
{
    public function context(Request $request): array
    {
        $data = $request->validate(['library' => ['nullable', 'string', 'max:2000']]);
        parse_str($data['library'] ?? '', $context);

        return array_filter(Arr::only($context, ['q', 'category', 'status', 'document_type', 'tag', 'review', 'owner', 'sort', 'dir', 'page', 'related_type', 'related_id', 'view', 'list_view']), fn ($value) => is_string($value));
    }

    public function libraryHref(array $context): string
    {
        return '/it/knowledge'.($context ? '?'.http_build_query($context) : '');
    }

    public function documentHref(int $id, array $context, ?string $section = null): string
    {
        $query = $context ? ['library' => http_build_query($context)] : [];
        if ($section) {
            $query['section'] = $section;
        }

        return '/it/knowledge/'.$id.($query ? '?'.http_build_query($query) : '');
    }
}
