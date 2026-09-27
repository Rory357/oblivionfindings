<?php

namespace App\Services\Maps;

use GdImage;
use RuntimeException;
use SQLite3;

/** Offline street maps. Takes only points already cleared by the owning report's privacy checks. */
final class OsmReportMap
{
    private const WIDTH = 1040;

    private const HEIGHT = 440;

    /** @param list<array{lat:float,lng:float}> $points */
    public function render(array $points, string $colour, bool $partial, ?string $directory = null): array
    {
        $missing = ['image' => null, 'kind' => 'recorded_position_sketch',
            'note' => 'Street map unavailable: local map data is not installed. Recorded-position sketch only.', 'dataset' => null];
        if (count($points) < 2) {
            return [...$missing, 'note' => 'Fewer than two recorded positions.'];
        }
        $directory ??= (string) config('report_maps.directory');
        if (! is_file($directory.'/active.json')) {
            return $missing;
        }
        $db = null;
        $image = null;
        try {
            $metadata = json_decode(file_get_contents($directory.'/active.json'), true, 16, JSON_THROW_ON_ERROR);
            if (($metadata['schema'] ?? null) !== 1 || ! preg_match('/^osm-[a-f0-9]{20}\.sqlite$/', $metadata['file'] ?? '')) {
                throw new RuntimeException('Invalid installed map metadata.');
            }
            $db = new SQLite3($directory.'/'.$metadata['file'], SQLITE3_OPEN_READONLY);
            $db->enableExceptions(true);
            $db->exec('PRAGMA trusted_schema=OFF');
            $db->exec('PRAGMA query_only=ON');
            $stored = json_decode($db->querySingle('SELECT json FROM metadata'), true, 16, JSON_THROW_ON_ERROR);
            if ($stored !== $metadata) {
                throw new RuntimeException('Map dataset metadata does not match.');
            }
            $viewport = $this->viewport($points);
            $roads = $this->features($db, 'roads', $viewport['bounds'], $viewport['wide']);
            if ($roads === []) {
                return [...$missing, 'note' => 'Street map unavailable for this area in the installed dataset. Recorded-position sketch only.'];
            }
            $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
            // Render at twice report display resolution. GD antialiasing ignores thick lines.
            imageantialias($image, false);
            $background = $this->colour($image, '#f2f3f4');
            imagefill($image, 0, 0, $background);
            $decoder = new GeoPackageGeometry;
            $budget = 100_000;
            if (! $viewport['wide']) {
                foreach ($this->features($db, 'water_a', $viewport['bounds'], false) as $feature) {
                    foreach ($decoder->decode($feature['geometry'], $metadata['srs_id']) as $shape) {
                        if ($shape['type'] !== 'polygon') {
                            continue;
                        }
                        foreach ($shape['coordinates'] as $ringIndex => $ring) {
                            $budget -= count($ring);
                            if ($budget < 0) {
                                throw new RuntimeException('Map detail exceeds the rendering limit.');
                            }
                            $ring = array_map($viewport['pixel'], $ring);
                            if (count($ring) >= 3) {
                                imagefilledpolygon($image, array_merge(...$ring), $ringIndex === 0 ? $this->colour($image, '#dce0e3') : $background);
                            }
                        }
                    }
                }
            }
            $lines = [];
            foreach ($roads as $feature) {
                foreach ($decoder->decode($feature['geometry'], $metadata['srs_id']) as $shape) {
                    if ($shape['type'] !== 'line') {
                        continue;
                    }
                    $budget -= count($shape['coordinates']);
                    if ($budget < 0) {
                        throw new RuntimeException('Map detail exceeds the rendering limit.');
                    }
                    $lines[] = ['points' => array_map($viewport['pixel'], $shape['coordinates']),
                        'name' => $feature['name'], 'major' => in_array($feature['class'], ['motorway', 'trunk', 'primary', 'secondary'], true),
                        'minor' => in_array($feature['class'], ['footway', 'path', 'steps', 'cycleway', 'bridleway', 'track', 'service'], true)];
                }
            }
            foreach ([true, false] as $casing) {
                foreach ($lines as $line) {
                    if ($line['minor']) {
                        if ($casing) {
                            $this->line($image, $line['points'], $this->colour($image, '#d8dcdf'), 1);
                        }

                        continue;
                    }
                    $this->line($image, $line['points'], $this->colour($image, $casing ? '#c6cbcf' : '#ffffff'),
                        ($line['major'] ? 7 : 4) - ($casing ? 0 : 2));
                }
            }
            $route = array_map(fn ($p) => $viewport['pixel']([(float) $p['lng'], (float) $p['lat']]), $points);
            $this->line($image, $route, $this->colour($image, '#ffffff'), 10);
            $stroke = $this->colour($image, $colour);
            if ($partial) {
                imagesetstyle($image, [...array_fill(0, 14, $stroke), ...array_fill(0, 10, IMG_COLOR_TRANSPARENT)]);
                $stroke = IMG_COLOR_STYLED;
            }
            $this->line($image, $route, $stroke, 6);
            $occupied = [];
            foreach ([[$route[0], '#15803d', 'A'], [$route[count($route) - 1], '#b91c1c', 'B']] as [$point, $fill, $letter]) {
                imagefilledellipse($image, $point[0], $point[1], 29, 29, $this->colour($image, '#ffffff'));
                imagefilledellipse($image, $point[0], $point[1], 23, 23, $this->colour($image, $fill));
                $this->text($image, $letter, $point[0] - 5, $point[1] + 5, 12, '#ffffff');
                $occupied[] = [$point[0] - 22, $point[1] - 22, $point[0] + 22, $point[1] + 22];
            }
            $names = [];
            foreach ($this->features($db, 'places', $viewport['bounds'], $viewport['wide']) as $feature) {
                if (! in_array($feature['class'], ['city', 'town', 'suburb', 'village', 'national_capital'], true)) {
                    continue;
                }
                foreach ($decoder->decode($feature['geometry'], $metadata['srs_id']) as $shape) {
                    if ($shape['type'] === 'point') {
                        $this->label($image, $feature['name'], $viewport['pixel']($shape['coordinates']), 15, $occupied, $names);
                    }
                }
            }
            usort($lines, fn ($a, $b) => (int) $b['major'] <=> (int) $a['major']);
            foreach ($lines as $line) {
                $visible = array_values(array_filter($line['points'], fn ($p) => $p[0] > 20 && $p[0] < self::WIDTH - 20 && $p[1] > 20 && $p[1] < self::HEIGHT - 50));
                if (! $line['minor'] && count($visible) >= 2 && count($names) < 24) {
                    $this->label($image, $line['name'], $visible[intdiv(count($visible), 2)], 11, $occupied, $names);
                }
            }
            imagefilledrectangle($image, 0, self::HEIGHT - 25, self::WIDTH, self::HEIGHT, $this->colour($image, '#ffffff'));
            $this->text($image, '© OpenStreetMap contributors · ODbL · openstreetmap.org/copyright', 12, self::HEIGHT - 8, 10, '#475569');
            $this->text($image, 'N ↑', self::WIDTH - 38, 24, 13, '#334155');
            ob_start();
            imagepng($image);
            $png = ob_get_clean();

            return ['image' => 'data:image/png;base64,'.base64_encode($png), 'kind' => 'local_osm_street_map',
                'note' => 'Local street map · '.$metadata['label'].' · data '.$metadata['date'].'. '.OsmReportDataset::ATTRIBUTION,
                'dataset' => ['label' => $metadata['label'], 'date' => $metadata['date'], 'sha256' => $metadata['source_sha256']]];
        } catch (\Throwable) {
            // Never substitute an external provider or disclose a local filesystem path.
            return [...$missing, 'note' => 'Street map unavailable: installed map data could not render this area. Recorded-position sketch only.'];
        } finally {
            $db?->close();
            if ($image instanceof GdImage) {
                imagedestroy($image);
            }
        }
    }

