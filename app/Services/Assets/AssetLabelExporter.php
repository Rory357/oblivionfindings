<?php

namespace App\Services\Assets;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Services\Fleet\VehicleTripReportExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use ZipArchive;

final class AssetLabelExporter
{
    use SanitizesCsvOutput;

    public function bytes($assets, array $layout, string $format): string
    {
        $brand = app(VehicleTripReportExporter::class)->branding();
        $labels = [];
        $manifest = [];
        foreach ($assets as $asset) {
            $url = route('assets.qr.redirect', ['token' => $asset->qr_token]);
            $png = (new Builder(writer: new PngWriter, data: $url, encoding: new Encoding('UTF-8'), errorCorrectionLevel: ErrorCorrectionLevel::High, size: 512, margin: 64))->build()->getString();
            $labels[] = ['name' => $asset->name, 'tag' => $asset->asset_tag ?: 'AS-'.$asset->id, 'png' => 'data:image/png;base64,'.base64_encode($png)];
            $manifest[] = ['id' => $asset->id, 'asset_tag' => $asset->asset_tag, 'name' => $asset->name, 'qr_url' => $url];
        }
        if ($format === 'pdf') {
            $slots = array_fill(0, $layout['start'] - 1, null);
            foreach ($labels as $label) {
                for ($n = 0; $n < $layout['copies']; $n++) {
                    $slots[] = $label;
                }
            }

            return Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true])
                ->loadView('pdf.asset-labels', ['pages' => array_chunk($slots, $layout['columns'] * $layout['rows']), 'layout' => $layout, 'brand' => $brand])
                ->setPaper('a4')->output();
        }
        $path = tempnam(sys_get_temp_dir(), 'asset-labels-');
        $zip = new ZipArchive;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create label archive.');
            }
            foreach ($manifest as $i => $row) {
                $stem = 'asset-'.$row['id'];
                $zip->addFromString($stem.'.png', $this->labelPng(base64_decode(explode(',', $labels[$i]['png'], 2)[1]), $row, $brand));
                $svg = (new Builder(writer: new SvgWriter, data: $row['qr_url'], encoding: new Encoding('UTF-8'), errorCorrectionLevel: ErrorCorrectionLevel::High, size: 512, margin: 64))->build()->getString();
                $document = new \DOMDocument;
                $document->loadXML($svg, LIBXML_NONET);
                $root = $document->documentElement;
                foreach (['x' => '0', 'y' => '65', 'width' => '512', 'height' => '512'] as $attribute => $value) {
                    $root->setAttribute($attribute, $value);
                }
                $inner = $document->saveXML($root);
                $identity = htmlspecialchars($row['asset_tag'] ?: 'AS-'.$row['id'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $name = htmlspecialchars(mb_strimwidth($row['name'], 0, 48, '…'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $branding = $brand['logo']
                    ? '<image x="176" y="8" width="160" height="48" href="'.$brand['logo'].'" preserveAspectRatio="xMidYMid meet"/>'
                    : '<text x="256" y="38" text-anchor="middle" font-family="sans-serif" font-size="20">'.htmlspecialchars(mb_strimwidth($brand['name'], 0, 42, '…'), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</text>';
                $zip->addFromString($stem.'.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="660" viewBox="0 0 512 660"><rect width="512" height="660" fill="white"/>'.$branding.$inner.'<text x="256" y="611" text-anchor="middle" font-family="sans-serif" font-size="22" font-weight="bold">'.$identity.'</text><text x="256" y="643" text-anchor="middle" font-family="sans-serif" font-size="17">'.$name.'</text></svg>');
            }
            $zip->addFromString('manifest.json', json_encode(['generated_at' => now()->toISOString(), 'assets' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $stream = fopen('php://temp', 'w+');
            fputcsv($stream, ['Asset ID', 'Tag', 'Name', 'QR URL'], ',', '"', '');
            foreach ($manifest as $row) {
                fputcsv($stream, array_map(fn ($v) => $this->sanitizeCsvCell((string) $v), array_values($row)), ',', '"', '');
            }
            rewind($stream);
            $zip->addFromString('manifest.csv', stream_get_contents($stream));
            fclose($stream);
            $zip->close();

            return file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function labelPng(string $qr, array $row, array $brand): string
    {
        $canvas = imagecreatetruecolor(512, 660);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $black = imagecolorallocate($canvas, 25, 25, 25);
        imagefill($canvas, 0, 0, $white);
        $code = imagecreatefromstring($qr);
        imagecopyresampled($canvas, $code, 0, 65, 0, 0, 512, 512, imagesx($code), imagesy($code));
        imagedestroy($code);
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $text = function (string $value, int $size, int $y) use ($canvas, $font, $black): void {
            $value = mb_strimwidth($value, 0, $size > 16 ? 36 : 48, '…');
            $box = imagettfbbox($size, 0, $font, $value);
            imagettftext($canvas, $size, 0, (int) ((512 - ($box[2] - $box[0])) / 2), $y, $black, $font, $value);
        };
        if ($brand['logo']) {
            $logo = imagecreatefromstring(base64_decode(explode(',', $brand['logo'], 2)[1]));
            $scale = min(160 / imagesx($logo), 48 / imagesy($logo));
            $width = (int) (imagesx($logo) * $scale);
            $height = (int) (imagesy($logo) * $scale);
            imagecopyresampled($canvas, $logo, (int) ((512 - $width) / 2), 8, 0, 0, $width, $height, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
        } else {
            $text($brand['name'], 15, 38);
        }
        $text($row['asset_tag'] ?: 'AS-'.$row['id'], 17, 611);
        $text($row['name'], 13, 643);
        ob_start();
        imagepng($canvas);
        $bytes = ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }
}
