<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('triage_session_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            $table->string('input_modality')->default('text');
            $table->string('stt_engine')->nullable();
            $table->timestamps();

            $table->index(['triage_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_messages');
    }
};
