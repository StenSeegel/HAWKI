<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

class GeneratePasskeyMasterKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'passkey:generate-master-key {--show : Display the key instead of modifying files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new passkey master key for server-side passkey encryption';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $key = 'base64:'.base64_encode(
            Encrypter::generateKey(config('app.cipher'))
        );

        if ($this->option('show')) {
            $this->line('<comment>'.$key.'</comment>');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('Passkey Master Key generated successfully!');
        $this->newLine();
        
        $this->components->twoColumnDetail('<fg=green>Generated Key</>', $key);
        $this->newLine();
        
        $this->components->warn('IMPORTANT: Add this to your .env file:');
        $this->line("PASSKEY_MASTER_KEY={$key}");
        $this->newLine();
        
        $this->components->warn('Security Notes:');
        $this->line('  • Keep this key secret and secure');
        $this->line('  • Never commit this key to version control');
        $this->line('  • Back up this key securely - losing it means losing access to all passkeys');
        $this->line('  • Changing this key will invalidate all existing encrypted passkeys');
        $this->newLine();

        return self::SUCCESS;
    }
}
