<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_brain_relations', function (Blueprint $table) {
            $table->id();

            // Directed edge: source ──[type]──▶ target
            $table->foreignId('source_entity_id')
                ->constrained('app_brain_entities')
                ->cascadeOnDelete();

            $table->foreignId('target_entity_id')
                ->constrained('app_brain_entities')
                ->cascadeOnDelete();

            // Relation semantics, e.g. "parent_of", "related_to", "contradicts"
            $table->string('type', 64)->index();

            // Optional strength / confidence score  (0.0 – 1.0 range by convention)
            $table->float('weight', 4, 2)->default(1.00)->unsigned();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Prevent duplicate directed edges of the same type
            $table->unique(['source_entity_id', 'target_entity_id', 'type'], 'brain_relations_unique_edge');

            // Fast traversal indexes
            $table->index(['source_entity_id', 'type']);
            $table->index(['target_entity_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_brain_relations');
    }
};
