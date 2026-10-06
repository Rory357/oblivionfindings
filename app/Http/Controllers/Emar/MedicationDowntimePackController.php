<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Medication\Downtime\DowntimeEvents;
use App\Services\Medication\Downtime\DowntimePackPdf;
use App\Services\Medication\Downtime\DowntimePackService;
use Illuminate\Http\Request;

class MedicationDowntimePackController extends Controller
{
    public function __construct(private readonly DowntimePackService $packs, private readonly DowntimePackPdf $pdf, private readonly DowntimeEvents $events) {}

    public function preview(Request $request)
    {
        $data = $request->validate(['site_id' => 'required|integer|min:1', 'nz_date' => 'required|date_format:Y-m-d']);
        $pack = $this->packs->build($request->user(), (int) $data['site_id'], $data['nz_date']);

        return response()->json([
            'site' => $pack['site'], 'nz_date' => $pack['nz_date'], 'first_page' => $pack['people'][0] ?? null,
            'controlled_notice' => $pack['controlled_notice'], 'purpose' => $pack['purpose'],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request)
    {
        $data = $request->validate(['site_id' => 'required|integer|min:1', 'nz_date' => 'required|date_format:Y-m-d']);
        $pack = $this->packs->build($request->user(), (int) $data['site_id'], $data['nz_date']);
        $binary = $this->pdf->render($pack);
        $this->packs->release($request->user(), $pack, fn (User $current) => $this->events->packMade($current, (int) $data['site_id'], $data['nz_date'], $pack['controlled_pages_included']));

        return response($binary)->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="downtime-pack-'.$data['site_id'].'-'.$data['nz_date'].'.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
