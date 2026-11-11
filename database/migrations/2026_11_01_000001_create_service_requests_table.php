<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 150);
            $table->text('description');
            $table->string('category', 30);
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('draft');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes for role-scoped filtering and queries
            $table->index(['requester_id', 'status']);
            $table->index(['assignee_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
