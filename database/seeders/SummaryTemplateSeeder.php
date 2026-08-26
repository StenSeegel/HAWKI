<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Transcription\SummaryTemplate;
use Illuminate\Database\Seeder;

class SummaryTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'id' => 'legacy',
                'name' => 'Standard-Protokoll',
                'description' => 'Das standardmäßige HAWKI-Ergebnisprotokoll.',
                'is_builtin' => true,
                'sections' => [
                    [
                        'type' => 'section',
                        'heading' => '',
                        'instruction' => "Du bist ein Experte für Gesprächsprotokolle. Hier ist das Transkript eines Gesprächs. Erstelle ein professionelles Ergebnisprotokoll.\n\nStruktur:\n1. Titel/Thema (basierend auf dem Inhalt)\n2. Zusammenfassung (kurz und prägnant)\n3. Wichtigste Kernaussagen (als Stichpunkte)\n4. Beschlüsse und nächste Schritte (falls identifizierbar)\n\nSprache: Deutsch. Form: Professionell, sachlich.",
                    ],
                ],
                'version' => 1,
            ],
            [
                'id' => 'meeting-protocol',
                'name' => 'Meeting-Protokoll',
                'description' => 'Strukturiertes Protokoll für Meetings und Teambesprechungen.',
                'is_builtin' => true,
                'sections' => [
                    ['type' => 'heading', 'level' => 1, 'text' => 'Meeting-Protokoll: {{title}}'],
                    ['type' => 'text', 'text' => 'Datum: {{date}} · Dauer: {{duration}}'],
                    ['type' => 'text', 'text' => 'Teilnehmer: {{participants}}'],
                    ['type' => 'section', 'heading' => 'Ergebnisse', 'instruction' => 'Fasse die wichtigsten Ergebnisse des Meetings zusammen.'],
                    ['type' => 'section', 'heading' => 'Beschlüsse', 'instruction' => 'Liste alle getroffenen Beschlüsse und Vereinbarungen als Stichpunkte.'],
                    ['type' => 'section', 'heading' => 'To-dos', 'instruction' => 'Erstelle eine To-do-Liste mit Aufgaben, Zuständigkeiten und Fristen.'],
                ],
                'version' => 1,
            ],
            [
                'id' => 'interview',
                'name' => 'Interview',
                'description' => 'Auswertung von Einzelinterviews mit Fokus auf Zitate und Themen.',
                'is_builtin' => true,
                'sections' => [
                    ['type' => 'heading', 'level' => 1, 'text' => 'Interview: {{title}}'],
                    ['type' => 'text', 'text' => 'Datum: {{date}} · Teilnehmer: {{participants}}'],
                    ['type' => 'section', 'heading' => 'Kernaussagen', 'instruction' => 'Fasse die Hauptthemen und wichtigsten Kernaussagen des Interviews zusammen.'],
                    ['type' => 'section', 'heading' => 'Zitate', 'instruction' => 'Extrahiere besonders prägnante und repräsentative Zitate aus dem Gespräch.'],
                    ['type' => 'section', 'heading' => 'Themen', 'instruction' => 'Gliedere das Gespräch in die behandelten Themenschwerpunkte.'],
                ],
                'version' => 1,
            ],
            [
                'id' => 'focus-group',
                'name' => 'Fokusgruppe',
                'description' => 'Analyse von Gruppendiskussionen und Moderationsrunden.',
                'is_builtin' => true,
                'sections' => [
                    ['type' => 'heading', 'level' => 1, 'text' => 'Fokusgruppe: {{title}}'],
                    ['type' => 'text', 'text' => 'Datum: {{date}} · Teilnehmer: {{participants}}'],
                    ['type' => 'section', 'heading' => 'Diskussion', 'instruction' => 'Fasse den Verlauf der Diskussion und die verschiedenen Meinungen zusammen.'],
                    ['type' => 'section', 'heading' => 'Moderation', 'instruction' => 'Analysiere die Rolle der Moderation und den Leitfaden.'],
                    ['type' => 'section', 'heading' => 'Kernthemen', 'instruction' => 'Identifiziere die zentralen Themen und Erkenntnisse aus der Gruppenbefragung.'],
                ],
                'version' => 1,
            ],
            [
                'id' => 'mein-interview-format',
                'name' => 'Mein Interview-Format',
                'description' => 'Benutzerdefiniertes Format für strukturierte Interviews.',
                'is_builtin' => true,
                'sections' => [
                    ['type' => 'heading', 'level' => 1, 'text' => '{{title}}'],
                    ['type' => 'text', 'text' => 'Datum: {{date}} · {{participants}}'],
                    ['type' => 'section', 'heading' => 'Zusammenfassung', 'instruction' => 'Fasse das Gespräch in 3–4 Sätzen zusammen.'],
                    ['type' => 'section', 'heading' => 'Wichtigste Entscheidungen', 'instruction' => 'Liste alle Entscheidungen als Stichpunkte.'],
                    ['type' => 'section', 'heading' => 'Offene Aufgaben', 'instruction' => 'Extrahiere To-dos mit verantwortlicher Person.'],
                ],
                'version' => 1,
            ],
        ];

        foreach ($templates as $tmpl) {
            SummaryTemplate::updateOrCreate(
                ['id' => $tmpl['id']],
                [
                    'name' => $tmpl['name'],
                    'description' => $tmpl['description'],
                    'is_builtin' => $tmpl['is_builtin'],
                    'sections' => $tmpl['sections'],
                    'version' => $tmpl['version'],
                ]
            );
        }
    }
}
