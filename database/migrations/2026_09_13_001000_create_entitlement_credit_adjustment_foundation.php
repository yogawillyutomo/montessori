<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlement_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('child_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('session_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_plan_assignment_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedSmallInteger('base_quantity');
            $table->string('quantity_source')->default('plan');
            $table->text('confirmation_reason')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['child_enrollment_id', 'period_start'], 'entitlement_period_enrollment_start_unique');
            $table->index(['child_enrollment_id', 'period_start', 'period_end'], 'entitlement_period_range');
            $table->index(['status', 'period_start']);
        });

        Schema::create('entitlement_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entitlement_period_id')->constrained()->restrictOnDelete();
            $table->smallInteger('quantity_delta');
            $table->string('reason_code');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_adjustment_id')->nullable()->constrained('entitlement_adjustments')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entitlement_period_id', 'created_at']);
            $table->index('reversal_of_adjustment_id');
        });

        Schema::create('session_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entitlement_period_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence_no');
            $table->date('origin_period_start');
            $table->string('status')->default('available');
            $table->string('source_type')->default('base');
            $table->foreignId('source_adjustment_id')->nullable()->constrained('entitlement_adjustments')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_adjustment_id')->nullable()->constrained('entitlement_adjustments')->nullOnDelete();
            $table->timestamps();

            $table->unique(['entitlement_period_id', 'sequence_no']);
            $table->index(['entitlement_period_id', 'status']);
            $table->index(['entitlement_period_id', 'voided_at']);
        });

        Schema::table('child_session_bookings', function (Blueprint $table): void {
            $table->foreignId('session_credit_id')
                ->nullable()
                ->after('child_enrollment_id')
                ->constrained('session_credits')
                ->restrictOnDelete();
            $table->foreignId('credit_allocated_by')
                ->nullable()
                ->after('session_credit_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('credit_allocated_at')->nullable()->after('credit_allocated_by');
        });
    }

    public function down(): void
    {
        Schema::table('child_session_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('session_credit_id');
            $table->dropConstrainedForeignId('credit_allocated_by');
            $table->dropColumn('credit_allocated_at');
        });

        Schema::dropIfExists('session_credits');
        Schema::dropIfExists('entitlement_adjustments');
        Schema::dropIfExists('entitlement_periods');
    }
};
