<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\Assets\AssetQrLabelService;
use App\Services\AuditLogger;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class AssetQrController extends Controller
{
    public function labels(Request $request, Asset $asset, AssetQrLabelService $labels)
    {
        $this->authorize('view', $asset);
        $options = $request->validate([
            'format' => ['required', 'in:a4,custom'], 'copies' => ['required', 'integer', 'min:1', 'max:180'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:17'], 'logo' => ['nullable', 'boolean'],
            'width' => ['required_if:format,custom', 'nullable', 'numeric', 'min:50', 'max:150'],
            'height' => ['required_if:format,custom', 'nullable', 'numeric', 'min:46', 'max:150'],
        ]);
        $bytes = $labels->pdf($asset, $options);
        AuditLogger::log('assets.qr.labels_exported', $asset, ['format' => $options['format'], 'copies' => $options['copies']]);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="asset-'.$asset->id.'-labels.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function redirectByToken(Request $request, string $token)
    {
        $asset = Asset::query()->where('qr_token', $token)->firstOrFail();
        $this->authorize('view', $asset);

        return redirect()->route('fleet-assets.assets.show', $asset);
    }

    public function png(Request $request, Asset $asset)
    {
        $this->authorize('view', $asset);
        abort_unless($asset->qr_token, 409, 'Generate this asset’s QR identity from its profile first.');

        $url = route('assets.qr.redirect', ['token' => $asset->qr_token]);

        $builder = new Builder(
            writer: new PngWriter,
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 512,
            margin: 64,
        );

        $result = $builder->build();

        return Response::make($result->getString(), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function svg(Request $request, Asset $asset)
    {
        $this->authorize('view', $asset);
        abort_unless($asset->qr_token, 409, 'Generate this asset’s QR identity from its profile first.');

        $url = route('assets.qr.redirect', ['token' => $asset->qr_token]);

        $builder = new Builder(
            writer: new SvgWriter,
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 512,
            margin: 64,
        );

        $result = $builder->build();

        return Response::make($result->getString(), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function downloadPng(Request $request, Asset $asset)
    {
        $this->authorize('view', $asset);

        $res = $this->png($request, $asset);

        // Sanitize filename to prevent path traversal attacks
        $identifier = $asset->asset_tag ?: $asset->id;
        $safeIdentifier = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);
        $filename = 'asset-'.$safeIdentifier.'-qr.png';

        $res->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $res;
    }
}
