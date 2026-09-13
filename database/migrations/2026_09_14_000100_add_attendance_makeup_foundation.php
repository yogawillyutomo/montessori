<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->unsignedBigInteger('class_session_id')->nullable()->change();
            $table->foreignId('child_session_booking_id')
                ->nullable()
                ->after('student_id')
                ->constrained('child_session_bookings')
                ->restrictOnDelete();
            $table->unique('child_session_booking_id', 'attendances_booking_unique');
        });

        DB::table('attendances')
            ->orderBy('id')
            ->eachById(function (object $attendance): void {
                $bookingIds = DB::table('child_session_bookings')
                    ->where('legacy_class_session_id', $attendance->class_session_id)
                    ->where('student_id', $attendance->student_id)
                    ->orderBy('id')
                    ->limit(2)
                    ->pluck('id');

                if ($bookingIds->count() === 1) {
                    DB::table('attendances')
                        ->where('id', $attendance->id)
                        ->update(['child_session_booking_id' => $bookingIds->first()]);
                }
            });

        Schema::create('makeup_eligibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_booking_id')->constrained('child_session_bookings')->restrictOnDelete();
            $table->foreignId('session_credit_id')->constrained('session_credits')->restrictOnDelete();
            $table->foreignId('source_attendance_id')->nullable()->constrained('attendances')->restrictOnDelete();
            $table->string('reason_category');
            $table->string('status')->default('pending');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->foreignId('fulfilled_by_booking_id')->nullable()->constrained('child_session_bookings')->restrictOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->unique('source_booking_id', 'makeup_eligibility_source_booking_unique');
            $table->unique('session_credit_id', 'makeup_eligibility_credit_unique');
            $table->index(['status', 'granted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('makeup_eligibilities');

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropUnique('attendances_booking_unique');
            $table->dropConstrainedForeignId('child_session_booking_id');
        });

        if (DB::table('attendances')->whereNull('class_session_id')->exists()) {
            throw new LogicException('Cannot restore non-null class_session_id while native booking attendance rows exist.');
        }

        Schema::table('attendances', function (Blueprint $table): void {
            $table->unsignedBigInteger('class_session_id')->nullable(false)->change();
        });
    }
};
