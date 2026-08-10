<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_topology_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id');
            $table->uuid('snapshot_uuid')->unique();
            $table->string('source', 128);
            $table->char('source_checkpoint_hash', 64);
            $table->uuid('source_envelope_id')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 16)->default('building');
            $table->unsignedInteger('node_count')->default(0);
            $table->unsignedInteger('edge_count')->default(0);
            $table->unsignedInteger('change_count')->default(0);
            $table->json('summary');
            $table->timestamps();

            $table->foreign('site_id', 'monitoring_topology_snapshot_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->unique(
                ['site_id', 'source', 'source_checkpoint_hash'],
                'monitoring_topology_snapshot_checkpoint_uq',
            );
            $table->index(
                ['site_id', 'source', 'status', 'captured_at'],
                'monitoring_topology_snapshot_latest_idx',
            );
        });

        Schema::create('monitoring_topology_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topology_snapshot_id');
            $table->foreignId('canonical_device_id')->nullable();
            $table->foreignId('discovery_candidate_id')->nullable();
            $table->char('observed_identity_hash', 64)->nullable();
            $table->char('node_key_hash', 64);
            $table->timestamps();

            $table->foreign('topology_snapshot_id', 'monitoring_topology_node_snapshot_fk')
                ->references('id')->on('monitoring_topology_snapshots')->restrictOnDelete();
            $table->foreign('canonical_device_id', 'monitoring_topology_node_device_fk')
                ->references('id')->on('devices')->restrictOnDelete();
            $table->foreign('discovery_candidate_id', 'monitoring_topology_node_candidate_fk')
                ->references('id')->on('monitoring_discovery_candidates')->restrictOnDelete();
            $table->unique(
                ['topology_snapshot_id', 'node_key_hash'],
                'monitoring_topology_node_key_uq',
            );
            $table->index(['canonical_device_id', 'topology_snapshot_id'], 'monitoring_topology_node_device_idx');
            $table->index(['discovery_candidate_id'], 'monitoring_topology_node_candidate_idx');
        });

        Schema::create('monitoring_topology_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topology_snapshot_id');
            $table->foreignId('from_node_id');
            $table->foreignId('to_node_id');
            $table->string('source', 32);
            $table->string('kind', 32);
            $table->string('local_port', 128)->nullable();
            $table->string('remote_port', 128)->nullable();
            $table->decimal('confidence', 5, 4);
            $table->json('evidence');
            $table->char('evidence_hash', 64);
            $table->char('edge_hash', 64);
            $table->char('content_hash', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->foreign('topology_snapshot_id', 'monitoring_topology_edge_snapshot_fk')
                ->references('id')->on('monitoring_topology_snapshots')->restrictOnDelete();
            $table->foreign('from_node_id', 'monitoring_topology_edge_from_fk')
                ->references('id')->on('monitoring_topology_nodes')->restrictOnDelete();
            $table->foreign('to_node_id', 'monitoring_topology_edge_to_fk')
                ->references('id')->on('monitoring_topology_nodes')->restrictOnDelete();
            $table->unique(
                ['topology_snapshot_id', 'edge_hash'],
                'monitoring_topology_edge_snapshot_hash_uq',
            );
            $table->index(['edge_hash', 'last_seen_at'], 'monitoring_topology_edge_history_idx');
            $table->index(['from_node_id', 'to_node_id'], 'monitoring_topology_edge_endpoints_idx');
        });

        Schema::create('monitoring_topology_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('previous_snapshot_id')->nullable();
            $table->foreignId('current_snapshot_id');
            $table->string('change_type', 16);
            $table->char('edge_hash', 64);
            $table->foreignId('before_edge_id')->nullable();
            $table->foreignId('after_edge_id')->nullable();
            $table->json('evidence');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('previous_snapshot_id', 'monitoring_topology_change_previous_fk')
                ->references('id')->on('monitoring_topology_snapshots')->restrictOnDelete();
            $table->foreign('current_snapshot_id', 'monitoring_topology_change_current_fk')
                ->references('id')->on('monitoring_topology_snapshots')->restrictOnDelete();
            $table->foreign('before_edge_id', 'monitoring_topology_change_before_edge_fk')
                ->references('id')->on('monitoring_topology_edges')->restrictOnDelete();
            $table->foreign('after_edge_id', 'monitoring_topology_change_after_edge_fk')
                ->references('id')->on('monitoring_topology_edges')->restrictOnDelete();
            $table->unique(
                ['current_snapshot_id', 'change_type', 'edge_hash'],
                'monitoring_topology_change_identity_uq',
            );
            $table->index(['current_snapshot_id', 'change_type'], 'monitoring_topology_change_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_topology_changes');
        Schema::dropIfExists('monitoring_topology_edges');
        Schema::dropIfExists('monitoring_topology_nodes');
        Schema::dropIfExists('monitoring_topology_snapshots');
    }
};
