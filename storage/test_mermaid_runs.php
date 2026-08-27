<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Translation\TextImprovementService;

$service = app(TextImprovementService::class);

$testScenarios = [
    // Scenarios for flowcharts
    1 => [
        'style' => 'Erstelle ein detailliertes Flowchart-Diagramm für einen Online-Bestellprozess mit Validierung und Bezahlungs-Entscheidungen.',
        'type' => 'flowchart'
    ],
    2 => [
        'style' => 'Erstelle ein Flowchart für einen User-Registrierungsprozess.',
        'type' => 'flowchart'
    ],
    // Scenarios for sequence diagrams
    3 => [
        'style' => 'Erstelle ein Sequence-Diagramm für eine OAuth2-Authentifizierung zwischen Client, Auth-Server und API-Gateway.',
        'type' => 'sequenceDiagram'
    ],
    4 => [
        'style' => 'Erstelle ein Sequence-Diagramm für das Senden einer Nachricht im Chat.',
        'type' => 'sequenceDiagram'
    ],
    // Scenarios for class diagrams
    5 => [
        'style' => 'Erstelle ein Class-Diagramm für ein E-Commerce System (User, Order, Product, Payment).',
        'type' => 'classDiagram'
    ],
    6 => [
        'style' => 'Erstelle ein Class-Diagramm für ein Bibliotheks-Verwaltungssystem.',
        'type' => 'classDiagram'
    ],
    // Scenarios for state diagrams
    7 => [
        'style' => 'Erstelle ein State-Diagramm-v2 für eine Ampelsteuerung (Red, Green, Yellow).',
        'type' => 'stateDiagram-v2'
    ],
    8 => [
        'style' => 'Erstelle ein State-Diagramm für ein Buchungssystem (Draft, Confirmed, Cancelled, Completed).',
        'type' => 'stateDiagram-v2'
    ],
    // Scenarios for Gantt / Pie
    9 => [
        'style' => 'Erstelle ein Gantt-Diagramm für einen vierwöchigen Projektplan.',
        'type' => 'gantt'
    ],
    10 => [
        'style' => 'Erstelle ein Pie-Chart für die Verteilung von mobilen Betriebssystemen.',
        'type' => 'pie'
    ]
];

echo "Running 10 generations for other diagram types...\n";

foreach ($testScenarios as $i => $scenario) {
    echo "\n--- RUN $i ({$scenario['type']}) ---\n";
    echo "Style instruction: {$scenario['style']}\n";
    try {
        $result = $service->improveText(
            text: '',
            sourceLang: null,
            targetLang: 'de',
            modelId: 'jlu/qwen3-coder-next',
            style: $scenario['style'],
            type: 'compose'
        );
        echo $result['text'] . "\n";
    } catch (\Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
}
