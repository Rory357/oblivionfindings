<?php

namespace Tests\Unit\Maps;

use App\Services\Maps\GeoPackageGeometry;
use App\Services\Maps\OsmReportDataset;
use App\Services\Maps\OsmReportMap;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SQLite3;

final class OsmReportMapTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/oblivion-map-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_installed_map_renders_streets_with_local_provenance_and_attribution(): void
    {
        $source = $this->fixture();
        $metadata = (new OsmReportDataset)->install($source, $this->directory.'/installed', 'Synthetic map fixture', '2026-09-23', hash_file('sha256', $source));
        $this->assertSame(['roads' => 2, 'places' => 1, 'water_a' => 0], $metadata['features']);
        $map = (new OsmReportMap)->render($this->points(), '#7c3aed', false, $this->directory.'/installed');
        $this->assertSame('local_osm_street_map', $map['kind']);
        $this->assertStringContainsString('OpenStreetMap contributors', $map['note']);
        $this->assertSame(hash_file('sha256', $source), $map['dataset']['sha256']);
        $image = imagecreatefromstring(base64_decode(explode(',', $map['image'], 2)[1]));
        $this->assertSame(1040, imagesx($image));
        $this->assertSame(440, imagesy($image));
        $route = $road = 0;
        for ($x = 0; $x < 1040; $x += 2) {
            for ($y = 0; $y < 415; $y += 2) {
                $pixel = imagecolorat($image, $x, $y) & 0xFFFFFF;
                $route += (int) ($pixel === 0x7C3AED);
                $road += (int) ($pixel === 0xC6CBCF);
            }
        }
        $this->assertGreaterThan(100, $route);
        $this->assertGreaterThan(100, $road);
        imagedestroy($image);
    }

    public function test_missing_coverage_and_broken_metadata_never_claim_a_street_map(): void
    {
        $renderer = new OsmReportMap;
        $missing = $renderer->render($this->points(), '#7c3aed', false, $this->directory);
        $this->assertNull($missing['image']);
        $this->assertStringContainsString('not installed', $missing['note']);
        $source = $this->fixture();
        (new OsmReportDataset)->install($source, $this->directory.'/installed', 'Test', '2026-09-23', hash_file('sha256', $source));
        $outside = $renderer->render([['lat' => 51.5, 'lng' => 0.1], ['lat' => 51.51, 'lng' => 0.11]], '#7c3aed', false, $this->directory.'/installed');
        $this->assertNull($outside['image']);
        $this->assertStringContainsString('for this area', $outside['note']);
        file_put_contents($this->directory.'/installed/active.json', '{"schema":1,"file":"../../private.sqlite"}');
        $broken = $renderer->render($this->points(), '#7c3aed', false, $this->directory.'/installed');
        $this->assertNull($broken['image']);
        $this->assertStringNotContainsString('private.sqlite', $broken['note']);
    }

    public function test_failed_install_keeps_the_previous_dataset_and_its_identity(): void
    {
        $source = $this->fixture();
        $installer = new OsmReportDataset;
        $installed = $this->directory.'/installed';
        $installer->install($source, $installed, 'Test', '2026-09-23', hash_file('sha256', $source));
        $manifest = file_get_contents($installed.'/active.json');
        foreach ([str_repeat('a', 64), hash_file('sha256', $source)] as $hash) {
            try {
                $installer->install($source, $installed, 'Changed', '2026-09-24', $hash);
                $this->fail('Invalid or conflicting dataset accepted.');
            } catch (RuntimeException) {
                $this->assertSame($manifest, file_get_contents($installed.'/active.json'));
            }
        }
        $this->assertSame([], glob($installed.'/*.tmp'));
    }

    public function test_geometry_reads_xy_and_rejects_truncation_wrong_srs_and_excessive_counts(): void
    {
        $geometry = $this->line([174.76, -36.85], [174.77, -36.84]);
        $this->assertSame([174.76, -36.85], (new GeoPackageGeometry)->decode($geometry)[0]['coordinates'][0]);
        foreach ([substr($geometry, 0, -4), substr_replace($geometry, pack('V', 3857), 4, 4), substr_replace($geometry, pack('V', 100001), 45, 4)] as $invalid) {
            try {
                (new GeoPackageGeometry)->decode($invalid);
                $this->fail('Invalid geometry accepted.');
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    private function fixture(): string
    {
        $source = $this->directory.'/fixture.gpkg';
        $db = new SQLite3($source);
        $db->exec('CREATE TABLE gpkg_geometry_columns(table_name TEXT,srs_id INTEGER,z INTEGER,m INTEGER)');
        $db->exec('CREATE TABLE gpkg_spatial_ref_sys(srs_id INTEGER,definition TEXT)');
        $db->exec("INSERT INTO gpkg_spatial_ref_sys VALUES(4326,'WGS84')");
        foreach (['roads', 'places', 'water_a'] as $layer) {
            $db->exec("CREATE TABLE gis_osm_{$layer}_free(fclass TEXT,name TEXT,geom BLOB)");
            $db->exec("INSERT INTO gpkg_geometry_columns VALUES('gis_osm_{$layer}_free',4326,0,0)");
        }
        foreach ([[174.75, -36.855, 174.78, -36.845], [174.755, -36.86, 174.775, -36.835]] as $i => $line) {
            $stmt = $db->prepare("INSERT INTO gis_osm_roads_free VALUES('primary',:name,:geom)");
            $stmt->bindValue(':name', 'Fixture street '.($i + 1));
            $stmt->bindValue(':geom', $this->line(array_slice($line, 0, 2), array_slice($line, 2, 2)), SQLITE3_BLOB);
            $stmt->execute()->finalize();
        }
        $stmt = $db->prepare("INSERT INTO gis_osm_places_free VALUES('suburb','Fixture suburb',:geom)");
        $stmt->bindValue(':geom', "GP\0\1".pack('V', 4326)."\1".pack('V', 1).pack('e2', 174.755, -36.849), SQLITE3_BLOB);
        $stmt->execute()->finalize();
        $db->close();

        return $source;
    }

    private function line(array $a, array $b): string
    {
        return "GP\0\3".pack('V', 4326).pack('e4', min($a[0], $b[0]), max($a[0], $b[0]), min($a[1], $b[1]), max($a[1], $b[1]))
            ."\1".pack('V2', 2, 2).pack('e4', ...[...$a, ...$b]);
    }

    private function points(): array
    {
        return [['lat' => -36.852, 'lng' => 174.757], ['lat' => -36.841, 'lng' => 174.772]];
    }
}
