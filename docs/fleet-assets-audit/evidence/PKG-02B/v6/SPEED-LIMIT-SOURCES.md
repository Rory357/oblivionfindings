# Road speed-limit sources

Research checked 22 September 2026. No paid account, key or service was activated.

## OpenStreetMap

OSM road features can carry `maxspeed`, direction-specific limits and conditional limits. They are optional, community-maintained attributes. A rendered map tile is an image, not a road-matched speed-limit API. Missing data must remain unknown, and GPS samples need a defensible road/direction match. Sources: [maxspeed](https://wiki.openstreetmap.org/wiki/Maxspeed), [conditional limits](https://wiki.openstreetmap.org/wiki/Key%3Amaxspeed%3Aconditional).

## Google Roads

Google's Speed Limits service requires an Asset Tracking licence. Google explicitly says speed-limit data is not real time, and may be inaccurate, incomplete or outdated; variable-limit roads return the maximum. Road results have display, attribution and caching restrictions, including use with Google maps rather than a non-Google map. It cannot simply be substituted behind the current OSM map. Sources: [Speed Limits](https://developers.google.com/maps/documentation/roads/speed-limits), [Roads policies](https://developers.google.com/maps/documentation/roads/policies).

## NZ baseline and other providers

The [NZTA National Speed Limit Register](https://nzta.govt.nz/partners/speed-management/national-speed-limit-register) is a useful legal/static baseline, but temporary changes require separate handling. [HERE route matching](https://docs.here.com/routing/docs/detect-speeding-gps) documents GPS matching and speed comparisons. [TomTom Live Speed Restrictions](https://docs.tomtom.com/intermediate-traffic-service/documentation/introduction) is another product to assess for the required geography and commercial coverage. Availability, terms and country coverage need confirmation before selection.

## Proposed integration (design inference)

Use a structured provider feed, match the road and direction, preserve version/freshness/confidence and effective time, then evaluate the road limit separately from fleet policy. Approved manual evidence handles temporary/local limits with a start, expiry, reason and reviewer. Overlapping/conflicting sources remain unknown pending review. Detect a continuous episode from timestamped, quality-checked samples; preserve the trigger, duration, source and location when routing it to Control Room.

The mockup's provider response is explicitly synthetic. OSM imagery is real public imagery, but its presence does not mean live road-limit integration is complete. Temporary manual limits are evidence-backed synthetic records only.
