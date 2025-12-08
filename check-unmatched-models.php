<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$parser = app(\App\Services\ModelInfo\ModelInfoParser::class);
$models = \App\Models\AiModel::all();
$notFound = [];

foreach ($models as $model) {
    $result = $parser->findModel($model->model_id);
    if ($result === null) {
        $notFound[] = [
            'id' => $model->id,
            'model_id' => $model->model_id,
            'provider' => $model->provider->name ?? 'N/A',
        ];
    }
}

if (empty($notFound)) {
    echo "✅ Alle Modelle wurden in der model_info.json gefunden!\n";
} else {
    echo "❌ Folgende Modelle wurden NICHT gefunden (".count($notFound)." von ".$models->count()."):\n\n";
    foreach ($notFound as $item) {
        echo "[ID: {$item['id']}] {$item['model_id']} ({$item['provider']})\n";
    }
}
