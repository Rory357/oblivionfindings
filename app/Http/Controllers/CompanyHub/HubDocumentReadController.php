<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubDocumentReads;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HubDocumentReadController extends Controller
{
    public function __invoke(Request $request, HubMedia $media, HubDocumentReads $reads)
    {
        $data = $request->validate([
            'actor_id' => 'required|integer|min:1|max:2147483647',
            'media_id' => 'required|integer|min:1|max:2147483647',
            'revision_id' => 'required|integer|min:1|max:2147483647',
            'user_id' => 'prohibited', 'edition' => 'prohibited', 'acknowledged' => 'prohibited',
        ]);

        return response()->json($reads->record($request->user(), (int) $media->id, $data))->header('Cache-Control', 'private, no-store');
    }
}
