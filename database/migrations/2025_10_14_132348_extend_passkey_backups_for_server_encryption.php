<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Extends passkey_backups table to support server-side passkey management
     * with master-key encryption for both user-generated and system-generated passkeys.
     */
    public function up(): void
    {
        Schema::table('passkey_backups', function (Blueprint $table) {
            // Passkey encrypted with server master key
            // This allows server to decrypt and use passkey for multi-device support
            $table->text('passkey_encrypted')->nullable()->after('tag');
            $table->string('passkey_iv')->nullable()->after('passkey_encrypted');
            $table->string('passkey_tag')->nullable()->after('passkey_iv');
            
            // Generation method: 'user' (user-entered) or 'system' (auto-generated)
            $table->enum('generation_method', ['user', 'system'])->default('user')->after('passkey_tag');
            
            // Timestamp when passkey was encrypted with master key
            $table->timestamp('passkey_encrypted_at')->nullable()->after('generation_method');
            
            // Note: Existing fields (ciphertext, iv, tag) remain for backup code system
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passkey_backups', function (Blueprint $table) {
            $table->dropColumn([
                'passkey_encrypted',
                'passkey_iv',
                'passkey_tag',
                'generation_method',
                'passkey_encrypted_at',
            ]);
        });
    }
};
