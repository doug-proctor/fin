<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monzo_sync_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->unsignedInteger('imported')->default(0);
            /**
             * The span of what actually arrived, which is empty on a run that
             * found nothing new.
             */
            $table->timestamp('oldest_booked_at')->nullable();
            $table->timestamp('newest_booked_at')->nullable();
            /** A failed run has to be visible, or it reads as a quiet night. */
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monzo_sync_reports');
    }
};
