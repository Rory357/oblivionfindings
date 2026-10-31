# Script catalogue HTTP fixture

`catalogue-http.json` is the byte-for-byte original JSON export from
`EndpointScriptCatalogueTest`'s isolated synthetic HTTP lifecycle, native run C
on 20 October 2026. That full run passed 11 tests and 133 assertions with its
27 source files unchanged. It uses generated test actors and synthetic code;
the script was stored and read but never executed.

The preserved original is
`.endpoint-tools/script-catalogue-20261020/php-catalogue-c.json`. Tests read the
repository fixture by default. `ENDPOINT_SCRIPT_HTTP_CORPUS` can select an
original export for a separate producer/reader check without modifying it.

These documents prove compatibility with that producer's catalogue responses.
They do not prove installed execution, hardware support, browser layout, or
production authority. Earlier run B exported a successful HTTP lifecycle but
failed a separate pagination test; its evidence remains distinct and is not
the fixture used here.
