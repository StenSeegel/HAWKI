<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_verification_codes', function (Blueprint $table) {
            // Three wrong guesses close the step for a while. Without this the row was
            // simply deleted, and asking for a new code handed out three fresh guesses
            // straight away, which left the six digits open to being worked through.
            $table->timestamp('locked_until')
                ->nullable()
                ->after('attempts')
                ->comment('Set after too many wrong attempts; no code is issued until it passes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_verification_codes', function (Blueprint $table) {
            $table->dropColumn('locked_until');
        });
    }
};
