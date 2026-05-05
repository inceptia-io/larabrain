<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_brain_entities', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Categorisation
            $table->string('type', 64)->index();       // e.g. concept, fact, rule
            $table->string('key', 191)->unique();       // human-readable unique slug
            $table->string('name', 255);

            $table->text('description')->nullable();
            $table->json('metadata')->nullable();       // arbitrary extra attributes

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();

            // Composite index for type-scoped lookups
            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_brain_entities');
    }
};
