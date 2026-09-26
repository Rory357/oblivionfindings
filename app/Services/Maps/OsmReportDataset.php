<?php

namespace App\Services\Maps;

use RuntimeException;
use SQLite3;

/** Installs public map data separately from the operating database and private trip records. */
final class OsmReportDataset
{
    public const ATTRIBUTION = '© OpenStreetMap contributors · ODbL · openstreetmap.org/copyright';

    public function install(string $source, string $directory, string $label, string $date, string $sha256): array
    {
        if (! is_file($source) || ! preg_match('/^[a-f0-9]{64}$/', $sha256)
            || ! hash_equals($sha256, hash_file('sha256', $source))) {
            throw new RuntimeException('The source file does not match its SHA-256.');
        }
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsedDate || $parsedDate->format('Y-m-d') !== $date || strlen($label) < 1 || strlen($label) > 100) {
            throw new RuntimeException('Supply a dataset label and valid source date.');
        }
        $sourceDb = new SQLite3($source, SQLITE3_OPEN_READONLY);
        $sourceDb->enableExceptions(true);
        $sourceDb->exec('PRAGMA trusted_schema=OFF');
        $sourceDb->exec('PRAGMA query_only=ON');
        $srs = (int) $sourceDb->querySingle("SELECT srs_id FROM gpkg_geometry_columns WHERE table_name='gis_osm_roads_free' AND z=0 AND m=0");
        $definition = (string) $sourceDb->querySingle('SELECT definition FROM gpkg_spatial_ref_sys WHERE srs_id='.$srs);
        if (($srs !== 4326 && ! str_contains($definition, 'AUTHORITY["OGC","CRS84"]')) || $srs < 1) {
            throw new RuntimeException('Only the Geofabrik WGS84/CRS84 XY dataset is supported.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Could not create the private map directory.');
        }
        $name = 'osm-'.substr($sha256, 0, 20).'.sqlite';
        $destination = $directory.DIRECTORY_SEPARATOR.$name;
        $temporary = $destination.'.'.bin2hex(random_bytes(6)).'.tmp';
        $target = new SQLite3($temporary, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $target->enableExceptions(true);
        $counts = [];
        try {
            $target->exec('PRAGMA journal_mode=OFF');
            $target->exec('CREATE TABLE features (id INTEGER PRIMARY KEY, kind TEXT NOT NULL, class TEXT, name TEXT, geometry BLOB NOT NULL)');
            $target->exec('CREATE TABLE bounds (id INTEGER PRIMARY KEY,min_x REAL,max_x REAL,min_y REAL,max_y REAL)');
            $target->exec('CREATE TABLE cells (x INTEGER,y INTEGER,feature_id INTEGER,PRIMARY KEY(x,y,feature_id)) WITHOUT ROWID');
            $target->exec('CREATE TABLE metadata (json TEXT NOT NULL)');
            $target->exec('BEGIN');
            $insert = $target->prepare('INSERT INTO features(kind,class,name,geometry) VALUES (:kind,:class,:name,:geometry)');
            $index = $target->prepare('INSERT INTO bounds VALUES (:id,:min_x,:max_x,:min_y,:max_y)');
            $cell = $target->prepare('INSERT INTO cells VALUES (:x,:y,:id)');
            foreach (['roads', 'places', 'water_a'] as $kind) {
                $table = 'gis_osm_'.$kind.'_free';
                $declared = $sourceDb->querySingle("SELECT srs_id FROM gpkg_geometry_columns WHERE table_name='{$table}' AND z=0 AND m=0");
                if ((int) $declared !== $srs) {
                    throw new RuntimeException('Required map layers are missing or use inconsistent coordinates.');
                }
                $rows = $sourceDb->query("SELECT fclass,name,geom FROM {$table}");
                $counts[$kind] = 0;
                while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                    if (++$counts[$kind] > 5_000_000) {
                        throw new RuntimeException('Map layer exceeds the installation limit.');
                    }
                    $bbox = $this->bounds($row['geom'], $srs);
                    $insert->reset();
                    $insert->bindValue(':kind', $kind, SQLITE3_TEXT);
                    $insert->bindValue(':class', (string) $row['fclass'], SQLITE3_TEXT);
                    $insert->bindValue(':name', (string) $row['name'], SQLITE3_TEXT);
                    $insert->bindValue(':geometry', $row['geom'], SQLITE3_BLOB);
                    $insert->execute()->finalize();
                    $index->reset();
                    $id = $target->lastInsertRowID();
                    $index->bindValue(':id', $id, SQLITE3_INTEGER);
                    foreach ($bbox as $key => $value) {
                        $index->bindValue(':'.$key, $value, SQLITE3_FLOAT);
                    }
                    $index->execute()->finalize();
                    $west = (int) floor($bbox['min_x'] * 10);
                    $east = (int) floor($bbox['max_x'] * 10);
                    $south = (int) floor($bbox['min_y'] * 10);
                    $north = (int) floor($bbox['max_y'] * 10);
                    // A simple B-tree grid also works on PHP builds without SQLite R-tree.
                    // Very large features use a separate sentinel bucket, still bounded at read time.
                    if (($east - $west + 1) * ($north - $south + 1) > 4096) {
                        $west = $east = 2147483647;
                        $south = $north = 0;
                    }
                    for ($x = $west; $x <= $east; $x++) {
                        for ($y = $south; $y <= $north; $y++) {
                            $cell->reset();
                            $cell->bindValue(':x', $x, SQLITE3_INTEGER);
                            $cell->bindValue(':y', $y, SQLITE3_INTEGER);
                            $cell->bindValue(':id', $id, SQLITE3_INTEGER);
                            $cell->execute()->finalize();
                        }
                    }
                }
                $rows->finalize();
            }
            if ($counts['roads'] < 1) {
                throw new RuntimeException('The dataset contains no streets.');
            }
            $metadata = ['schema' => 1, 'file' => $name, 'label' => $label, 'date' => $date,
                'source_sha256' => $sha256, 'srs_id' => $srs, 'features' => $counts,
                'attribution' => self::ATTRIBUTION, 'source' => 'Geofabrik OpenStreetMap extract'];
            $meta = $target->prepare('INSERT INTO metadata VALUES (:json)');
            $meta->bindValue(':json', json_encode($metadata, JSON_THROW_ON_ERROR), SQLITE3_TEXT);
            $meta->execute()->finalize();
            $target->exec('COMMIT');
            $target->exec('CREATE INDEX feature_kind ON features(kind)');
            $target->close();
            $target = null;
            // A failed install never replaces the active dataset. Keep prior versions for rollback.
            if (! file_exists($destination)) {
                if (! rename($temporary, $destination)) {
                    throw new RuntimeException('Could not publish the map dataset.');
                }
            } else {
                $existing = new SQLite3($destination, SQLITE3_OPEN_READONLY);
                $same = json_decode($existing->querySingle('SELECT json FROM metadata'), true) === $metadata;
                $existing->close();
                if (! $same) {
                    throw new RuntimeException('This dataset version already exists with different metadata.');
                }
                unlink($temporary);
            }
            $manifest = $directory.DIRECTORY_SEPARATOR.'active.json';
            $pending = $manifest.'.'.bin2hex(random_bytes(6));
            file_put_contents($pending, json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
            if (! rename($pending, $manifest)) {
                throw new RuntimeException('Could not activate the map dataset.');
            }

            return $metadata;
        } finally {
            $sourceDb->close();
            if ($target) {
                $target->close();
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function bounds(string $geometry, int $srs): array
    {
        if (strlen($geometry) < 13 || strlen($geometry) > 8_000_000 || substr($geometry, 0, 3) !== "GP\0") {
            throw new RuntimeException('Invalid map geometry.');
        }
        $flags = ord($geometry[3]);
        $little = $flags & 1;
        if (($flags & 0xF0) !== 0 || unpack($little ? 'V' : 'N', substr($geometry, 4, 4))[1] !== $srs) {
            throw new RuntimeException('Inconsistent map geometry.');
        }
        $envelope = ($flags >> 1) & 7;
        if ($envelope === 1 && strlen($geometry) >= 45) {
            $box = array_values(unpack($little ? 'e4' : 'E4', substr($geometry, 8, 32)));
        } elseif ($envelope === 0) {
            $shape = (new GeoPackageGeometry)->decode($geometry, $srs);
            if (count($shape) !== 1 || $shape[0]['type'] !== 'point') {
                throw new RuntimeException('A map feature needs a spatial envelope.');
            }
            [$x, $y] = $shape[0]['coordinates'];
            $box = [$x, $x, $y, $y];
        } else {
            throw new RuntimeException('Only XY map envelopes are supported.');
        }
        [$west, $east, $south, $north] = $box;
        if (count(array_filter($box, 'is_finite')) !== 4 || $west < -180 || $east > 180
            || $south < -90 || $north > 90 || $west > $east || $south > $north) {
            throw new RuntimeException('Invalid map extent.');
        }

        return ['min_x' => $west, 'max_x' => $east, 'min_y' => $south, 'max_y' => $north];
    }
}
