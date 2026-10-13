# Combined Windows installation — implementation contract

8 October 2026. Source and isolated tests, not a published or installed release.

One operating organisation owns each Oblivion deployment and its approved Sites. Separate customers deploy separate instances. No organisation switcher, tenant transport or cross-customer authority is added.

## Profiles from the intended deployment

Computer support uses the existing device-bound Windows profile. Site monitoring now has authenticated HTTPS options and attachment endpoints beneath /security-devices/discovery/collectors:

- GET installation-options with site_id and platform=windows|linux.
- POST installation-profile with that Site/platform and the reviewed context_hash.

Issuance rechecks current internal-user approval, verification, permissions, required two-factor state and canonical Site access under locks. The Site must be active. A changed Site label, destination, TLS pin or signing authority invalidates review. Incomplete deployment settings refuse issuance. Raw enrolment tokens and private signing authority do not enter the audit payload.

The exported Site profile contains version, component, platform, deployment_url, server_url, server_public_key, tls_public_key_pin, organisation, site_id, site_label, collector_uuid, enrolment_token and expires_at. A new migration binds the grant to this exact collector UUID, including consumption through the legacy enrolment route. Defaults are 15 minutes, bounded to 1–30.

deployment_url is the configured application origin. server_url is its configured collector transport, which may be a dedicated TLS/mTLS origin. Neither comes from request Host or submitted destination fields. The profile contains public verification material and a short-lived secret; retrieve it from the intended authenticated deployment. An embedded key is not independent proof that an untrusted downloaded file came from that deployment.

Configuration: endpoint_management.server_url and organisation_label; monitoring.collector.installation.server_url (falls back to deployment origin), tls_public_key_pin (required), enrolment_minutes; existing collector signing authority. The native Windows installer accepts Windows profiles. The PHP collector also consumes Windows/Linux profiles through enrol-profile, requiring the profile platform to match its host OS. Linux packaging and installed service acceptance remain unfinished.

## Operator installer

The existing signed-package verifier and Windows signature gate still apply. No unsigned or runtime publisher-key override was added.

~~~text
oblivion-setup install --package release.zip --role computer --bootstrap computer.json --confirm-server https://care.example.test

oblivion-setup install --package release.zip --role site --collector-bootstrap site.json --confirm-server https://care.example.test --confirm-collector-server https://collectors.example.test

oblivion-setup install --package release.zip --role both --bootstrap computer.json --collector-bootstrap site.json --confirm-server https://care.example.test --confirm-collector-server https://collectors.example.test
~~~

The collector confirmation is required when its transport origin differs from the deployment. Both profiles must identify the exact same deployment. Only profiles required by the chosen role are accepted. Computer-only command compatibility is retained. Labels are displayed without token contents.

This is the operator CLI. The graphical Windows setup wizard now exists in endpoint-agent/installer and reuses the actual Obli Orbit theme/artwork. Its signed installed acceptance remains unfinished. Discovery & collectors now offers Set up collector: choose an approved Site and Windows/Linux, review the configured organisation and server, explicitly create a profile, then save the file. The Agent downloads Site setup entry opens the Collectors tab. Existing revoked-collector re-enrolment remains a separate lifecycle action.

The wizard keeps profile bytes in memory only, clears its download on close/account or Site-list change/window hiding/expiry, aborts pending requests and ignores late responses. Clearing a download does not revoke an already issued server grant; the original expiry still applies. An uncertain issue requires a fresh review before another attempt.

## PHP profile enrolment

For the Linux Site collector, obtain a Linux profile from the intended authenticated deployment. Place it in a private directory under the intended service account, with no group/other access (file mode 0600). Create the private state directory under that same account (mode 0700); it must initially be empty. Use the packaged runtime/entry point when a supported package is available:

~~~sh
php bin/oblivion-collector enrol-profile --profile=/private/setup/site.json --state-directory=/private/collector-state --confirm-server=https://care.example.test --confirm-collector-server=https://collectors.example.test
~~~

