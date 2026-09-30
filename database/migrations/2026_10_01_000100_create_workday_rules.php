<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workday_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16)->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->char('start_month_day', 5)->nullable();
            $table->char('end_month_day', 5)->nullable();
            $table->boolean('is_workday')->default(false);
            $table->string('name', 100);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workday_rules');
    }
};
