<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateMissingSalts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crypto:generate-salts {--force : Force regeneration of existing salts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate missing encryption salts for HAWKI';

    /**
     * Salts that need to be generated
     */
    private array $requiredSalts = [
        'USERDATA_ENCRYPTION_SALT',
        'INVITATION_SALT',
        'AI_CRYPTO_SALT',
        'PASSKEY_SALT',
        'BACKUP_SALT',
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->newLine();
        $this->components->info('Checking encryption salts...');
        $this->newLine();

        $envPath = base_path('.env');
        
        if (!file_exists($envPath)) {
            $this->components->error('.env file not found!');
            return self::FAILURE;
        }

        $envContent = file_get_contents($envPath);
        $generated = [];
        $skipped = [];

        foreach ($this->requiredSalts as $saltName) {
            $currentValue = env($saltName);
            
            if (empty($currentValue) || $this->option('force')) {
                // Generate new salt (128 bytes = 1024 bits, base64 encoded)
                $newSalt = base64_encode(random_bytes(128));
                
                // Update .env file
                if (preg_match("/^{$saltName}=.*$/m", $envContent)) {
                    // Salt exists, replace it
                    $envContent = preg_replace(
                        "/^{$saltName}=.*$/m",
                        "{$saltName}={$newSalt}",
                        $envContent
                    );
                } else {
                    // Salt doesn't exist, append it
                    $envContent .= "\n{$saltName}={$newSalt}";
                }
                
                $generated[] = $saltName;
            } else {
                $skipped[] = $saltName;
            }
        }

        // Write updated content back to .env
        if (!empty($generated)) {
            file_put_contents($envPath, $envContent);
            
            $this->components->info('Generated salts:');
            foreach ($generated as $salt) {
                $this->line("  ✓ {$salt}");
            }
            $this->newLine();
        }

        if (!empty($skipped)) {
            $this->components->warn('Skipped (already set):');
            foreach ($skipped as $salt) {
                $this->line("  - {$salt}");
            }
            $this->newLine();
        }

        if (empty($generated)) {
            $this->components->info('All salts are already configured!');
            $this->line('Use --force to regenerate existing salts');
        } else {
            $this->components->warn('IMPORTANT:');
            $this->line('  • Salts have been added to your .env file');
            $this->line('  • Restart your application for changes to take effect');
            $this->line('  • Back up these salts securely');
            $this->line('  • Changing salts will invalidate existing encrypted data');
        }
        
        $this->newLine();

        return self::SUCCESS;
    }
}
