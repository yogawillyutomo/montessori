<?php

use App\Models\ClassLevel;
use App\Models\SchoolClass;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('class_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->decimal('min_age_months', 5, 2)->nullable();
            $table->decimal('max_age_months', 5, 2)->nullable();
            $table->string('color')->default('sage');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('school_classes', function (Blueprint $table) {
            $table->foreignId('class_level_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        $levelSeeds = [
            ['name' => 'Infant', 'sequence' => 0, 'min_age_months' => 6, 'max_age_months' => 24, 'color' => 'blue'],
            ['name' => 'Sunny', 'sequence' => 1, 'min_age_months' => 24, 'max_age_months' => 48, 'color' => 'sage'],
            ['name' => 'Glow', 'sequence' => 2, 'min_age_months' => 48, 'max_age_months' => 72, 'color' => 'coral'],
        ];

        foreach ($levelSeeds as $seed) {
            ClassLevel::query()->firstOrCreate([
                'slug' => Str::slug($seed['name']),
            ], $seed);
        }

        SchoolClass::query()->get()->each(function (SchoolClass $schoolClass): void {
            $levelName = $schoolClass->name;

            if (preg_match('/^[a-zA-Z]+/', $schoolClass->name, $matches)) {
                $levelName = $matches[0];
            } elseif ($schoolClass->level) {
                $levelName = $schoolClass->level;
            }

            $level = ClassLevel::query()
                ->where('slug', Str::slug($levelName))
                ->orWhere('name', $levelName)
                ->first();

            if (! $level) {
                $level = ClassLevel::query()->create([
                    'name' => $levelName,
                    'slug' => Str::slug($levelName),
                    'sequence' => (int) ClassLevel::query()->max('sequence') + 1,
                    'color' => $schoolClass->color ?: 'sage',
                ]);
            }

            $schoolClass->update(['class_level_id' => $level->id]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_level_id');
        });

        Schema::dropIfExists('class_levels');
    }
};
