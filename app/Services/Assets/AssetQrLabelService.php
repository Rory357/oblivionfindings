<?php

namespace App\Services\Assets;

use App\Models\AppSetting;
use App\Models\Asset;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;

final class AssetQrLabelService
{
    /** Authenticated asset links only; the printed QR contains no staff or client details. */
    public function pdf(Asset $asset, array $options): string
    {
        abort_unless($asset->qr_token, 409, 'This asset needs a QR identity before a label can be generated.');
        $qr = (new Builder(writer: new PngWriter, data: route('assets.qr.redirect', ['token' => $asset->qr_token]), encoding: new Encoding('UTF-8'), errorCorrectionLevel: ErrorCorrectionLevel::High, size: 600, margin: 80))->build();
        $brand = $this->branding((bool) ($options['logo'] ?? true));
        $sheet = ($options['format'] ?? 'a4') === 'a4';
        $width = $sheet ? 63.5 : (float) $options['width'];
        $height = $sheet ? 46.6 : (float) $options['height'];
        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true])->loadView('assets.qr-labels', [
            'asset' => $asset, 'organisation' => $brand['name'], 'logo' => $brand['logo'], 'qr' => 'data:image/png;base64,'.base64_encode($qr->getString()),
            'sheet' => $sheet, 'width' => $width, 'height' => $height,
            'copies' => (int) ($options['copies'] ?? 1), 'offset' => $sheet ? (int) ($options['offset'] ?? 0) : 0,
        ]);
        $pdf->setPaper($sheet ? 'a4' : [0, 0, $width * 72 / 25.4, $height * 72 / 25.4]);

        return $pdf->output();
    }

    public function branding(bool $includeLogo = true): array
    {
        $settings = AppSetting::whereIn('key', ['branding.name', 'branding.logo_path'])->pluck('value', 'key');
        $logo = null;
        $path = $settings['branding.logo_path'] ?? null;
        if ($includeLogo && is_string($path) && $path !== '' && ! str_contains($path, '..') && ! str_starts_with($path, '/')) {
            $disk = Storage::disk('public');
            if ($disk->exists($path) && $disk->size($path) <= 2 * 1024 * 1024) {
                $bytes = $disk->get($path);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                if (in_array($mime, ['image/png', 'image/jpeg'], true)) {
                    $logo = 'data:'.$mime.';base64,'.base64_encode($bytes);
                } elseif (in_array($mime, ['image/gif', 'image/webp'], true) && function_exists('imagecreatefromstring')) {
                    $size = @getimagesizefromstring($bytes);
                    if ($size && $size[0] * $size[1] <= 8000000) {
                        $image = @imagecreatefromstring($bytes);
                        if ($image) {
                            ob_start();
                            imagepng($image);
                            $png = ob_get_clean();
                            imagedestroy($image);
                            if ($png) {
                                $logo = 'data:image/png;base64,'.base64_encode($png);
                            }
                        }
                    }
                }
            }
        }

        return ['name' => is_string($settings['branding.name'] ?? null) ? $settings['branding.name'] : config('app.name'), 'logo' => $logo];
    }
}
