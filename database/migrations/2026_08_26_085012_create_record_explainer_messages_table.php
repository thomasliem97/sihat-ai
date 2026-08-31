<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_explainer_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            $table->unsignedInteger('finding_index')->nullable();
            $table->json('selected_box')->nullable();
            $table->timestamps();

            $table->index(['medical_record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_explainer_messages');
    }
};
