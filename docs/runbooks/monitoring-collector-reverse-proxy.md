# Monitoring collector reverse-proxy contract

Oblivion Findings collector runtime endpoints require two independent identities on every request after enrolment:

1. a client certificate verified by the terminating reverse proxy against the dedicated collector CA; and
2. an Ed25519 request signature from the public key bound during one-time enrolment.

The application never trusts a certificate or fingerprint supplied directly by a collector. The proxy must require successful client-certificate verification and replace any incoming `X-Oblivion-Verified-Client-Certificate` value with Nginx's verified, escaped PEM value. The application parses that verified certificate transiently and derives its SHA-256 fingerprint itself. `MONITORING_COLLECTOR_TRUSTED_PROXY_CIDRS` must contain only the exact internal proxy address ranges that can perform that verification. An empty allowlist fails closed.

Example Nginx server shape (adapt certificate paths and upstream locally):

```nginx
ssl_verify_client optional;
ssl_client_certificate /etc/oblivion/collector-ca.pem;

location = /api/monitoring/collectors/enrol {
    proxy_set_header X-Oblivion-Verified-Client-Certificate "";
    proxy_set_header X-Oblivion-Client-Certificate-Fingerprint "";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto https;
    proxy_pass http://oblivion_application;
}

location /api/monitoring/collectors/ {
    if ($ssl_client_verify != SUCCESS) {
        return 403;
    }

    proxy_set_header X-Oblivion-Verified-Client-Certificate $ssl_client_escaped_cert;
    proxy_set_header X-Oblivion-Client-Certificate-Fingerprint "";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto https;
    proxy_pass http://oblivion_application;
}
```

If the proxy cannot apply optional client authentication safely, expose enrolment through a separate listener and require mTLS on the listener serving the three runtime locations: `configuration`, `observations`, and `heartbeat`. Do not forward the certificate header on any unrelated route.

`$ssl_client_escaped_cert` is the URL-encoded PEM certificate that Nginx has already verified against the dedicated collector CA. The application accepts it only from an exact trusted proxy CIDR, bounds and parses it with OpenSSL, derives the lowercase SHA-256 fingerprint, and compares that value with the identity stored at enrolment. Stock Nginx `$ssl_client_fingerprint` is SHA-1 and must not be substituted. The legacy pre-derived fingerprint header is disabled by default and may be enabled only as a time-limited migration setting after an independently reviewed proxy-side SHA-256 implementation is proven.

Required application settings:

- `MONITORING_COLLECTOR_SIGNING_SECRET_KEY`: base64 Ed25519 secret key used only to sign configuration envelopes.
- `MONITORING_COLLECTOR_TRUSTED_PROXY_CIDRS`: comma-separated internal proxy CIDRs.
- `MONITORING_COLLECTOR_CA_CERTIFICATE_PATH`: readable dedicated CA certificate.
- `MONITORING_COLLECTOR_CA_PRIVATE_KEY_PATH`: readable dedicated CA private key, restricted to the application service account.
- `MONITORING_COLLECTOR_CA_PRIVATE_KEY_PASSPHRASE`: secret-manager-injected passphrase when the CA key is encrypted.
- `MONITORING_COLLECTOR_REPLAY_STORE=redis`: shared atomic replay protection across all application instances.
- `MONITORING_COLLECTOR_ALLOW_PROXY_FINGERPRINT_HEADER=false`: keep the legacy proxy-derived fingerprint path disabled; the standard verified-PEM path above needs no proxy scripting.

Before enabling a remote Site, verify certificate issuance, a valid signed request, denial from an untrusted source address, denial after fingerprint substitution, nonce replay denial, collector revocation denial, configuration scope, ordered upload recovery, heartbeat outage timing, and CA/key rotation in a non-production environment. Never log bearer enrolment tokens, private keys, credential-lease material, raw configuration envelopes, or request signatures.
