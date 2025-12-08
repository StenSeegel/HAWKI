<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the ai_model_infos master catalog table containing ALL models from models.hawki.info.
     * AI models reference this catalog via ai_model_info_id foreign key.
     * 
     * KONSOLIDIERTE MIGRATION - ersetzt:
     * - 2025_12_05_000001_create_ai_model_infos_table.php
     * - 2025_12_08_111651_change_provider_id_to_foreign_key_in_ai_model_infos.php
     * - 2025_12_08_120000_cleanup_ai_model_infos_table.php
     */
    public function up(): void
    {
        // Create ai_model_infos table (master catalog)
        Schema::create('ai_model_infos', function (Blueprint $table) {
            $table->id();

            // ========================================
            // Primary Identifier (from models.hawki.info)
            // ========================================

            // Unique model ID from JSON (e.g., "openai-usa/gpt-4o", "anthropic-usa/claude-3-opus")
            // Format: "hawki_provider_unique_name/base_model_id" für eindeutige Einträge
            $table->string('model_info_id')->unique()->index();
            
            // Base Model ID (ohne Provider-Prefix, z.B. "gpt-4o")
            $table->string('base_model_id', 255)->nullable()->index();
            
            // Model Family (z.B. 'gpt-4', 'claude-3', 'gemini-2')
            $table->string('model_family', 100)->nullable()->index();

            // ========================================
            // Provider Reference (Foreign Key)
            // ========================================

            // Foreign Key zu api_providers (HAWKI Provider)
            $table->foreignId('provider_id')
                ->nullable()
                ->constrained('api_providers')
                ->onDelete('set null');

            // ========================================
            // Base Model Metadata (from JSON)
            // ========================================

            $table->string('name')->nullable();
            $table->json('aliases')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_de')->nullable();
            $table->date('knowledge_cutoff')->nullable();
            $table->boolean('reasoning')->default(false);
            $table->boolean('tool_calling')->default(false);
            $table->boolean('open_weights')->default(false);
            $table->boolean('deprecated')->default(false);

            // ========================================
            // Input/Output Types
            // ========================================

            $table->json('input_types')->nullable();
            $table->json('output_types')->nullable();

            // ========================================
            // Parameters
            // ========================================

            $table->json('parameters')->nullable();
            $table->json('default_parameters')->nullable();

            // ========================================
            // Technical Specs (from provider in JSON)
            // ========================================

            $table->integer('context_length')->nullable();
            $table->integer('output_limit')->nullable();

            // ========================================
            // Pricing (USD - von models.hawki.info)
            // ========================================

            $table->decimal('price_input_usd', 20, 10)->nullable();
            $table->decimal('price_output_usd', 20, 10)->nullable();
            
            // EUR Pricing (HAWKI spezifisch)
            $table->decimal('price_input_eur', 20, 10)->nullable();
            $table->decimal('price_output_eur', 20, 10)->nullable();

            // ========================================
            // Import Tracking & Metadata
            // ========================================

            $table->timestamp('last_imported_at')->nullable();
            $table->string('import_source')->default('models.hawki.info'); // models.hawki.info, ollama, manual
            
            // Mode (chat, completion, embedding, image_generation, etc.)
            $table->string('mode', 50)->default('chat')->index();
            
            // Deprecation Info
            $table->date('deprecation_date')->nullable();
            
            // Source URL
            $table->string('source_url', 500)->nullable();
            
            // Zusätzliche Provider-spezifische Metadaten (JSON)
            // Speichert supports_*, supported_modalities, spezielle Pricing (batch, caching, etc.)
            $table->json('additional_metadata')->nullable();

            // ========================================
            // Field Locking (Custom Admin Values)
            // ========================================

            // Array of field names that have been customized by admin
            // These fields will NOT be overwritten during sync/import
            // Example: ['name', 'description_en', 'context_length']
            $table->json('locked_fields')->nullable()->comment('Fields locked by admin (custom values, not synced)');

            // ========================================
            // Timestamps
            // ========================================

            $table->timestamps();

            // ========================================
            // Indexes
            // ========================================

            $table->index('deprecated');
            $table->index('reasoning');
            $table->index('tool_calling');
            $table->index('open_weights');
            $table->index('deprecation_date');
            
            // Unique Constraint: Provider + Base Model ID = eindeutig
            // Erlaubt mehrere Einträge für dasselbe Basis-Modell bei verschiedenen Providern
            $table->unique(['provider_id', 'base_model_id'], 'unique_provider_model');
        });

        // Add ai_model_info_id to ai_models table
        Schema::table('ai_models', function (Blueprint $table) {
            // Foreign key to ai_model_infos (nullable - can be unlinked)
            $table->foreignId('ai_model_info_id')
                ->nullable()
                ->after('provider_id')
                ->constrained('ai_model_infos')
                ->onDelete('set null'); // If model info is deleted, just unlink

            // Matching candidates (JSON array of potential matches with scores)
            // Format: [{"ai_model_info_id": 123, "model_info_id": "openai-usa/gpt-4o", "score": 0.95, "match_type": "exact"}, ...]
            $table->json('matching_candidates')->nullable()->after('ai_model_info_id');

            // Matching metadata
            $table->enum('match_type', ['exact', 'fuzzy', 'manual', 'none'])
                ->default('none')
                ->after('matching_candidates');
            
            $table->timestamp('last_matched_at')->nullable()->after('match_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove columns from ai_models
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropForeign(['ai_model_info_id']);
            $table->dropColumn(['ai_model_info_id', 'matching_candidates', 'match_type', 'last_matched_at']);
        });

        // Drop ai_model_infos table
        Schema::dropIfExists('ai_model_infos');
    }
};
