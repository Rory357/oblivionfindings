<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent Requests — the "please review and sign" workflow that links a pending
 * ClientConsent obligation (typically triggered by a device assignment draft)
 * to a family-portal signatory (welfare guardian, EPOA, next-of-kin).
 *
 * Staff initiates, recipient responds in the family portal, and on approval a
 * ClientConsent row is written with evidence_type='portal_signature'.
 *
 * Compliance frame: NZ Health Information Privacy Code 2020,
 * HDC Code of Rights Right 7 (informed consent) + Right 7(4) best-interests,
 * PPPR Act 1988 (substituted consent authority),
 * CRPD Article 12 (supported decision-making).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('consent_type_id')->constrained('consent_types')->cascadeOnDelete();

            // Who requested (staff) → who responds (family portal user).
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();

            // Snapshot of the recipient's relationship at request time. Taken from
            // client_portal_users.relation so it survives if the pivot changes.
            // Current valid values: 'client', 'next_of_kin'. Additional values
            // ('welfare_guardian', 'epoa_personal_care', 'parent_guardian',
            // 'court_appointed') are accepted for forward-compat; policy layer
            // decides which are authorised to consent for what.
            $table->string('recipient_relationship');

            // Polymorphic trigger — the draft entity that prompted this request.
            // For v1: DeviceAssignment. Nullable so we can also support standalone
            // consent requests later. Explicit index name keeps it under MySQL's
            // 64-char identifier limit (default name would be 71 chars).
            $table->nullableMorphs('triggering_subject', 'consent_requests_trigger_morph_idx');

            // Right-7 disclosure fields, composed by staff at request time.
            // These are shown verbatim to the recipient in the portal.
            $table->text('purpose');
            $table->text('least_restrictive_justification')->nullable();
            $table->text('data_scope')->nullable();
            $table->unsignedSmallInteger('retention_period_days')->nullable();
            $table->text('withdrawal_method_text')->nullable();
            $table->text('staff_notes')->nullable();

            $table->enum('status', [
                'pending',
                'approved',
                'declined',
                'cancelled',
                'expired',
            ])->default('pending');

            // Lifecycle timestamps
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at');

            // Response detail
            $table->text('response_notes')->nullable();
            $table->string('response_ip_address', 45)->nullable();
            $table->text('response_user_agent')->nullable();

            // On approval, link to the ClientConsent row we wrote.
            $table->foreignId('resulting_consent_id')->nullable()
                ->constrained('client_consents')->nullOnDelete();

            // Cancel (staff-initiated revocation before response)
            $table->foreignId('cancelled_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            // Append-only event log: [{event, actor_id, at, meta}, ...]
            $table->json('audit_trail')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
            $table->index(['recipient_user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_requests');
    }
};
