<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\MedicationAllergy;
use App\Models\MedicationRound;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

class EmarPdfController extends Controller
{
    /**
     * Generate a PDF MAR chart for a client over a date range.
     */
    public function marChart(Request $request)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $dateFrom = $request->input('date_from', Carbon::now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', Carbon::now()->endOfMonth()->toDateString());

        $client = Client::findOrFail($request->input('client_id'));

        $scheduledMedications = ClientMedication::where('client_id', $client->id)
            ->where('active', true)
            ->where('is_prn', false)
            ->with(['administrations' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('scheduled_for', [
                    Carbon::parse($dateFrom)->startOfDay(),
                    Carbon::parse($dateTo)->endOfDay(),
                ]);
            }])
            ->orderBy('name')
            ->get();

        $prnMedications = ClientMedication::where('client_id', $client->id)
            ->where('active', true)
            ->where('is_prn', true)
            ->with(['administrations' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('administered_at', [
                    Carbon::parse($dateFrom)->startOfDay(),
                    Carbon::parse($dateTo)->endOfDay(),
                ]);
            }])
            ->orderBy('name')
            ->get();

        $allergies = MedicationAllergy::where('client_id', $client->id)->get();

        $dates = collect(CarbonPeriod::create($dateFrom, $dateTo))->map(fn ($d) => $d->toDateString())->toArray();

        $pdf = Pdf::loadView('pdf.mar-chart', [
            'client' => $client,
            'scheduledMedications' => $scheduledMedications,
            'prnMedications' => $prnMedications,
            'allergies' => $allergies,
            'dates' => $dates,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);

        $pdf->setPaper('A4', 'landscape');

        return $pdf->download("mar-chart-{$client->last_name}.pdf");
    }

    /**
     * Generate a Controlled Drug Register PDF for a client.
     */
    public function controlledDrugRegister(Request $request)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $dateFrom = $request->input('date_from', Carbon::now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', Carbon::now()->endOfMonth()->toDateString());

        $client = Client::findOrFail($request->input('client_id'));

        $entries = ClientControlledDrugEntry::where('client_id', $client->id)
            ->whereBetween('recorded_at', [
                Carbon::parse($dateFrom)->startOfDay(),
                Carbon::parse($dateTo)->endOfDay(),
            ])
            ->with(['medication', 'recordedBy', 'witnessedBy'])
            ->orderBy('recorded_at')
            ->get();

        $pdf = Pdf::loadView('pdf.controlled-drug-register', [
            'client' => $client,
            'entries' => $entries,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);

        $pdf->setPaper('A4', 'landscape');

        return $pdf->download("cd-register-{$client->last_name}.pdf");
    }

    /**
     * Generate a printable Round Sheet for a given date.
     */
    public function roundSheet(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
        ]);

        $date = $request->input('date', Carbon::today()->toDateString());

        $rounds = MedicationRound::where('round_date', $date)
            ->with(['assignedTo', 'administrations.medication', 'administrations.client'])
            ->orderBy('scheduled_time')
            ->get();

        $pdf = Pdf::loadView('pdf.round-sheet', [
            'rounds' => $rounds,
            'date' => $date,
        ]);

        $pdf->setPaper('A4', 'portrait');

        return $pdf->download("round-sheet-{$date}.pdf");
    }
}
