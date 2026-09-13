<?php

namespace App\Http\Requests\It\Concerns;

use App\Domain\It\Services\ItKbAccessService;
use App\Models\ItKbArticle;

trait ConcealsInaccessibleItKnowledge
{
    protected function canManageKnowledge(string $capability = ItKbAccessService::AUTHOR): bool
    {
        $actor = $this->user();
        if (! $actor?->canDo($capability)) {
            return false;
        }

        $article = $this->route('article');
        abort_unless($article instanceof ItKbArticle
            && app(ItKbAccessService::class)->canManage($actor, $article), 404);

        return true;
    }
}
