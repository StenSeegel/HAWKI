<?php

namespace App\Services\Profile;

use App\Models\PasskeyBackup;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;

class PasskeyService
{
    /**
     * Get master key for encrypting/decrypting passkeys
     * This key must be set in .env as PASSKEY_MASTER_KEY
     * 
     * @return string Base64-decoded master key
     * @throws \Exception if master key not configured
     */
    private function getMasterKey(): string
    {
        $masterKey = config('auth.passkey_master_key');
        
        if (empty($masterKey)) {
            throw new \Exception('PASSKEY_MASTER_KEY not configured. Run: php artisan passkey:generate-master-key');
        }
        
        return $masterKey;
    }

    /**
     * Encrypt passkey with master key for storage
     * Uses AES-256-GCM for authenticated encryption
     * 
     * @param string $passkey Plain text passkey
     * @return array ['ciphertext', 'iv', 'tag']
     * @throws \Exception on encryption failure
     */
    private function encryptPasskeyForStorage(string $passkey): array
    {
        try {
            // Generate IV for encryption (12 bytes for GCM mode)
            $iv = random_bytes(12);
            
            // Get and decode master key
            $masterKeyBase64 = $this->getMasterKey();
            
            // Remove "base64:" prefix if present
            if (str_starts_with($masterKeyBase64, 'base64:')) {
                $masterKeyBase64 = substr($masterKeyBase64, 7);
            }
            
            $masterKey = base64_decode($masterKeyBase64);
            
            if ($masterKey === false || strlen($masterKey) !== 32) {
                throw new \Exception('Invalid master key format');
            }
            
            // Encrypt passkey with AES-256-GCM
            $tag = '';
            $ciphertext = openssl_encrypt(
                $passkey,
                'aes-256-gcm',
                $masterKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16 // Tag length
            );
            
            if ($ciphertext === false) {
                throw new \Exception('Encryption failed: ' . openssl_error_string());
            }
            
            return [
                'ciphertext' => base64_encode($ciphertext),
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to encrypt passkey for storage', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Passkey encryption failed: ' . $e->getMessage());
        }
    }

