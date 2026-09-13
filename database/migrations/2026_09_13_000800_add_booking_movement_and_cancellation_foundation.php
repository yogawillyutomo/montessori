<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('child_session_bookings', function (Blueprint $table): void {
            $table->text('cancellation_reason')->nullable()->after('source_type');
            $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->boolean('capacity_override')->default(false)->after('cancelled_at');
            $table->foreignId('capacity_override_by')->nullable()->after('capacity_override')->constrained('users')->nullOnDelete();
            $table->text('capacity_override_reason')->nullable()->after('capacity_override_by');
        });

        Schema::table('session_occurrences', function (Blueprint $table): void {
            $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
        });

        Schema::create('booking_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_booking_id')->constrained('child_session_bookings')->restrictOnDelete();
            $table->foreignId('destination_booking_id')->constrained('child_session_bookings')->restrictOnDelete();
            $table->string('movement_type')->default('reschedule');
            $table->text('reason');
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('capacity_override')->default(false);
            $table->text('capacity_override_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('source_booking_id');
            $table->unique('destination_booking_id');
            $table->index(['movement_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_movements');

        Schema::table('session_occurrences', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
        });

        Schema::table('child_session_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('capacity_override_by');
            $table->dropColumn([
                'cancellation_reason',
                'cancelled_at',
                'capacity_override',
                'capacity_override_reason',
            ]);
        });
    }
};
