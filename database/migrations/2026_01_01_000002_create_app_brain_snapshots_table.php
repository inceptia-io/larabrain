<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_brain_snapshots', function (Blueprint $table) {
            $table->id();

            // NULL means a global/system-level snapshot (not tied to one entity)
            $table->foreignId('entity_id')
                ->nullable()
                ->constrained('app_brain_entities')
                ->nullOnDelete();

            // Monotonically increasing per entity (or globally when entity_id is null)
            $table->unsignedSmallInteger('version')->default(1);

            // Full state payload captured at snapshot time
            $table->json('payload');

            // SHA-256 hex digest of the JSON payload for integrity verification
            $table->char('hash', 64)->index();

            // Optional actor identifier (user ID, system name, etc.)
            $table->string('created_by', 191)->nullable()->index();

            $table->json('metadata')->nullable();

            // Snapshots are immutable — no updated_at, no soft-deletes
            $table->timestamp('created_at')->useCurrent();

            // Enforce one version number per entity (nulls are excluded by DB)
            $table->unique(['entity_id', 'version'], 'brain_snapshots_entity_version_unique');

            $table->index(['entity_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_brain_snapshots');
    }
};
