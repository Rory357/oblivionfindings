<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Screens\Devices\DeviceFormOptions;
use App\Domain\SecurityDevices\Screens\Sites\SiteLogbookExport;
use App\Domain\SecurityDevices\Screens\Sites\SitePagePresenter;
use App\Domain\SecurityDevices\Screens\Sites\SitesIndexPresenter;
use App\Domain\SecurityDevices\Screens\Sites\SiteStream;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Sites and one Site (S&D rebuild drawings pg-sdr-11 and pg-sdr-12). */
final class SitesController extends Controller
{
    public function __construct(
        private readonly SitesIndexPresenter $sites,
        private readonly SitePagePresenter $sitePage,
        private readonly DeviceFormOptions $formOptions,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);

        return Inertia::render('security-devices/sites/index', [
            ...$this->sites->present($user, $request->only(['tab', 'q', 'region'])),
            // Add a device and Scan a network load their choices only when one opens.
            'deviceForm' => Inertia::optional(fn (): array => $this->formOptions->present($user)),
        ]);
    }

    public function show(Request $request, int $site): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);
        $model = $this->sitePage->site($user, $site);

        return Inertia::render('security-devices/sites/show', [
            // what / day: the Logbook's "What" choice and "Go to" date (12i).
            ...$this->sitePage->present($user, $model, $request->only(['tab', 'what', 'day'])),
            'deviceForm' => Inertia::optional(fn (): array => $this->formOptions->present($user)),
        ]);
    }

    public function exportLogbook(Request $request, int $site, SiteLogbookExport $exporter): HttpResponse|StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view') && SiteStream::mayView($user), 403);
        $model = $this->sitePage->site($user, $site);

        $validated = $request->validate([
            'format' => ['required', 'string', Rule::in(['pdf', 'csv'])],
            'from' => ['required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}\z/'],
            'to' => ['nullable', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}\z/'],
            'what' => ['nullable', 'string', Rule::in(SitePagePresenter::LOGBOOK_WHAT)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $reason = trim((string) $validated['reason']);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'reason' => ['Give a short reason for exporting the logbook.'],
            ]);
        }

        $format = (string) $validated['format'];
        $what = is_string($validated['what'] ?? null) ? $validated['what'] : 'everything';
        $now = CarbonImmutable::now('UTC');
        $range = $exporter->parseRange((string) $validated['from'], is_string($validated['to'] ?? null) ? $validated['to'] : null, $now);
        $lines = $exporter->lines($user, $model, $range['start'], $range['end'], $what);

        AuditLogger::logOrFail('security_devices.logbook.exported', $model, [
            'actor_id' => (int) $user->id,
            'actor_name' => (string) $user->name,
            'site_id' => (int) $model->id,
            'site_name' => (string) $model->name,
            'format' => $format,
            'from' => $range['from_day'],
            'to' => $range['to_day'],
            'what' => $what,
            'rows' => count($lines),
            'reason' => $reason,
        ]);

        $slug = Str::slug((string) $model->name) ?: 'site-'.$model->id;
        $baseName = 'security-devices-logbook-'.$slug.'-'.$range['slug'];

        if ($format === 'pdf') {
            return response($exporter->pdfOutput($model, $range, $what, $reason, $lines, $user, $now), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$baseName.'.pdf"',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        return $this->streamSanitizedCsv($baseName.'.csv', SiteLogbookExport::CSV_COLUMNS, $exporter->csvRows($lines));
    }
}
