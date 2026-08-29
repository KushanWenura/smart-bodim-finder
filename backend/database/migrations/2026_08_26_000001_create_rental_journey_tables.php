<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('proposed_at');
            $table->dateTime('alternative_at')->nullable();
            $table->string('status', 30)->default('requested');
            $table->string('tenant_note', 500)->nullable();
            $table->string('owner_note', 500)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['listing_id', 'status', 'proposed_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['owner_id', 'created_at']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('viewing_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->date('move_in_date');
            $table->date('move_out_date');
            $table->unsignedTinyInteger('occupants')->default(1);
            $table->string('status', 30)->default('requested');
            $table->string('tenant_message', 700)->nullable();
            $table->string('owner_message', 700)->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('owner_accepted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['listing_id', 'status', 'move_in_date', 'move_out_date'], 'reservation_availability_index');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['owner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('viewing_requests');
    }
};
