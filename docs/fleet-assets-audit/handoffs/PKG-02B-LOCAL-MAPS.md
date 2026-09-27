# Built-in street maps for vehicle reports

The user requested real streets in saved PDF/Excel reports and then asked to build this into Oblivion. `OsmReportDataset` and `OsmReportMap` implement that inside the existing PHP application. There is no paid API, account, external tile renderer, browser screenshot service or request containing trip coordinates.

This remains a single-tenant application. The existing vehicle report service first applies roles, approved Sites, trip privacy and tracking consent. Only its authorised points reach the renderer. Public map data contains no vehicle or client records and does not grant access to trips. The renderer adds no HTTP route or publicly served database.

## Installation and updates

1. Download the public regional **GeoPackage ZIP** from [Geofabrik](https://download.geofabrik.de/australia-oceania/new-zealand.html), keeping its source URL and date. Public OSM tile servers and editing APIs are not used for exports.
2. Extract the `.gpkg` outside the web root. Record its SHA-256 from the actual file. This validates local file identity; it is not a signature from the publisher.
3. Run the operator command under the application service account:

```text
php artisan report-maps:install /private/maps/new-zealand.gpkg --label="New Zealand" --date=2026-09-23 --sha256=<actual-file-sha256>
```

The command verifies the hash, validates WGS84/CRS84 XY layer metadata, and builds a bounded spatial index of roads, places and water. It requires PHP SQLite3, GD/FreeType and the DejaVu Sans font already supplied by dompdf. It uses ordinary SQLite B-tree cells so it also works on PHP builds without the R-tree extension. No spatial extension, application schema migration or operating-database write is required.

`REPORT_MAP_DIRECTORY` optionally specifies the private dataset directory; the default is `storage/app/private/report-maps`. PHP must be able to read it. Installation needs write access; normal export rendering opens SQLite read-only with trusted schema disabled. The directory must not be published through a storage symlink or web-server alias.

An installation writes an immutable version file and atomically activates `active.json`. Failed imports leave the previous active dataset intact. Old versions remain for rollback; restore the matching previous manifest and dataset together. Do not edit a manifest's source identity or delete a version still in use. A refresh is an explicit operator action: download a fresh extract, record its date/hash and rerun installation. Scheduling and the desired refresh cadence remain operating configuration, not an invented Fleet policy.

## Report behavior and limits

- PDF and Excel embed greyscale street maps, named roads/places, the brand-coloured recorded-position line, A/B markers and OSM attribution. The map image is generated locally and remains inside the authorised download.
- The data date and source appear below each map. The export audit records image kind, dataset label/date/source SHA-256 and trip count. The line joins recorded positions; it is not snapped to roads and does not establish where the vehicle travelled between samples.
- If data is missing, outside installed coverage, malformed or too complex for the bounded renderer, the report explicitly says the street map is unavailable and labels its position sketch. It never substitutes an unapproved external service. Wide extents simplify minor roads; extremely wide/dateline-crossing journeys fall back explicitly.
- Regional street maps are contextual. They do not supply verified current speed limits, routing, geocoding, traffic conditions, collision evidence or device capabilities. Existing unknown-speed-limit behavior remains unchanged.
- Rendering is bounded to 15,000 selected features per layer, 8MB selected geometry per layer, 100,000 decoded points across the image and a maximum extent. Existing report trip/range limits remain. Large report batches still need deployment-specific PHP memory/runtime capacity; a queued bulk-report product was not silently introduced.
- Dataset attribution is retained inside every image and in report text. Public derived map data stays subject to ODbL; retain the source extract and [OSM attribution/licence reference](https://www.openstreetmap.org/copyright). Application trip records are separate overlays, not written into the public map dataset.

## Verified dataset and evidence

The audit preview uses the actual public Geofabrik New Zealand extract dated 23 September 2026:

- Source: `https://download.geofabrik.de/australia-oceania/new-zealand-260923-free.gpkg.zip` (775,683,111 bytes).
- ZIP SHA-256: `2365c7a0f6f14d17163022978212079be961eb95b455d4d6e25b905518391dc6`.
- Extracted GeoPackage: 1,502,552,064 bytes; SHA-256 `8a40e14d71fd3c9522646117209d0ea403cba10fc549ab3e77b09fa84b581ad4`.
- Installed features: 852,534 roads, 5,784 places, 61,455 water areas. Installation took 7.53 seconds on the audit machine; a sample Auckland map rendered in approximately 0.4 seconds. These are observations, not performance guarantees.
- Native Chrome downloaded a four-page PDF with two real street maps and an Excel workbook with two embedded PNGs in **Journey maps**. Poppler rendered both trip pages; openpyxl verified image count, workbook structure, typed cells and totals. The map background is real OSM data; the vehicle/trips are explicitly synthetic QA records.

Dataset binaries are local operating data, not committed source. They are preserved in the task-owned `pkg02b-map-data` visualization directory and enabled only in the audit preview. Main's operating checkout/database has not been configured or modified. Deployment must install the selected region before claiming street-map exports are available there.