    /**
     * Decrypt passkey from storage using master key
     * 
     * @param array $encrypted ['ciphertext', 'iv', 'tag']
     * @return string Decrypted passkey
     * @throws \Exception on decryption failure
     */
    private function decryptPasskeyFromStorage(array $encrypted): string
    {
        try {
            // Get and decode master key
            $masterKeyBase64 = $this->getMasterKey();
            
            // Remove "base64:" prefix if present
            if (str_starts_with($masterKeyBase64, 'base64:')) {
                $masterKeyBase64 = substr($masterKeyBase64, 7);
            }
            
            $masterKey = base64_decode($masterKeyBase64);
            
            if ($masterKey === false || strlen($masterKey) !== 32) {
                throw new \Exception('Invalid master key format');
            }
            
            // Decode stored values
            $ciphertext = base64_decode($encrypted['ciphertext']);
            $iv = base64_decode($encrypted['iv']);
            $tag = base64_decode($encrypted['tag']);
            
            if ($ciphertext === false || $iv === false || $tag === false) {
                throw new \Exception('Invalid encrypted data format');
            }
            
            // Decrypt passkey with AES-256-GCM
            $passkey = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $masterKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($passkey === false) {
                throw new \Exception('Decryption failed - invalid data or key: ' . openssl_error_string());
            }
            
            return $passkey;
            
        } catch (\Exception $e) {
            Log::error('Failed to decrypt passkey from storage', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Passkey decryption failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate deterministic system passkey based on immutable user attributes
     * Uses user_id (never changes) + created_at timestamp + server secret
     * 
     * @param User $user
     * @return string Hex-encoded passkey (64 characters)
     */
    private function generateSystemPasskey(User $user): string
    {
        // Use immutable attributes as basis
        // user_id: Primary key, absolutely immutable
        // created_at: Timestamp of user creation, immutable
        $basis = $user->id . '|' . $user->created_at->timestamp;
        
        // Server secret for additional entropy
        $serverSecret = config('auth.passkey_secret', 'default_secret');
        
        // Generate deterministic passkey using PBKDF2
        // 100,000 iterations for strong key derivation
        $passkey = hash_pbkdf2(
            'sha256',           // Algorithm
            $basis,             // Input/password
            $serverSecret,      // Salt
            100000,             // Iterations
            32,                 // Length (32 bytes = 256 bits)
            false               // Raw binary output = false (hex output)
        );
        
        Log::info('System passkey generated', [
            'user_id' => $user->id,
            'username' => $user->username,
        ]);
        
        return $passkey;
    }

    /**
     * Store user-generated passkey (from registration form)
     * Encrypts with master key before storing in database
     * 
     * @param User $user
     * @param string $passkey User-provided passkey
     * @return void
     * @throws \Exception on storage failure
     */
    public function storeUserPasskey(User $user, string $passkey): void
    {
        // Encrypt passkey with master key
        $encrypted = $this->encryptPasskeyForStorage($passkey);
        
        // Store in database
        PasskeyBackup::updateOrCreate(
            ['username' => $user->username],
            [
                'passkey_encrypted' => $encrypted['ciphertext'],
                'passkey_iv' => $encrypted['iv'],
                'passkey_tag' => $encrypted['tag'],
                'generation_method' => 'user',
                'passkey_encrypted_at' => now(),
            ]
        );
        
        Log::info('User passkey stored', [
            'user_id' => $user->id,
            'username' => $user->username,
            'method' => 'user',
        ]);
    }

    /**
     * Generate and store system passkey
     * Called during registration when passkey_method = 'system'
     * 
     * @param User $user
     * @return void
     * @throws \Exception on generation or storage failure
     */
    public function generateAndStoreSystemPasskey(User $user): void
    {
        // Generate deterministic passkey
        $passkey = $this->generateSystemPasskey($user);
        
        // Encrypt with master key
        $encrypted = $this->encryptPasskeyForStorage($passkey);
        
        // Store in database
        PasskeyBackup::updateOrCreate(
            ['username' => $user->username],
            [
                'passkey_encrypted' => $encrypted['ciphertext'],
                'passkey_iv' => $encrypted['iv'],
                'passkey_tag' => $encrypted['tag'],
                'generation_method' => 'system',
                'passkey_encrypted_at' => now(),
            ]
        );
        
        Log::info('System passkey generated and stored', [
            'user_id' => $user->id,
            'username' => $user->username,
            'method' => 'system',
        ]);
    }

    /**
     * Get user's passkey (decrypted)
     * Works for both user-generated and system-generated passkeys
     * 
     * @param User $user
     * @return string Decrypted passkey
     * @throws \Exception if passkey not found or decryption fails
     */
    public function getPasskey(User $user): string
    {
        $backup = PasskeyBackup::where('username', $user->username)->first();
        
        if (!$backup || !$backup->passkey_encrypted) {
            // No passkey found - might be legacy user or migration needed
            throw new \Exception("No passkey found for user {$user->username}. User may need to re-register.");
        }
        
        // Decrypt passkey from storage
        $passkey = $this->decryptPasskeyFromStorage([
            'ciphertext' => $backup->passkey_encrypted,
            'iv' => $backup->passkey_iv,
            'tag' => $backup->passkey_tag,
        ]);
        
        Log::debug('Passkey retrieved for user', [
            'user_id' => $user->id,
            'username' => $user->username,
            'method' => $backup->generation_method,
        ]);
        
        return $passkey;
    }

    /**
     * Get passkey generation method for user
     * 
     * @param User $user
     * @return string|null 'user' or 'system' or null if not found
     */
    public function getPasskeyMethod(User $user): ?string
    {
        $backup = PasskeyBackup::where('username', $user->username)->first();
        
        return $backup?->generation_method;
    }

    /**
     * Check if user has passkey stored
     * 
     * @param User $user
     * @return bool
     */
    public function hasPasskey(User $user): bool
    {
        $backup = PasskeyBackup::where('username', $user->username)->first();
        
        return $backup && !empty($backup->passkey_encrypted);
    }

    // ==========================================
    // EXISTING METHODS (Backup Code System)
    // These remain unchanged for backward compatibility
    // ==========================================

    /**
     * Backup passkey encrypted with backup code
     * This is the existing recovery mechanism - remains unchanged
     * 
     * @param array $data
     * @return void
     */
    public function backupPassKey(array $data): void
    {
        $userInfo = json_decode(Session::get('authenticatedUserInfo'), true);
        $username = $userInfo['username'];

        if($username != $userInfo['username']){
            throw new \Exception('Username comparison failed!');
        }

        PasskeyBackup::updateOrCreate(
            ['username' => $username],
            [
                'ciphertext' => $data['cipherText'],
                'iv' => $data['iv'],
                'tag' => $data['tag'],
            ]
        );
    }


    /**
     * Retrieve passkey backup (encrypted with backup code)
     * This is the existing recovery mechanism - remains unchanged
     * 
     * @return array
     */
    public function retrievePasskeyBackup(): array
    {
        $user = Auth::user();
        $backup = PasskeyBackup::where('username', $user->username)->firstOrFail();

        return [
            'ciphertext' => $backup->ciphertext,
            'iv' => $backup->iv,
            'tag' => $backup->tag,
        ];
    }

}
