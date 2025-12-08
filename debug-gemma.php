<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$parser = app(\App\Services\ModelInfo\ModelInfoParser::class);
$result = $parser->findModel('gemma-3-1b-it');

if ($result) {
    echo "✅ GEFUNDEN: {$result['id']}\n";
} else {
    echo "❌ NICHT GEFUNDEN\n\n";
    echo "Teste Regex-Patterns:\n";
    
    // Test Suffixes
    $test = 'gemma-3-1b-it';
    echo "Original: $test\n";
    
    $test1 = preg_replace('/-it$/', '', $test);
    echo "Nach -it entfernung: $test1\n";
    
    $test2 = preg_replace('/-\d+[a-z]?$/', '', $test1);
    echo "Nach -\\d+[a-z]? entfernung: $test2\n";
    
    $test3 = preg_replace('/-[1-9]b$/', '', $test2);
    echo "Nach -[1-9]b entfernung: $test3\n";
    
    // Check if gemma-3 exists
    echo "\nSuche nach 'gemma-3':\n";
    $result2 = $parser->findModel('gemma-3');
    if ($result2) {
        echo "✅ 'gemma-3' existiert: {$result2['id']}\n";
    } else {
        echo "❌ 'gemma-3' existiert NICHT\n";
    }
}
