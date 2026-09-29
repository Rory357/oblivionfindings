# Fleet secondary page assessment — 29 September 2026

This is a source and workflow assessment of the 50 titled Fleet page files in `page-inventory.json` at the `3ffc4c1` baseline and this follow-up. A controller render or an active route establishes reachability; it does not establish visual acceptance. The shared boundary is one operating organisation, approved sites, roles, direct-object denial and privacy rules.

## Corrected in this follow-up

- `fleet-assets/daily-check`: active. Replaced the legacy header with the shared PageHeader, made the three check counts filter the list, made the filtered empty state truthful, kept alert access permission-aware, and corrected date-only compliance counts.
- `fleet-assets/bookings/show`: active. Replaced the compact legacy header with the shared profile header, preserved the contextual return link and status, and allowed the status banner to wrap on narrow screens.
- `fleet-assets/vehicles/alerts-config`: active. Replaced the compact legacy header with the shared profile header, retained unsaved input after a failed save, and exposed the save error to assistive technology.
- `fleet-assets/bookings/index`: active. Kept its existing header pending a complete list/calendar design pass. Corrected calendar horizontal scrolling and focus access, status colours, and filtered empty-state copy.

## Active pages with the shared header or report wrapper

These files already use the shared header or the report wrapper. This source classification does not certify all states and actions.

- `fleet-assets/alerts/index`: canonical Fleet alert queue.
- `fleet-assets/assets/index`: asset register.
- `fleet-assets/assets/labels`: label workspace.
- `fleet-assets/compliance/index`: evidence queue and wizard entry.
- `fleet-assets/dashboard`: Fleet landing.
- `fleet-assets/geofences/index`: geofence and map workspace.
- `fleet-assets/maintenance/work-orders/index`: work-order list.
- `fleet-assets/maintenance/work-orders/report`: work-order report.
- `fleet-assets/maintenance/work-orders/show`: work-order detail.
- `fleet-assets/reports/by-house`: specialist report wrapper.
- `fleet-assets/reports/community-access`: specialist report wrapper.
- `fleet-assets/reports/cost-allocation`: specialist report wrapper.
- `fleet-assets/reports/index`: older operating summary at its explicit route; the `/reports` landing uses the newer operational report library.
- `fleet-assets/reports/reimbursement`: specialist report wrapper.
- `fleet-assets/settings/index`: Fleet settings, including Maps setup.
- `fleet-assets/transports/record`: journey record form.
- `fleet-assets/transports/workspace`: canonical Transport workspace.
- `fleet-assets/vehicles/index`: vehicle register.
- `fleet-assets/vehicles/show`: vehicle profile workspace.

## Active pages requiring presentation assessment

Each view below is rendered by an active controller route and still uses the legacy full or compact hero. The Home breadcrumb has been restored in this follow-up. The remaining header and workflow assessment must use populated, empty, error and denied states with the relevant worker or manager role. Existing journeys remain available while the canonical Transport workspace is in service.

- `fleet-assets/devices/index`: device inventory, assignment and provider state.
- `fleet-assets/drivers/index`: driver list and qualification state.
- `fleet-assets/drivers/show`: driver profile and linked records.
- `fleet-assets/fuel/index`: fuel entries, filters and totals.
- `fleet-assets/handovers/index`: handover queue and filters.
- `fleet-assets/handovers/show`: handover detail and actions.
- `fleet-assets/incidents/index`: incident queue, tabs and actions.
- `fleet-assets/inspections/index`: inspection list, templates and due state.
- `fleet-assets/inspections/show`: inspection detail and evidence.
- `fleet-assets/keys/index`: key custody, issue and return.
- `fleet-assets/maintenance/checklists/index`: checklist templates and history.
- `fleet-assets/maintenance/checklists/run`: active checklist run.
- `fleet-assets/maintenance/dashboard`: maintenance overview.
- `fleet-assets/maintenance/schedules/index`: maintenance schedule list.
- `fleet-assets/mileage/index`: mileage entries, filters and totals.
- `fleet-assets/outings/index`: outing list and scheduling.
- `fleet-assets/outings/show`: outing detail and actions.
- `fleet-assets/resident-tracking/history`: location history and privacy state.
- `fleet-assets/resident-tracking/index`: people location list and consent state.
- `fleet-assets/transports/index`: older journey list route.
- `fleet-assets/transports/medications`: journey medication route.
- `fleet-assets/transports/pre-check`: journey pre-check route.
- `fleet-assets/transports/show`: older journey detail route.
- `fleet-assets/trips/index`: trip list and telemetry state.
- `fleet-assets/trips/playback`: trip playback and map state.

## Inactive legacy branches

- `fleet-assets/map`: the registered map route redirects to the geofences map tab; this source file is not the rendered page. No cosmetic edit was made here.
- `fleet-assets/assets/show`: the controller supplies the `workspace` presenter, which selects `AssetProfileWorkspace`; the legacy compact hero in the fallback branch is not reached by that controller contract. No cosmetic edit was made to the fallback.

There are 19 pre-existing standard or wrapped pages, three converted pages, one corrected page retaining a legacy header, 25 other active legacy-header pages, and two inactive legacy branches. That accounts for all 50 inventory entries. The active legacy presentation queue is 26 pages including the booking list.
