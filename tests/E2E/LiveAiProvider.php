<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Models\AiModel as AiModelRecord;
use App\Models\ApiFormat;
use App\Models\ApiProvider;
use App\Services\AI\Config\AiConfigService;
use Illuminate\Encryption\Encrypter;
use PDO;

/**
 * Copies one API provider and one of its models out of the running installation
 * into the test database, so an end-to-end test can send a real request with the
 * real key.
 *
 * Why it reads the live database rather than a set of E2E_* variables: the point
 * of these tests is to prove that the configuration an administrator actually
 * clicked together produces a working tool call. A key pasted into .env proves
 * that a key works, which is not the same thing, and it duplicates a secret that
 * already exists. Nothing is written back - the row is read, decrypted, and
 * re-created in the isolated sqlite database the test suite forces.
 *
 * The api_key column is encrypted with the APP_KEY of the installation, and
 * phpunit.xml deliberately forces a throwaway APP_KEY, so the real one is read
 * straight out of the .env file instead of from config().
 *
 * Run these tests inside the app container, where .env and the database host
 * both resolve the way the application resolves them:
 *
 *   docker exec hawki-dev-app vendor/bin/phpunit --group e2e
 *
 * From the host, point the database at the published port instead:
 *
 *   E2E_DB_HOST=127.0.0.1 vendor/bin/phpunit --group e2e
 */
final class LiveAiProvider
{
    /** @var array<string,string>|null */
    private static ?array $env = null;

    /**
     * Reads the provider and model from the live database and re-creates both in
     * the test database. Returns the model id to send requests with.
     *
     * @param  string  $providerName  api_providers.unique_name, e.g. 'openai'
     * @param  string  $modelId  ai_models.model_id, e.g. 'gpt-5.6-luna'
     */
    public static function seed(string $providerName, string $modelId): string
    {
        $pdo = self::connect();

        $provider = self::fetch(
            $pdo,
            'SELECT p.*, f.unique_name AS format_unique_name
               FROM api_providers p
               JOIN api_formats f ON f.id = p.api_format_id
              WHERE p.unique_name = ?',
            [$providerName]
        );

        if ($provider === null) {
            throw new SkipLiveProvider("No API provider '{$providerName}' is configured in the live database.");
        }

        $model = self::fetch(
            $pdo,
            'SELECT * FROM ai_models WHERE provider_id = ? AND model_id = ?',
            [$provider['id'], $modelId]
        );

        if ($model === null) {
            throw new SkipLiveProvider("The model '{$modelId}' is not configured on provider '{$providerName}'.");
        }

        $apiKey = self::decrypt((string) $provider['api_key']);

        if ($apiKey === '') {
            throw new SkipLiveProvider("Provider '{$providerName}' has no usable API key.");
        }

        // The formats and their endpoints come from the seeder, which the test
        // has already run; only the provider and the model are installation state.
        $format = ApiFormat::where('unique_name', $provider['format_unique_name'])->first();

        if ($format === null) {
            throw new SkipLiveProvider("The API format '{$provider['format_unique_name']}' is missing from the test database.");
        }

        // The seeder ships a placeholder row for the well known providers, so this
        // updates rather than inserts.
        $record = ApiProvider::updateOrCreate(['unique_name' => $providerName], [
            'provider_name' => (string) $provider['provider_name'],
            'api_format_id' => $format->id,
            'api_key' => $apiKey,
            'base_url' => (string) $provider['base_url'],
            'is_active' => true,
            'display_order' => 1,
            'additional_settings' => self::json($provider['additional_settings']),
        ]);

        AiModelRecord::updateOrCreate([
            'provider_id' => $record->id,
            'model_id' => $modelId,
        ], [
            'label' => (string) $model['label'],
            'is_active' => true,
            'is_visible' => true,
            'display_order' => 1,
            'settings' => self::json($model['settings']),
            'information' => self::json($model['information']),
        ]);

        app(AiConfigService::class)->clearCache();

        return $modelId;
    }

    private static function connect(): PDO
    {
        $host = self::env('E2E_DB_HOST') ?? self::env('DB_HOST') ?? '127.0.0.1';
        $port = self::env('DB_PORT') ?? '3306';
        $database = self::env('DB_DATABASE');
        $username = self::env('DB_USERNAME');

        if ($database === null || $username === null) {
            throw new SkipLiveProvider('No database credentials in .env; cannot read the live provider configuration.');
        }

        try {
            return new PDO(
                "mysql:host={$host};port={$port};dbname={$database}",
                $username,
                self::env('DB_PASSWORD') ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
        } catch (\PDOException $e) {
            throw new SkipLiveProvider("The live database at {$host}:{$port} is not reachable: ".$e->getMessage());
        }
    }

    /**
     * @param  array<int,mixed>  $bindings
     * @return array<string,mixed>|null
     */
    private static function fetch(PDO $pdo, string $sql, array $bindings): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private static function decrypt(string $ciphertext): string
    {
        $appKey = self::env('APP_KEY');

        if ($appKey === null || ! str_starts_with($appKey, 'base64:')) {
            throw new SkipLiveProvider('No base64 APP_KEY in .env; the stored API key cannot be decrypted.');
        }

        $encrypter = new Encrypter(
            base64_decode(substr($appKey, 7), true) ?: '',
            (string) config('app.cipher', 'AES-256-CBC')
        );

        try {
            // The 'encrypted' cast stores a plain string, not a serialized value.
            return $encrypter->decryptString($ciphertext);
        } catch (\Throwable $e) {
            throw new SkipLiveProvider(
                'The stored API key could not be decrypted with the APP_KEY in .env: '.$e->getMessage()
            );
        }
    }

    private static function json(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        return json_decode($value, true) ?: [];
    }

    private static function env(string $key): ?string
    {
        if (self::$env === null) {
            self::$env = [];
            $path = base_path('.env');

            if (is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                        continue;
                    }

                    [$name, $value] = explode('=', $line, 2);
                    self::$env[trim($name)] = trim(trim($value), "\"'");
                }
            }
        }

        // Deliberately not falling back to getenv(): phpunit.xml forces
        // DB_CONNECTION, DB_DATABASE and APP_KEY into the process environment to
        // keep the suite off the real database, and reading those here would ask
        // the live MySQL server for a database called ':memory:'. Only the E2E_*
        // keys, which nothing else sets, may come from the process.
        if (str_starts_with($key, 'E2E_')) {
            $fromProcess = getenv($key);

            if (is_string($fromProcess) && $fromProcess !== '') {
                return $fromProcess;
            }
        }

        return self::$env[$key] ?? null;
    }
}
