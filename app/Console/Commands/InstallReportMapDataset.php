<?php

namespace App\Console\Commands;

use App\Services\Maps\OsmReportDataset;
use Illuminate\Console\Command;

final class InstallReportMapDataset extends Command
{
    protected $signature = 'report-maps:install {geopackage : Local extracted Geofabrik GeoPackage} {--label= : Region label} {--date= : Dataset date, YYYY-MM-DD} {--sha256= : Expected GeoPackage SHA-256}';

    protected $description = 'Install a versioned local OpenStreetMap dataset for private report rendering';

    public function handle(OsmReportDataset $installer): int
    {
        try {
            $metadata = $installer->install((string) $this->argument('geopackage'),
                (string) config('report_maps.directory'), (string) $this->option('label'),
                (string) $this->option('date'), strtolower((string) $this->option('sha256')));
            $this->info('Installed '.$metadata['label'].' · '.$metadata['date'].' · '.$metadata['features']['roads'].' streets.');
            $this->line(OsmReportDataset::ATTRIBUTION);

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('Map installation failed: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
