<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail. Rows are never updated, so there is no updated_at.
     */
    public function up(): void
    {
        Schema::create('request_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);                       // App\Enums\ActivityType
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('comment')->nullable();              // the optional note given with a transition
            $table->json('meta')->nullable();                 // e.g. changed fields, assignee ids, comment id
            $table->timestamp('created_at')->useCurrent();

            // "Show me the history of request N, oldest first."
            $table->index(['service_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_activities');
    }
};
