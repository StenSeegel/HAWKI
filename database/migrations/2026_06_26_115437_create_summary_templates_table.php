<?php

declare(strict_types=1);

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
        // Drop the old transcription_templates table if it exists
        Schema::dropIfExists('transcription_templates');

        // Create the new summary_templates table
        Schema::create('summary_templates', function (Blueprint $table) {
            $table->string('id')->primary(); // Stable slug as primary key
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_builtin')->default(false);
            $table->json('sections');
            $table->json('output_format_hints')->nullable();
            $table->integer('version')->default(1);
            $table->timestamps();

            $table->index('user_id');
        });

        // Add summary_template_id to transcriptions table
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->string('summary_template_id')->nullable()->after('metadata');
            $table->foreign('summary_template_id')->references('id')->on('summary_templates')->onDelete('set null');
        });

        // Seed the built-in templates
        $builtInTemplates = [
            [
                'id' => 'legacy',
                'name' => 'Standard-Protokoll',
                'description' => 'Das standardmäßige HAWKI-Ergebnisprotokoll.',
                'is_builtin' => true,
                'sections' => json_encode([
                    [
                        'type' => 'section',
                        'heading' => '',
                        'instruction' => "Du bist ein Experte für Gesprächsprotokolle. Hier ist das Transkript eines Gesprächs. Erstelle ein professionelles Ergebnisprotokoll.\n\nStruktur:\n1. Titel/Thema (basierend auf dem Inhalt)\n2. Zusammenfassung (kurz und prägnant)\n3. Wichtigste Kernaussagen (als Stichpunkte)\n4. Beschlüsse und nächste Schritte (falls identifizierbar)\n\nSprache: Deutsch. Form: Professionell, sachlich.",
                    ],
                ]),
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'meeting-protocol',
                'name' => 'Meeting-Protokoll',
                'description' => 'Strukturiertes Protokoll für Meetings und Teambesprechungen.',
                'is_builtin' => true,
                'sections' => json_encode([
                    ['type' => 'heading', 'level' => 1, 'text' => 'Meeting-Protokoll: {{title}}'],
                    ['type' => 'text', 'text' => 'Datum: {{date}} · Dauer: {{duration}}'],
                    ['type' => 'text', 'text' => 'Teilnehmer: {{participants}}'],
                    ['type' => 'section', 'heading' => 'Ergebnisse', 'instruction' => 'Fasse die wichtigsten Ergebnisse des Meetings zusammen.'],
                    ['type' => 'section', 'heading' => 'Beschlüsse', 'instruction' => 'Liste alle getroffenen Beschlüsse und Vereinbarungen als Stichpunkte.'],
                    ['type' => 'section', 'heading' => 'To-dos', 'instruction' => 'Erstelle eine To-do-Liste mit Aufgaben, Zuständigkeiten und Fristen.'],
                ]),
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'interview',
                'name' => 'Interview',
                'description' => 'Auswertung von Einzelinterviews mit Fokus auf Zitate und Themen.',
                'is_builtin' => true,
                'sections' => json_encode([
                    ['type' => 'heading', 'level' => 1, 'text' => 'Interview: {{title}}'],
                    ['type' => 'text', 'text' => 'Datum: {{date}} · Teilnehmer: {{participants}}'],
                    ['type' => 'section', 'heading' => 'Kernaussagen', 'instruction' => 'Fasse die Hauptthemen und wichtigsten Kernaussagen des Interviews zusammen.'],
                    ['type' => 'section', 'heading' => 'Zitate', 'instruction' => 'Extrahiere besonders prägnante und repräsentative Zitate aus dem Gespräch.'],
                    ['type' => 'section', 'heading' => 'Themen', 'instruction' => 'Gliedere das Gespräch in die behandelten Themenschwerpunkte.'],
                ]),
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'focus-group',
                'name' => 'Fokusgruppe',
                'description' => 'Analyse von Gruppendiskussionen und Moderationsrunden.',
                'is_builtin' => true,
                'sections' => json_encode([
                    ['type' => 'heading', 'level' => 1, 'text' => 'Fokusgruppe: {{title}}'],
                    ['type' => 'text', 'text' => 'Datum: {{date}} · Teilnehmer: {{participants}}'],
                    ['type' => 'section', 'heading' => 'Diskussion', 'instruction' => 'Fasse den Verlauf der Diskussion und die verschiedenen Meinungen zusammen.'],
                    ['type' => 'section', 'heading' => 'Moderation', 'instruction' => 'Analysiere die Rolle der Moderation und den Leitfaden.'],
                    ['type' => 'section', 'heading' => 'Kernthemen', 'instruction' => 'Identifiziere die zentralen Themen und Erkenntnisse aus der Gruppenbefragung.'],
                ]),
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'mein-interview-format',
                'name' => 'Mein Interview-Format',
                'description' => 'Benutzerdefiniertes Format für strukturierte Interviews.',
                'is_builtin' => true,
                'sections' => json_encode([
                    ['type' => 'heading', 'level' => 1, 'text' => '{{title}}'],
                    ['type' => 'text', 'text' => 'Datum: {{date}} · {{participants}}'],
                    ['type' => 'section', 'heading' => 'Zusammenfassung', 'instruction' => 'Fasse das Gespräch in 3–4 Sätzen zusammen.'],
                    ['type' => 'section', 'heading' => 'Wichtigste Entscheidungen', 'instruction' => 'Liste alle Entscheidungen als Stichpunkte.'],
                    ['type' => 'section', 'heading' => 'Offene Aufgaben', 'instruction' => 'Extrahiere To-dos mit verantwortlicher Person.'],
                ]),
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('summary_templates')->insert($builtInTemplates);

        // Update existing transcriptions to link to legacy template if they have a summary
        DB::table('transcriptions')
            ->whereNotNull('metadata')
            ->where(function ($query) {
                $query->where('metadata', 'like', '%"summary"%');
            })
            ->update(['summary_template_id' => 'legacy']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropForeign(['summary_template_id']);
            $table->dropColumn('summary_template_id');
        });

        Schema::dropIfExists('summary_templates');
    }
};
