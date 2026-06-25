<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Transcription\TranscriptionTemplate;
use Illuminate\Database\Seeder;

class TranscriptionTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'user_id' => null,
                'name' => 'Mein Interview-Format',
                'structure' => [
                    ['type' => 'heading', 'level' => 1, 'text' => '{{titel}}'],
                    ['type' => 'text', 'text' => 'Datum: {{datum}} · {{teilnehmer}}'],
                    ['type' => 'section', 'heading' => 'Zusammenfassung', 'instruction' => 'Fasse das Gespräch in 3–4 Sätzen zusammen'],
                    ['type' => 'section', 'heading' => 'Wichtigste Entscheidungen', 'instruction' => 'Liste alle Entscheidungen als Stichpunkte'],
                    ['type' => 'section', 'heading' => 'Offene Aufgaben', 'instruction' => 'Extrahiere To-dos mit verantwortlicher Person'],
                ],
            ],
            [
                'user_id' => null,
                'name' => 'Meeting-Protokoll',
                'structure' => [
                    ['type' => 'heading', 'level' => 1, 'text' => 'Meeting-Protokoll: {{titel}}'],
                    ['type' => 'text', 'text' => 'Datum: {{datum}} · Dauer: {{dauer}}'],
                    ['type' => 'text', 'text' => 'Teilnehmer: {{teilnehmer}}'],
                    ['type' => 'section', 'heading' => 'Ergebnisse', 'instruction' => 'Fasse die wichtigsten Ergebnisse des Meetings zusammen.'],
                    ['type' => 'section', 'heading' => 'Beschlüsse', 'instruction' => 'Liste alle getroffenen Beschlüsse und Vereinbarungen als Stichpunkte.'],
                    ['type' => 'section', 'heading' => 'To-dos', 'instruction' => 'Erstelle eine To-do-Liste mit Aufgaben, Zuständigkeiten und Fristen.'],
                ],
            ],
            [
                'user_id' => null,
                'name' => 'Interview',
                'structure' => [
                    ['type' => 'heading', 'level' => 1, 'text' => 'Interview: {{titel}}'],
                    ['type' => 'text', 'text' => 'Datum: {{datum}} · Teilnehmer: {{teilnehmer}}'],
                    ['type' => 'section', 'heading' => 'Kernaussagen', 'instruction' => 'Fasse die Hauptthemen und wichtigsten Kernaussagen des Interviews zusammen.'],
                    ['type' => 'section', 'heading' => 'Zitate', 'instruction' => 'Extrahiere besonders prägnante und repräsentative Zitate aus dem Gespräch.'],
                    ['type' => 'section', 'heading' => 'Themen', 'instruction' => 'Gliedere das Gespräch in die behandelten Themenschwerpunkte.'],
                ],
            ],
            [
                'user_id' => null,
                'name' => 'Fokusgruppe',
                'structure' => [
                    ['type' => 'heading', 'level' => 1, 'text' => 'Fokusgruppe: {{titel}}'],
                    ['type' => 'text', 'text' => 'Datum: {{datum}} · Teilnehmer: {{teilnehmer}}'],
                    ['type' => 'section', 'heading' => 'Diskussion', 'instruction' => 'Fasse den Verlauf der Diskussion und die verschiedenen Meinungen zusammen.'],
                    ['type' => 'section', 'heading' => 'Moderation', 'instruction' => 'Analysiere die Rolle der Moderation und den Leitfaden.'],
                    ['type' => 'section', 'heading' => 'Kernthemen', 'instruction' => 'Identifiziere die zentralen Themen und Erkenntnisse aus der Gruppenbefragung.'],
                ],
            ],
        ];

        foreach ($templates as $tmpl) {
            TranscriptionTemplate::updateOrCreate(
                ['name' => $tmpl['name'], 'user_id' => $tmpl['user_id']],
                ['structure' => $tmpl['structure']]
            );
        }
    }
}
