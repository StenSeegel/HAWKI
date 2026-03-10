<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('translate_glossaries', function (Blueprint $table) {
            $table->id();
            $table->string('unique_name')->unique();
            $table->string('display_name');
            $table->string('domain');
            $table->text('description');
            $table->enum('visibility', ['private', 'team', 'org', 'public']);
            $table->string('editor_role')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('translate_glossary_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('glossary_id')->constrained('translate_glossaries')->onDelete('cascade');
            $table->string('source_language', 10);
            $table->string('target_language', 10);
            $table->text('source_term');
            $table->text('target_term');
            $table->boolean('case_sensitive')->default(false);
            $table->timestamps();

            // PostgreSQL and SQLite typically handle TEXT indexing directly
            $driver = DB::getDriverName();
            if ($driver !== 'mysql' && $driver !== 'mariadb') {
                $table->index(['glossary_id', 'source_language', 'target_language', 'source_term'], 'glossary_lookup_index');
            }
        });

        // Driver-specific index creation for MySQL/MariaDB
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('CREATE INDEX glossary_lookup_index ON translate_glossary_entries (glossary_id, source_language, target_language, source_term(191))');
        }

        // Add Check Constraints
        try {
            DB::statement('ALTER TABLE translate_glossary_entries ADD CONSTRAINT check_languages_diff CHECK (source_language <> target_language)');
            DB::statement("ALTER TABLE translate_glossary_entries ADD CONSTRAINT check_source_term_not_empty CHECK (source_term <> '')");
            DB::statement("ALTER TABLE translate_glossary_entries ADD CONSTRAINT check_target_term_not_empty CHECK (target_term <> '')");
        } catch (\Exception $e) {
            // Ignore if driver doesn't support CHECK constraints
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translate_glossary_entries');
        Schema::dropIfExists('translate_glossaries');
    }
};
