<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('modality')->default('unknown');
            $table->string('detected_modality')->nullable();
            $table->decimal('route_confidence', 5, 2)->nullable();
            $table->string('status')->default('pending');
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->string('language')->default('en');
            $table->decimal('overall_confidence', 5, 2)->nullable();
            $table->json('findings')->nullable();
            $table->json('physician_report')->nullable();
            $table->json('patient_report')->nullable();
            $table->json('citations')->nullable();
            $table->json('bounding_boxes')->nullable();
            $table->json('longitudinal_diff')->nullable();
            $table->json('guardrail_flags')->nullable();
            $table->json('pipeline_steps')->nullable();
            $table->json('agent_trace')->nullable();
            $table->json('findings_embedding')->nullable();
            $table->json('volume_meta')->nullable();
            $table->json('patch_meta')->nullable();
            $table->json('signed_physician_report')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('deidentified_at')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_records');
    }
};
