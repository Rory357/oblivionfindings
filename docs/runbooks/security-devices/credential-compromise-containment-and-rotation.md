# Credential compromise containment and rotation

## Purpose

Use this runbook when a Security & Devices credential reference may be compromised, when the secret-manager provider reports exposure, or during the scheduled credential-rotation rehearsal. Oblivion Findings is one application for one organisation across many Sites. Always identify the exact Site and governed reference; never introduce a tenant scope.

## Safety boundary

- Never paste passwords, tokens, private keys, passphrases, lease identifiers, or returned secret material into Oblivion Findings, a terminal command, a ticket, a notification, or this runbook.
- Rotate the reusable value in the external secret manager first. Oblivion Findings stores only the external reference and encrypted short-lived lease lifecycle data.
- Use the Security & Devices Settings & audit workspace to rotate the external reference. Rotation suspends the reference immediately, blocks new leases, and contains every outstanding lease from all earlier versions.
- Keep the replacement suspended until **Test reference** succeeds. Do not bypass a failed test.
- If provider revocation is temporarily unavailable, the lease stays encrypted and marked `revoke_pending`; the one-minute reconciliation job retries it and irreversibly erases its identifier at authoritative expiry.

## Containment and recovery procedure

1. Record the incident or change reference, exact Site, affected provider, governed reference key, detection time, and responder. Do not record credential material.
2. In the external secret manager, disable or rotate the compromised value and preserve the provider audit evidence.
3. In **Security & Devices > Settings & audit > Credential references**, choose the exact Site and reference, then select **Rotate reference** and enter only the replacement external secret-manager path.
4. Confirm the reference is now **Suspended**, rotation is **Due**, and the last test is **Untested**. This state blocks new runtime delivery.
5. Select **Test reference**. A successful one-use test lease is revoked immediately. The reference becomes **Active / Current / Passed** only after that test succeeds.
6. Run the read-only executable containment verifier from the application release directory:

   ```powershell
   php artisan security-devices:verify-credential-containment <site-id> '<reference-key>' --require-active
   ```

   Expected result: `Credential containment verified` and `Outstanding prior leases: 0`. The command never accepts or prints secret material.
7. Review **Settings & audit** for the immutable rotation, test, issued, contained, deferred-revocation, and expiry evidence. If any prior lease remains pending before its expiry, keep the incident open and verify the one-minute `monitoring-maintenance` worker and secret-manager availability.
8. Confirm monitoring checks and approved Device commands recover using the new reference. Do not close the incident merely because the reference activated; verify the affected Site and Device paths.
9. Close the incident/change with safe evidence: exact Site, reference UUID, versions, timestamps, lease counts, test result, and provider audit identifier. Never attach logs or screenshots containing credential material.

## Executable rehearsal

The automated rehearsal uses a non-production secret-manager fixture and proves that an issued lease is encrypted at rest, rotation suspends new delivery, the old lease is revoked and erased, the replacement must be retested, and the verifier rejects incomplete containment:

```powershell
php artisan test tests/Feature/SecurityDevices/CredentialReferenceLeaseTest.php --filter="rehearses compromised credential containment"
```

Run the collector boundary alongside it to prove a copied configuration cannot be decrypted by a different collector identity:

```powershell
Push-Location collector
vendor\bin\pest tests/CollectorBoundaryTest.php --filter="revalidates target scope"
Pop-Location
```

## Escalation

- If the reference cannot be suspended or the Site/reference cannot be identified exactly, stop runtime use of the affected integration at the provider and escalate to the Security & Devices administrator.
- If revocation remains pending until expiry, preserve the safe lifecycle audit and investigate provider availability; the identifier is erased at expiry even if the provider remains unavailable.
- If the replacement test fails, leave the reference suspended, restore provider connectivity or correct the external reference, then retest. Never reactivate it manually.
