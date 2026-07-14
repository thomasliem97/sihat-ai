<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biomarkers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medical_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('value', 12, 4);
            $table->string('unit');
            $table->decimal('reference_low', 12, 4)->nullable();
            $table->decimal('reference_high', 12, 4)->nullable();
            $table->string('status')->default('normal');
            $table->timestamp('collected_at');
            $table->timestamps();

            $table->index(['user_id', 'name', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biomarkers');
    }
};
