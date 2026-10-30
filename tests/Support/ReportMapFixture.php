<?php

namespace Tests\Support;

use SQLite3;

/** Synthetic street geometry for export integration tests; never an operational map. */
final class ReportMapFixture
{
    public static function create(string $directory): string
    {
        $source = $directory.'/fixture.gpkg';
        $db = new SQLite3($source);
        $db->exec('CREATE TABLE gpkg_geometry_columns(table_name TEXT,srs_id INTEGER,z INTEGER,m INTEGER)');
        $db->exec('CREATE TABLE gpkg_spatial_ref_sys(srs_id INTEGER,definition TEXT)');
        $db->exec("INSERT INTO gpkg_spatial_ref_sys VALUES(4326,'WGS84')");
        foreach (['roads', 'places', 'water_a'] as $layer) {
            $db->exec("CREATE TABLE gis_osm_{$layer}_free(fclass TEXT,name TEXT,geom BLOB)");
            $db->exec("INSERT INTO gpkg_geometry_columns VALUES('gis_osm_{$layer}_free',4326,0,0)");
        }
        foreach ([[174.75, -41.29, 174.79, -41.27], [174.76, -41.3, 174.78, -41.26]] as $i => $line) {
            [$ax, $ay, $bx, $by] = $line;
            $geometry = "GP\0\3".pack('V', 4326).pack('e4', min($ax, $bx), max($ax, $bx), min($ay, $by), max($ay, $by))
                ."\1".pack('V2', 2, 2).pack('e4', ...$line);
            $stmt = $db->prepare("INSERT INTO gis_osm_roads_free VALUES('primary',:name,:geom)");
            $stmt->bindValue(':name', 'Synthetic street '.($i + 1));
            $stmt->bindValue(':geom', $geometry, SQLITE3_BLOB);
            $stmt->execute()->finalize();
        }
        $db->close();

        return $source;
    }
}
