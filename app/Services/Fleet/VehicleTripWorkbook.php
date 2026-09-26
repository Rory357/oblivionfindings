<?php

namespace App\Services\Fleet;

use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

/** Native Excel workbook; only already-authorised report data enters this writer. */
final class VehicleTripWorkbook
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @param array<string,mixed> $data */
    public function bytes(array $data): string
    {
        $sheets = [];
        $intro = [[$data['brand']['name'].' · Trip report'], [$data['vehicle_line']],
            [$data['range_label'].' · '.$data['timezone']], [$data['filters_line']], [$data['scope_note']]];
        $rows = [...$intro, [], ['Trip', 'Date', 'Start', 'End', 'Driver', 'Driver status', 'From', 'To',
            'Distance (km)', 'Duration (min)', 'Max speed (km/h)', 'Coverage (%)', 'Driving events', 'Behaviour score', 'Source']];
        foreach ($data['trips'] as $trip) {
            $rows[] = [$trip['reference'], $trip['date_iso'], $trip['start_time'], $trip['end_time'],
                $trip['driver_name'], $trip['driver_status'], $trip['from'], $trip['to'], $trip['distance_km'],
                $trip['duration_seconds'] / 60, $trip['max_speed_kph'], $trip['coverage_pct'], $trip['driving_events'],
                $trip['score'] ?? $trip['score_label'], $trip['source_note']];
        }
        $lastTripRow = count($rows);
        $rows[] = ['Total', '', '', '', '', '', '', '',
            ['formula' => 'SUM(I8:I'.$lastTripRow.')', 'value' => $data['totals']['distance_km']],
            ['formula' => 'SUM(J8:J'.$lastTripRow.')', 'value' => $data['totals']['duration_seconds'] / 60]];
        $sheets[] = ['name' => 'Trips', 'rows' => $rows, 'header' => 7, 'columns' => 15, 'last' => $lastTripRow,
            'date_column' => 1, 'duration_column' => 9, 'images' => []];
        if ($data['include_events']) {
            $rows = [...$intro, [], ['Trip', 'Date', 'Time', 'Event', 'Details', 'Location']];
            foreach ($data['trips'] as $trip) {
                foreach ($trip['events'] as $event) {
                    $rows[] = [$trip['reference'], $trip['date_iso'], $event['time'], $event['title'], $event['detail'], $event['location']];
                }
            }
            $sheets[] = ['name' => 'Events', 'rows' => $rows, 'header' => 7, 'columns' => 6, 'last' => count($rows),
                'date_column' => 1, 'images' => []];
        }
        $rows = [[$data['brand']['name'].' · Report notes'], [$data['vehicle_line']], [$data['generated_label']], [],
            ['Duration retains recorded seconds as fractional minutes. Cells display two decimals; totals use the unrounded values.'],
            ...array_map(fn (string $note): array => [$note], $data['notes'])];
        $sheets[] = ['name' => 'Report notes', 'rows' => $rows, 'header' => 0, 'columns' => 8, 'last' => 0,
            'date_column' => null, 'images' => []];
        if ($data['include_routes']) {
            $rows = [[$data['brand']['name'].' · Recorded journeys'], [$data['vehicle_line']], [$data['range_label']],
                ['Recorded positions over the available local map. Lines are not verified roads; gaps remain unknown.'], []];
            $images = [];
            foreach ($data['trips'] as $trip) {
                $rows[] = [$trip['reference'].' · '.$trip['date_label'].' · '.$trip['start_time'].'–'.$trip['end_time']];
                $rows[] = [$trip['from'].' → '.$trip['to']];
                $rows[] = [$trip['map_note'] ?? 'Recorded-position sketch only.'];
                if ($trip['route']) {
                    $images[] = ['bytes' => $this->routePng($trip['route']), 'row' => count($rows), 'col' => 0,
                        'width' => 780, 'height' => 330, 'extension' => 'png'];
                    for ($line = 0; $line < 19; $line++) {
                        $rows[] = [];
                    }
                } else {
                    $rows[] = ['Fewer than two recorded positions; no route can be drawn.'];
                }
                $rows[] = [$trip['source_note'].($trip['partial'] ? ' · Partial coverage' : '')];
                $rows[] = [];
            }
            $sheets[] = ['name' => ($data['has_street_maps'] ?? false) ? 'Journey maps' : 'Journey sketches', 'rows' => $rows, 'header' => 0, 'columns' => 8, 'last' => 0,
                'date_column' => null, 'images' => $images];
        }
        // Branding is embedded, never an external image link or formula.
        if (preg_match('~^data:image/(png|jpeg);base64,(.+)$~s', (string) $data['brand']['logo'], $logo)) {
            $bytes = base64_decode($logo[2], true);
            $size = $bytes !== false ? getimagesizefromstring($bytes) : false;
            if ($size) {
                $sheets[0]['images'][] = ['bytes' => $bytes, 'row' => 0, 'col' => 15, 'width' => 120,
                    'height' => min(100, 120 * $size[1] / $size[0]), 'extension' => $logo[1] === 'jpeg' ? 'jpg' : 'png'];
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'vehicle-report-');
        if ($path === false) {
            throw new RuntimeException('Could not prepare the workbook.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the workbook.');
            }
            $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpg" ContentType="image/jpeg"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $workbook = '<workbook xmlns="'.self::NS.'" xmlns:r="'.self::REL.'"><sheets>';
            $relationships = $this->relationship('styles', 'styles', 'styles.xml');
            foreach ($sheets as $index => $sheet) {
                $id = $index + 1;
                $workbook .= '<sheet name="'.$this->xml($sheet['name']).'" sheetId="'.$id.'" r:id="sheet'.$id.'"/>';
                $relationships .= $this->relationship('sheet'.$id, 'worksheet', 'worksheets/sheet'.$id.'.xml');
                $types .= '<Override PartName="/xl/worksheets/sheet'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $zip->addFromString('xl/worksheets/sheet'.$id.'.xml', $this->sheet($sheet));
                if ($sheet['images'] !== []) {
                    $types .= '<Override PartName="/xl/drawings/drawing'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
                    $zip->addFromString('xl/worksheets/_rels/sheet'.$id.'.xml.rels', $this->relationships($this->relationship('drawing', 'drawing', '../drawings/drawing'.$id.'.xml')));
                    $drawing = '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="'.self::REL.'">';
                    $imageRels = '';
                    foreach ($sheet['images'] as $imageIndex => $image) {
                        $imageId = $imageIndex + 1;
                        $name = 'image'.$id.'-'.$imageId.'.'.$image['extension'];
                        $zip->addFromString('xl/media/'.$name, $image['bytes']);
                        $imageRels .= $this->relationship('image'.$imageId, 'image', '../media/'.$name);
                        $extent = 'cx="'.(int) ($image['width'] * 9525).'" cy="'.(int) ($image['height'] * 9525).'"';
                        $drawing .= '<xdr:oneCellAnchor><xdr:from><xdr:col>'.$image['col'].'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$image['row'].'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:ext '.$extent.'/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="'.$imageId.'" name="'.$name.'"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="image'.$imageId.'"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext '.$extent.'/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
                    }
                    $zip->addFromString('xl/drawings/drawing'.$id.'.xml', $drawing.'</xdr:wsDr>');
                    $zip->addFromString('xl/drawings/_rels/drawing'.$id.'.xml.rels', $this->relationships($imageRels));
                }
            }
            $zip->addFromString('[Content_Types].xml', $types.'</Types>');
            $zip->addFromString('_rels/.rels', $this->relationships($this->relationship('workbook', 'officeDocument', 'xl/workbook.xml')));
            $zip->addFromString('xl/workbook.xml', $workbook.'</sheets><calcPr fullCalcOnLoad="1"/></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relationships($relationships));
            $zip->addFromString('xl/styles.xml', $this->styles($data['brand']['colour']));
            if (! $zip->close()) {
                throw new RuntimeException('Could not finish the workbook.');
            }

            return file_get_contents($path) ?: throw new RuntimeException('Could not read the workbook.');
        } finally {
            unlink($path);
        }
    }

    private function sheet(array $sheet): string
    {
        $end = $this->column($sheet['columns'] - 1);
        $xml = '<worksheet xmlns="'.self::NS.'" xmlns:r="'.self::REL.'"><sheetViews><sheetView workbookViewId="0" showGridLines="0">'
            .($sheet['header'] ? '<pane ySplit="7" topLeftCell="A8" activePane="bottomLeft" state="frozen"/>' : '').'</sheetView></sheetViews><cols>';
        for ($i = 1; $i <= $sheet['columns']; $i++) {
            $width = in_array($i, [5, 6, 7, 8, 15]) ? 32 : 18;
            $xml .= '<col min="'.$i.'" max="'.$i.'" width="'.$width.'" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';
        $merges = [];
        foreach ($sheet['rows'] as $index => $values) {
            $row = $index + 1;
            // Excel does not auto-fit fixed-height rows or merged cells on open.
            $height = $row === 1 ? 32 : ($row === $sheet['header'] ? 36 : 24);
            if ($sheet['header'] && $row > $sheet['header']) {
                $height = 60;
            } elseif (count($values) === 1 && mb_strlen((string) $values[0]) > 150) {
                $height = 48;
            }
            $xml .= '<row r="'.$row.'" ht="'.$height.'" customHeight="1">';
            foreach ($values as $col => $value) {
                $ref = $this->column($col).$row;
                $style = $row === 1 ? 1 : ($row === $sheet['header'] ? 2 : 0);
                if ($sheet['date_column'] === $col && $row > $sheet['header'] && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    $value = (int) CarbonImmutable::parse('1899-12-30', 'UTC')->diffInDays(CarbonImmutable::parse($value, 'UTC'));
                    $style = 3;
                }
                if (($sheet['duration_column'] ?? null) === $col && $row > $sheet['header']) {
                    $style = 4;
                }
                $xml .= '<c r="'.$ref.'" s="'.$style.'"';
                if (is_array($value)) {
                    $xml .= '><f>'.$value['formula'].'</f><v>'.$value['value'].'</v></c>';
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= '><v>'.$value.'</v></c>';
                } else {
                    // Text is an inline string; no user value is ever interpreted as a formula.
                    $xml .= ' t="inlineStr"><is><t xml:space="preserve">'.$this->xml($value ?? '').'</t></is></c>';
                }
            }
            $xml .= '</row>';
            if (count($values) === 1) {
                $merges[] = 'A'.$row.':'.$end.$row;
            }
        }
        $xml .= '</sheetData>';
        if ($sheet['header'] && $sheet['last'] > $sheet['header']) {
            $xml .= '<autoFilter ref="A7:'.$end.$sheet['last'].'"/>';
        }
        if ($merges !== []) {
            $xml .= '<mergeCells count="'.count($merges).'">'.implode('', array_map(fn ($ref) => '<mergeCell ref="'.$ref.'"/>', $merges)).'</mergeCells>';
        }
        $xml .= '<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>';

        return $xml.($sheet['images'] !== [] ? '<drawing r:id="drawing"/>' : '').'</worksheet>';
    }

    /** Rasterise only the simple SVG generated by VehicleTripReportExporter; no external assets. */
    private function routePng(string $uri): string
    {
        if (str_starts_with($uri, 'data:image/png;base64,')) {
            $png = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
            if ($png === false || ! str_starts_with($png, "\x89PNG")) {
                throw new RuntimeException('The local street map could not be embedded.');
            }

            return $png;
        }
        $svg = simplexml_load_string(base64_decode(substr($uri, strpos($uri, ',') + 1)), options: LIBXML_NONET);
        if ($svg === false) {
            throw new RuntimeException('The recorded route could not be rendered.');
        }
        $image = imagecreatetruecolor(520, 220);
        $colour = fn (string $hex): int => imagecolorallocate($image, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
        imagefill($image, 0, 0, $colour('#f3f3f5'));
        imagesetthickness($image, 3);
        $points = array_map(fn (string $point): array => array_map('floatval', explode(',', $point)), explode(' ', (string) $svg->polyline['points']));
        $stroke = $colour((string) $svg->polyline['stroke']);
        if (isset($svg->polyline['stroke-dasharray'])) {
            imagesetstyle($image, [...array_fill(0, 8, $stroke), ...array_fill(0, 6, IMG_COLOR_TRANSPARENT)]);
            $stroke = IMG_COLOR_STYLED;
        }
        for ($i = 1; $i < count($points); $i++) {
            imageline($image, (int) $points[$i - 1][0], (int) $points[$i - 1][1], (int) $points[$i][0], (int) $points[$i][1], $stroke);
        }
        foreach ($svg->circle as $circle) {
            imagefilledellipse($image, (int) $circle['cx'], (int) $circle['cy'], 16, 16, $colour((string) $circle['fill']));
        }
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    private function styles(string $colour): string
    {
        return '<styleSheet xmlns="'.self::NS.'"><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="16"/><color rgb="FF'.substr($colour, 1).'"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF'.substr($colour, 1).'"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1"/></xf><xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    private function xml(mixed $value): string
    {
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $value) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function relationship(string $id, string $type, string $target): string
    {
        return '<Relationship Id="'.$id.'" Type="'.self::REL.'/'.$type.'" Target="'.$target.'"/>';
    }

    private function relationships(string $items): string
    {
        return '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$items.'</Relationships>';
    }
}
