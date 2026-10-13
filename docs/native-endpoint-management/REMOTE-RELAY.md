# Remote-support relay and service negotiation

8 October 2026. Single operating organisation across approved Sites. Independent customer installations supply their own deployment URL and relay configuration. This is development source; the final release gate is not satisfied.

## Deployment configuration

ENDPOINT_REMOTE_RELAY_URLS is a comma-separated list of one to four explicit TURN URLs for the deployment's relay service. Each URL requires a host and port; examples are turns:relay.example.test:5349?transport=tcp and turn:relay.example.test:3478?transport=udp. TLS URLs permit TCP only. IPv6 literals use brackets. STUN, user information, paths, fragments and arbitrary queries are rejected. There is no automatic public server or direct-connection fallback.

ENDPOINT_REMOTE_RELAY_SECRET is a protected server configuration value, 32–512 bytes, shared only between the Oblivion server and its TURN REST authentication service. It is never included in an installer, browser page, agent state, signed grant or audit event. Use separate secrets for independent deployments. The remote_support.enabled switch is still false; setting relay configuration alone does not enable screen support.

The application generates temporary credentials compatible with [Coturn's TURN REST protocol](https://github.com/coturn/coturn/blob/master/README.turnserver): an expiry-prefixed opaque username and its Base64 HMAC-SHA1 credential. This protocol MAC does not change the Ed25519 signing scheme for application grants. The application does not install or configure Coturn.

## Grant authority and lifetime

The enrolled agent requests its grant using the accepted desktop identifier. The initiating technician obtains a distinct credential through their existing authenticated web session. Both paths recheck the current canonical device, Site/assignment, technician permissions/account, linked ticket, consent mode and live session. Pre-consent and wrong-desktop requests fail. Other technicians and enrolled computers cannot obtain a session's grants.

The agent's typed grant is signed by the pinned deployment authority. Its agent/session/desktop/access/role, exact fields, relay destinations and lifetime are validated before use. A technician grant is returned over the existing private HTTPS route. No session authority comes from possessing a relay credential alone.

The signed grant is valid for at most 15 seconds and cannot outlive the current application lease. The TURN credential expires at the already configured session deadline, at most 120 minutes from issuance; it is not a 15-second TURN password. Distinct participant usernames use an opaque keyed hash, not staff names or device identifiers. Credentials are computed on demand and are not stored in the session or audit rows.

Application Stop immediately withdraws local consent and cancels the media lifetime. It does not erase a previously issued bearer credential from a relay. Production relay acceptance must therefore cover authenticated-only operation, allocation/bandwidth quotas, restricted peer destinations, prohibited loopback/multicast/private targets as appropriate to the deployment, expiry and abandoned-allocation cleanup. Relay network permissions must be explicit for the deployment; candidate type alone is not an IP allowlist.

## Service orchestration

The installed-service runner starts media supervision alongside its existing request/lease loop. Once attended consent has an acknowledged allowed lease, negotiation fetches the signed relay grant, creates a bound RTC peer, gathers its offer, publishes it and fetches/applies the signed answer. Lease renewals continue independently while networking or ICE gathering is pending.

Setup has a 30-second deadline, HTTP calls retain three-second limits, and ICE offer gathering retains its eight-second limit. A 100 ms supervisor enforces existing OS/session, helper presence and lease checks. Local Stop cancels the attempt synchronously, before upstream reporting. A changed controller revision or cancelled lifetime prevents late results from restoring access. Disconnected transport signals wake a pending handshake without waiting for its full timeout. Shutdown cancels and joins the negotiation worker.

The TURN credential deadline is also enforced locally using a monotonic deadline after signed wall-clock validation. A relay/configuration failure ends this request; there is no silent alternative server or automatic request revival.

A ready encrypted data channel is still only negotiation. A separately present, OS-bound Windows worker now triggers a signed active lease before frames/input are permitted. The browser technician viewer and real installed acceptance remain missing; see REMOTE-WINDOWS.md. Current source must not be presented as operational remote control.

## Evidence and remaining acceptance

Backend tests exercise pre-consent denial, distinct participant credentials, signed binding, credential expiry, absent/malformed configuration, other-actor/agent denial, withdrawn Site authority, stopped-session denial and secret exclusion. Local HTTPS client tests validate the configured route and signed response without contacting a deployed server.

Native lifecycle tests hold setup pending while renewing the lease, stop during setup, deliver a late answer after Stop, simulate OS/helper/lease/connection/relay loss, and join a cancelled worker. All use simulated desktop identity and input, not an installed SYSTEM service.

The actual RTC tests connect two encrypted peers through a loopback-only TURN fixture, with separate agent/technician credentials using the same expiring TURN REST mechanism. Generated JPEGs and simulated input prove transport behaviour. No host screenshot or real input injection is used. This is not a Coturn deployment, browser interoperability, NAT/performance or installed Windows acceptance witness.

Remaining work includes the ordinary-user Windows capture/input worker, private Obli content masking/exclusion, browser viewer, active-session handshake and input release on Stop; unattended/desktop-selection/emergency/recording policy; production relay deployment; and signed installed end-to-end acceptance. The wider programme in PROGRAMME.md remains required.
