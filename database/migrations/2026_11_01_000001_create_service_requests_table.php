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
            // Never silently delete a user who still owns history.
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 150);
            $table->text('description');
            $table->string('category', 30);   // App\Enums\RequestCategory
            $table->string('priority', 20)->default('medium'); // App\Enums\RequestPriority
            $table->string('status', 20)->default('draft');    // App\Enums\RequestStatus

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('due_at')->nullable();      // SLA deadline, set when approved
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // "My requests", filtered by status (employee list, manager list via requester ids).
            $table->index(['requester_id', 'status']);
            // "Work assigned to me", filtered by status.
            $table->index(['assignee_id', 'status']);
            // Operations queue and every status filter, newest first.
            $table->index(['status', 'created_at']);
            // Overdue filter.
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
