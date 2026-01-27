<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class AssetQrController extends Controller
{
    public function redirectByToken(Request $request, string $token)
    {
        $asset = Asset::query()->where('qr_token', $token)->firstOrFail();
        $this->authorize('view', $asset);

        return redirect()->route('assets.show', $asset);
    }

    public function png(Request $request, Asset $asset)
    {
        $this->authorize('view', $asset);

        $url = route('assets.qr.redirect', ['token' => $asset->qr_token]);

        $builder = new Builder(
            writer: new PngWriter(),
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 512,
            margin: 10,
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

        $url = route('assets.qr.redirect', ['token' => $asset->qr_token]);

        $builder = new Builder(
            writer: new SvgWriter(),
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 512,
            margin: 10,
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
        $filename = 'asset-' . $safeIdentifier . '-qr.png';

        $res->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $res;
    }
}
