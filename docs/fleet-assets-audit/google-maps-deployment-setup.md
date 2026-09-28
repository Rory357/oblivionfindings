# Google Maps deployment setup

Google is optional. Fleet & Assets Settings → Maps → Configure provider can be used before credentials are available: leave **Use Google Maps** off and save the project reference. OpenStreetMap, saved sites, manual addresses, coordinates and canonical boundary editing remain available.

## When the keys are ready

1. In the organisation-approved Google Cloud project, review billing and enable **Maps JavaScript API**. Create a browser key restricted to approved application websites and this API. Use separate restricted keys for development and production.
2. If needed, enable **Places API (New)** for address search, **Geocoding API** for reverse geocoding, and **Routes API** for route estimates. Create a separate server key restricted to the deployment's public egress IP addresses and only the selected APIs. Map display alone does not need this key.
3. Add the keys through the deployment's secret/environment settings. Do not commit them, put them in the project reference, or paste them into audit notes:

    ```dotenv
    GOOGLE_MAPS_API_KEY=
    GOOGLE_MAPS_SERVER_API_KEY=
    GOOGLE_MAPS_PROJECT_ID=
    ```

    `GOOGLE_MAPS_PROJECT_ID` supplies an initial reference; a saved reference takes precedence. The browser key is necessarily sent to a browser when Google display is enabled. The server key stays on the server. Settings display keyed fingerprints, not key fragments.

4. Refresh the deployment's Laravel configuration (`php artisan config:cache`) and restart any long-running application workers according to the normal deployment procedure. These commands operate on the deployment being configured; the wizard cannot change its environment.
5. Open **Configure provider**, select **Reload configuration**, and review the credential references. If the saved settings or credentials changed while a draft was open, review the conflict before saving.
6. Enable Google and select the optional APIs needed. Google display is the foundation for displaying Google-derived results. Continue through **Check configuration** and **Review & save**, confirm the restrictions and provider review, and save.
7. Run a deliberate request using public, non-sensitive test inputs in the capability explorer. A configured key does not prove that Google accepted it. Inspect the result and Google Cloud's usage, quota and billing screens. Setup and reload do not run a paid provider probe.

## States and troubleshooting

- **Google is off / Not selected:** no Google capability has been enabled for this use.
- **Credential missing / Google display required / Review required:** the selected feature is unavailable. Correct the deployment or saved configuration, then reload.
- **Configured · not verified:** local prerequisites are present; restrictions, enabled APIs, billing and quota have not been confirmed by setup.
- **Last request succeeded / quota reached / unavailable / rejected:** a bounded observation from an explicit server request, associated with the current configuration. Observations expire after 24 hours and contain no search text, coordinates or credentials. They are not continuous health monitoring or a bill. Google display itself has no server request observation.

Removing or rotating either key changes the configuration revision. Missing display credentials restore OSM and disable optional Google requests. Provider quota/temporary failures apply a short backoff instead of repeatedly sending paid requests. Disabling Google remains possible after credentials are removed.

Configure API quotas deliberately. Budget alerts send notifications; they do not impose a hard spending cap. Free allowances depend on the API/SKU and current Google pricing, so this application does not promise free operation. See [Google cost controls](https://developers.google.com/maps/billing-and-pricing/manage-costs) and [API security guidance](https://developers.google.com/maps/api-security-best-practices).

## Access and ownership

Only `fleet.settings.manage` can save the shared provider configuration. Reader access and source records retain the existing roles, approved sites, record ownership and privacy rules. This is one operating organisation, not a tenant selection workflow.

The shared map renderer uses the saved display setting. Transport and Maps & boundaries expose the reusable Google address and route viewer to permitted Fleet/Assets readers. Boundary editors retain their geometry controls and configured OSM-compatible tiles. Manual transport addresses remain available for addresses outside saved sites. Google-derived viewer results stay temporary and attributed on a Google map; they are not copied into canonical records or displayed on OSM. Client and People location consent, source selection and sharing remain with those workspaces.

Live Google verification remains pending until real restricted credentials and the project's API/billing setup are available.