    private function features(SQLite3 $db, string $kind, array $bounds, bool $wide): array
    {
        $classes = $wide ? ($kind === 'roads'
            ? " AND f.class IN ('motorway','motorway_link','trunk','trunk_link','primary','primary_link','secondary')"
            : " AND f.class IN ('city','town','national_capital')") : '';
        $statement = $db->prepare('SELECT f.* FROM features f JOIN bounds b ON b.id=f.id WHERE f.id IN ('
            .'SELECT feature_id FROM cells WHERE x BETWEEN :cell_west AND :cell_east AND y BETWEEN :cell_south AND :cell_north '
            .'UNION SELECT feature_id FROM cells WHERE x=2147483647) '
            .'AND b.max_x>=:west AND b.min_x<=:east AND b.max_y>=:south AND b.min_y<=:north AND f.kind=:kind'.$classes.' LIMIT 15001');
        foreach ($bounds as $key => $value) {
            $statement->bindValue(':'.$key, $value, SQLITE3_FLOAT);
            $statement->bindValue(':cell_'.$key, (int) floor($value * 10), SQLITE3_INTEGER);
        }
        $statement->bindValue(':kind', $kind, SQLITE3_TEXT);
        $result = $statement->execute();
        $features = [];
        $bytes = 0;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $bytes += strlen($row['geometry']);
            if ($bytes > 8_000_000) {
                throw new RuntimeException('Map geometry exceeds the render memory limit.');
            }
            $features[] = $row;
            if (count($features) > 15_000) {
                throw new RuntimeException('Map extent exceeds the feature limit.');
            }
        }
        $result->finalize();

