<?php
/**
 * HAWKI - Environment Variables Preparation Script
 * Prepares environment variables for production deployment.
 */

$envFile = '/var/www/html/.env';

if (!file_exists($envFile)) {
    echo "⚠️  Warning: .env file not found\n";
    exit(1);
}

echo "🔧 Preparing environment variables...\n";
$envContent = file_get_contents($envFile);

$dockerEnvVars = [
    'APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL',
    'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'REDIS_HOST', 'REDIS_PORT', 'MAIL_HOST', 'MAIL_PORT',
    'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET',
    'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME',
];

$updated = false;
$warnings = [];

foreach ($dockerEnvVars as $varName) {
    $dockerValue = getenv($varName);
    
    if ($dockerValue !== false && $dockerValue !== '') {
        $pattern = '/^' . preg_quote($varName, '/') . '=.*/m';
        
        if (preg_match($pattern, $envContent)) {
            $envContent = preg_replace($pattern, $varName . '=' . $dockerValue, $envContent);
            $updated = true;
        } else {
            $envContent .= "\n" . $varName . '=' . $dockerValue;
            $updated = true;
        }
    } elseif (in_array($varName, ['APP_KEY', 'DB_HOST', 'DB_DATABASE'])) {
        $warnings[] = "⚠️  Critical variable {$varName} is not set!";
    }
}

if ($updated) {
    file_put_contents($envFile, $envContent);
    echo "✅ Environment variables updated\n";
} else {
    echo "ℹ️  No environment variables needed updating\n";
}

if (!empty($warnings)) {
    echo "\n" . implode("\n", $warnings) . "\n";
}

echo "✅ Environment preparation complete\n";