These paths/origins are illustrative; use the reviewed deployment details. The second confirmation is required for a separate collector transport origin. The command rejects duplicate/unknown fields, wrong platform, expired profiles, changed origins/authority, invalid files and unknown options. It uses the recoverable enrolment path with current-authority confirmation, preserves the original identity on retry, and does not accept environment-token or CLI-token overrides. Delete the downloaded secret profile after successful installation; keep the protected identity/state.

This command is source/test evidence, not a complete Linux installer, systemd deployment or real Linux OS acceptance. Current executable PHP tests ran on Windows; Linux schema validation was also exercised. The direct PHP entry does not establish Windows ACLs; Windows deployment uses the protected native installer.

## Native activation and recovery

The shared locked installation journal additionally binds collector UUID, Site and SHA-256 of the complete approved profile without persisting its token. Existing computer-only receipts remain compatible. An expired/new profile cannot silently replace the identity of an interrupted attempt; approved recovery remains required.

Setup verifies selected executable signatures and package bytes, creates separate protected Endpoint/Collector state, and enrols through separate authorities. Collector enrolment invokes only the packaged PHP/script with fixed extensions and a minimal environment. Its bounded token input uses a private inherited pipe, never command-line or environment fields. Normal collection still reads NUL.

Native setup requires current server confirmation even when the local collector commit already exists. PHP proves possession of the original signing key, validates the expected central authority before first commit, and compares the returned original certificate/key/expiry on retry. Confirmation never overwrites a committed identity or resets runtime checkpoints.

The collector's Temp, quarantine and command-journal directories are created after identity commit. The local runtime check validates PHP modules and identity; it is not a central heartbeat, signed configuration, network probe or operational health acceptance.

The selected fixed services are OblivionAgent and/or OblivionCollector. Setup registers each matching protected Event Log source before its service and checks source ownership before startup. Rollback removes a matching source only after its service cleanup succeeds; a service that may still be active retains its logging source. Each has its own immutable path, ownership marker, administrative control policy, service SID and bounded recovery. A Site-only install does not register a tray. Both activates both services and one ordinary-user tray entry. Setup never launches the tray elevated.

Retry stops only the same attempt's owned services. Failure attempts cleanup of each selected service independently, keeps identity/spool/staged files and marks incomplete cleanup for administrator review. Foreign or changed service/startup ownership is retained and reported as requiring review. No service installation or registry mutation was executed on this development workstation.

## Evidence and remaining release gates

- Server tests verify profile issue/download, current actor/Site authority, review invalidation, HTTPS, configured destinations and UUID binding; existing recoverable-controller tests remain passing.
- Native tests verify strict profile parsing, deployment/role matching, secret-free receipt binding, fixed service selection/ownership, bounded private input and real child-process containment.
- Actual Windows-to-PHP interoperability uses the real collector application, crypto and local commit with a substituted test transport. It proves private token input, valid certificate commit, online-confirmation calls, revoked-grant refusal and local runtime acceptance. It does not prove real TLS/proxy/server interoperability.
- Full native suite/vet and Windows amd64/arm64 compilation passed. Collector standalone suite passed. Exact run details are in CHECKPOINT.md.

Event Log registration/ownership/recovery now exists in source; see WINDOWS-EVENT-LOG.md. Native registry/Event Viewer and signed graphical setup acceptance, supported packaged PHP/dependency provenance, production signatures, installed SCM/tray/browser/relay acceptance, Windows/Linux collector distribution, repair/update/uninstall/durable revision policy and all remaining programme areas remain under the single final release gate. This work does not enable the normal remote Connect gate.

## Completed-install service repair

The setup core now has repair-services for the exact completed Computer/Site/Both installation, without presenting new bootstrap grants. It validates existing program bytes/identity, restores owned registration and records durable local outcomes. See SERVICE-REPAIR.md. It does not replace damaged files, switch roles/destinations or complete the outstanding update/uninstall and signed installed acceptance requirements.