        return $features;
    }

    private function viewport(array $points): array
    {
        $project = static function (array $p): array {
            [$lng, $lat] = $p;
            if (! is_finite($lat) || ! is_finite($lng) || abs($lat) > 85 || abs($lng) > 180) {
                throw new RuntimeException('Invalid route coordinates.');
            }

            return [($lng + 180) / 360, (1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2];
        };
        $positions = array_map(fn ($p) => $project([(float) $p['lng'], (float) $p['lat']]), $points);
        $xs = array_column($positions, 0);
        $ys = array_column($positions, 1);
        $spanX = max(max($xs) - min($xs), .000018);
        $spanY = max(max($ys) - min($ys), .000018);
        if ($spanX > .04 || $spanY > .04) {
            throw new RuntimeException('Map extent is too wide.');
        }
        $scale = min((self::WIDTH - 128) / $spanX, (self::HEIGHT - 120) / $spanY);
        $midX = (min($xs) + max($xs)) / 2;
        $midY = (min($ys) + max($ys)) / 2;
        $left = $midX - self::WIDTH / 2 / $scale;
        $top = $midY - (self::HEIGHT - 25) / 2 / $scale;
        $inverseLat = fn ($y) => rad2deg(atan(sinh(M_PI * (1 - 2 * $y))));

        return ['wide' => $spanX > .0014 || $spanY > .0014,
            'bounds' => ['west' => $left * 360 - 180, 'east' => ($left + self::WIDTH / $scale) * 360 - 180,
                'north' => $inverseLat($top), 'south' => $inverseLat($top + self::HEIGHT / $scale)],
            'pixel' => static function ($point) use ($project, $left, $top, $scale): array {
                [$x, $y] = $project($point);

                return [(int) max(-100000, min(100000, round(($x - $left) * $scale))), (int) max(-100000, min(100000, round(($y - $top) * $scale)))];
            }];
    }

    private function line(GdImage $image, array $points, int $colour, int $width): void
    {
        imagesetthickness($image, $width);
        for ($i = 1; $i < count($points); $i++) {
            imageline($image, ...[...$points[$i - 1], ...$points[$i], $colour]);
        }
    }

    private function label(GdImage $image, string $name, array $point, int $size, array &$occupied, array &$names): void
    {
        if ($name === '' || isset($names[$name]) || mb_strlen($name) > 55) {
            return;
        }
        $box = imagettfbbox($size, 0, $this->font(), $name);
        $width = $box[2] - $box[0];
        $x = $point[0] - (int) ($width / 2);
        $y = $point[1] - 6;
        $area = [$x - 5, $y - $size - 4, $x + $width + 5, $y + 5];
        if ($area[0] < 4 || $area[1] < 4 || $area[2] > self::WIDTH - 4 || $area[3] > self::HEIGHT - 30) {
            return;
        }
        foreach ($occupied as $other) {
            if ($area[0] < $other[2] && $area[2] > $other[0] && $area[1] < $other[3] && $area[3] > $other[1]) {
                return;
            }
        }
        $occupied[] = $area;
        $names[$name] = true;
        imagefilledrectangle($image, ...[...$area, imagecolorallocatealpha($image, 255, 255, 255, 22)]);
        $this->text($image, $name, $x, $y, $size, '#475569');
    }

    private function text(GdImage $image, string $text, int $x, int $y, int $size, string $colour): void
    {
        imagettftext($image, $size, 0, $x, $y, $this->colour($image, $colour), $this->font(), $text);
    }

    private function font(): string
    {
        return dirname(__DIR__, 3).'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';
    }

    private function colour(GdImage $image, string $hex): int
    {
        return imagecolorallocate($image, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    }
}
