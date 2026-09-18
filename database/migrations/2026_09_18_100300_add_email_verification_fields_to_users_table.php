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
        Schema::table('users', function (Blueprint $table) {
            // The column Laravel ships with by default is missing from this schema,
            // so it is added here together with the rule reference.
            if (! Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }

            $table->foreignId('domain_rule_id')
                ->nullable()
                ->after('employeetype')
                ->constrained('email_domain_role_rules')
                ->nullOnDelete()
                ->comment('The domain rule that assigned the role of this user');
        });

        // Every account that exists before this feature is considered verified,
        // otherwise the new guards would lock out the whole installation.
        \Illuminate\Support\Facades\DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('domain_rule_id');
            $table->dropColumn('email_verified_at');
        });
    }
};
