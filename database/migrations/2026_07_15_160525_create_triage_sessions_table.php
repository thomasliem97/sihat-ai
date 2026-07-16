<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role_context');
            $table->string('locale', 16)->default('');
            $table->string('status')->default('active');
            $table->string('urgency')->nullable();
            $table->string('chief_complaint')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['subject_user_id', 'status']);
            $table->index(['status', 'shared_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_sessions');
    }
};
