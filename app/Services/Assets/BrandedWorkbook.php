<?php

namespace App\Services\Assets;

use ZipArchive;

/** Small, typed OOXML workbook. All user content is an inline string, never a formula. */
final class BrandedWorkbook
{
    public function bytes(array $sheets, array $brand, string $title): string
    {
        $path = tempnam(sys_get_temp_dir(), 'asset-xlsx-');
        $zip = new ZipArchive;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create workbook.');
            }
            $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $rels = 'http://schemas.openxmlformats.org/package/2006/relationships';
            $office = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $workbook = '<workbook xmlns="'.$ns.'" xmlns:r="'.$office.'"><sheets>';
            $relationships = '<Relationships xmlns="'.$rels.'"><Relationship Id="styles" Type="'.$office.'/styles" Target="styles.xml"/>';
            $logo = $brand['logo'] ?? null;
            $hasLogo = is_string($logo) && preg_match('#^data:image/(png|jpeg);base64,(.+)$#s', $logo, $image);
            if ($hasLogo) {
                $ext = $image[1] === 'jpeg' ? 'jpg' : 'png';
                $logoBytes = base64_decode($image[2]);
                $dimensions = getimagesizefromstring($logoBytes);
                $scale = $dimensions ? min(144 / $dimensions[0], 58 / $dimensions[1]) : 1;
                $logoWidth = (int) (($dimensions[0] ?? 144) * $scale * 9525);
                $logoHeight = (int) (($dimensions[1] ?? 58) * $scale * 9525);
                $zip->addFromString('xl/media/logo.'.$ext, $logoBytes);
                $types .= '<Default Extension="'.$ext.'" ContentType="image/'.$image[1].'"/>';
            }
            $i = 0;
            foreach ($sheets as $name => $rows) {
                $i++;
                $workbook .= '<sheet name="'.$this->xml($name).'" sheetId="'.$i.'" r:id="s'.$i.'"/>';
                $relationships .= '<Relationship Id="s'.$i.'" Type="'.$office.'/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
                $types .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $columns = max(2, count($rows[0]));
                $widths = $columns === 2 ? [28, 100] : array_fill(0, $columns, 25);
                if ($columns > 5) {
                    $widths[$columns - 1] = 90;
                }
                $xml = '<worksheet xmlns="'.$ns.'" xmlns:r="'.$office.'"><sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
                foreach ($widths as $column => $width) {
                    $xml .= '<col min="'.($column + 1).'" max="'.($column + 1).'" width="'.$width.'" customWidth="1"/>';
                }
                $xml .= '</cols><sheetData>';
                $all = [[$brand['name']], [$title], ['STOCKTAKE · '.$name], [], ...$rows];
                foreach ($all as $r => $row) {
                    $lines = 1;
                    foreach ($row as $c => $value) {
                        $lines = max($lines, (int) ceil(mb_strlen((string) $value) / max(10, ($widths[$c] ?? 25) - 4)) + substr_count((string) $value, "\n"));
                    }
                    $height = $r < 3 ? 25 : min(409, max(30, $lines * 16 + 8));
                    $xml .= '<row r="'.($r + 1).'" ht="'.$height.'" customHeight="1">';
                    foreach ($row as $c => $value) {
                        $style = $r < 3 ? 1 : ($r === 4 ? 2 : ($r % 2 ? 3 : 4));
                        $ref = $this->column($c).($r + 1);
                        $xml .= is_int($value) || is_float($value)
                            ? '<c r="'.$ref.'" s="'.$style.'"><v>'.$value.'</v></c>'
                            : '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml($value ?? '').'</t></is></c>';
                    }
                    $xml .= '</row>';
                }
                $xml .= '</sheetData><autoFilter ref="A5:'.$this->column($columns - 1).count($all).'"/><mergeCells count="3">';
                foreach ([1, 2, 3] as $r) {
                    $xml .= '<mergeCell ref="A'.$r.':'.$this->column(max(1, $columns - 3)).$r.'"/>';
                }
                $xml .= '</mergeCells><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>';
                if ($hasLogo) {
                    $xml .= '<drawing r:id="logo"/>';
                    $zip->addFromString('xl/worksheets/_rels/sheet'.$i.'.xml.rels', '<Relationships xmlns="'.$rels.'"><Relationship Id="logo" Type="'.$office.'/drawing" Target="../drawings/drawing'.$i.'.xml"/></Relationships>');
                    $zip->addFromString('xl/drawings/_rels/drawing'.$i.'.xml.rels', '<Relationships xmlns="'.$rels.'"><Relationship Id="image" Type="'.$office.'/image" Target="../media/logo.'.$ext.'"/></Relationships>');
                    $types .= '<Override PartName="/xl/drawings/drawing'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
                    $zip->addFromString('xl/drawings/drawing'.$i.'.xml', '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><xdr:oneCellAnchor><xdr:from><xdr:col>'.max(2, $columns - 2).'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:ext cx="'.$logoWidth.'" cy="'.$logoHeight.'"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="Company logo"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip xmlns:r="'.$office.'" r:embed="image"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>');
                }
                $zip->addFromString('xl/worksheets/sheet'.$i.'.xml', $xml.'</worksheet>');
            }
            $colour = ltrim($brand['colour'], '#');
            $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="'.$ns.'"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF'.$colour.'"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF4F1FA"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="5"><xf/><xf fontId="1" fillId="2" applyFill="1" applyFont="1"/><xf fontId="1" fillId="2" applyFill="1" applyFont="1"><alignment wrapText="1"/></xf><xf fillId="0"><alignment vertical="top" wrapText="1"/></xf><xf fillId="3" applyFill="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            $zip->addFromString('[Content_Types].xml', $types.'</Types>');
            $zip->addFromString('_rels/.rels', '<Relationships xmlns="'.$rels.'"><Relationship Id="workbook" Type="'.$office.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', $workbook.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $relationships.'</Relationships>');
            $zip->close();

            return file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function xml(mixed $value): string
    {
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $value) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function column(int $index): string
    {
        $name = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }
}
