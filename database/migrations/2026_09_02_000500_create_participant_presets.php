<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 50);
            $table->timestamps();
            $table->unique(['user_id', 'organization_id', 'name'], 'participant_presets_owner_name_unique');
        });

        Schema::create('participant_preset_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_preset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role_type', 16);
            $table->string('display_name', 100);
            $table->boolean('is_external')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_preset_items');
        Schema::dropIfExists('participant_presets');
    }
};
