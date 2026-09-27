<?php

return [
    // Installed public OSM data, outside the web root. Report coordinates stay local.
    'directory' => env('REPORT_MAP_DIRECTORY', storage_path('app/private/report-maps')),
];
