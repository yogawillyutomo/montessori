<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('attendances')
            ->whereNull('marked_at')
            ->update(['status' => 'unmarked']);

        Schema::table('attendances', function (Blueprint $table): void {
            $table->string('status')->default('unmarked')->change();
        });
    }

    public function down(): void
    {
        DB::table('attendances')
            ->where('status', 'unmarked')
            ->update(['status' => 'present']);

        Schema::table('attendances', function (Blueprint $table): void {
            $table->string('status')->default('present')->change();
        });
    }
};
