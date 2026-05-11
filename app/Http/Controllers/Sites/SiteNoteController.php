<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteNote;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class SiteNoteController extends Controller
{
    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note = $site->siteNotes()->create([
            'body' => $data['body'],
            'created_by_user_id' => $request->user()?->id,
        ]);

        AuditLogger::log('site.note.create', $note, [
            'site_id' => $site->id,
        ]);

        return back()->with('success', 'Note added.');
    }

    public function destroy(Request $request, Site $site, SiteNote $note)
    {
        $this->authorize('update', $site);

        if ($note->site_id !== $site->id) {
            abort(404);
        }

        $note->delete();

        AuditLogger::log('site.note.delete', $note, [
            'site_id' => $site->id,
        ]);

        return back()->with('success', 'Note removed.');
    }
}
