<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase H: UniFi Access, kept in step through each Site's hub (its API answers only on the
 * Site's own network). One console per Site; its doors, people (matched to staff by employee
 * number), cards (only a hash and the printed number), visitors, policies and door events.
 * Card tokens themselves are never stored. Names on door events are cleared after 90 days (D14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_access_consoles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->string('address', 45);
            $table->unsignedSmallInteger('port')->default(12445);
            $table->string('state', 16)->default('waiting'); // waiting | syncing | ok | failed | untrusted
            $table->string('last_error', 191)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('events_until')->nullable();
            $table->timestamp('exit_buttons_confirmed_at')->nullable();
            $table->foreignId('exit_buttons_confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('security_access_doors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('console_id')->constrained('security_access_consoles')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->string('name', 191);
            $table->string('lock_state', 16)->nullable(); // locked | unlocked
            $table->string('door_state', 16)->nullable(); // open | closed
            $table->boolean('hub_bound')->default(false);
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['console_id', 'external_id'], 'sd_access_doors_external_unique');
        });

        Schema::create('security_access_people', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('console_id')->constrained('security_access_consoles')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('employee_number', 64)->nullable();
            $table->string('status', 16); // active | pending | deactivated
            $table->string('kind', 16)->default('unknown'); // staff | resident | unknown
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('match_state', 16)->default('unmatched'); // matched | suggested | unmatched
            $table->json('policy_ids')->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['console_id', 'external_id'], 'sd_access_people_external_unique');
            $table->index(['console_id', 'employee_number'], 'sd_access_people_employee_idx');
        });

        Schema::create('security_access_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('security_access_people')->cascadeOnDelete();
            $table->char('token_hash', 64); // sha256 of the card token; the token itself is never kept
            $table->string('display_id', 32)->nullable(); // the number printed on the card
            $table->string('status', 16)->default('active');
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['person_id', 'token_hash'], 'sd_access_cards_token_unique');
        });

        Schema::create('security_access_visitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('console_id')->constrained('security_access_consoles')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->string('name', 191);
            $table->string('status', 24)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['console_id', 'external_id'], 'sd_access_visitors_external_unique');
        });

        Schema::create('security_access_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('console_id')->constrained('security_access_consoles')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->string('name', 191);
            $table->json('door_ids')->nullable();
            $table->json('schedule')->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['console_id', 'external_id'], 'sd_access_policies_external_unique');
        });

        Schema::create('security_access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('console_id')->constrained('security_access_consoles')->cascadeOnDelete();
            $table->char('event_key', 64); // sha256 of the console's own event id
            $table->foreignId('door_id')->nullable()->constrained('security_access_doors')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('security_access_people')->nullOnDelete();
            $table->string('kind', 24); // opened | denied | forced | held_open | unlocked | other
            $table->string('result', 24)->nullable();
            $table->string('method', 16)->nullable(); // card | pin | app | face | fingerprint | button | remote | other
            $table->string('actor_name', 191)->nullable(); // cleared after 90 days (D14)
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['console_id', 'event_key'], 'sd_access_events_key_unique');
            $table->index(['console_id', 'occurred_at'], 'sd_access_events_time_idx');
            $table->index(['person_id', 'occurred_at'], 'sd_access_events_person_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_access_events');
        Schema::dropIfExists('security_access_policies');
        Schema::dropIfExists('security_access_visitors');
        Schema::dropIfExists('security_access_cards');
        Schema::dropIfExists('security_access_people');
        Schema::dropIfExists('security_access_doors');
        Schema::dropIfExists('security_access_consoles');
    }
};
