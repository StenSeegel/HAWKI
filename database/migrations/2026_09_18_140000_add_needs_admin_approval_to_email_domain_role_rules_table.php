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
        Schema::table('email_domain_role_rules', function (Blueprint $table) {
            // A domain rule is already a statement of trust, so an address verified
            // against one does not have to wait for a second human decision. Rules
            // created before this column existed keep the cautious behaviour.
            $table->boolean('needs_admin_approval')
                ->default(true)
                ->after('role_id')
                ->comment('Whether a user matching this rule still waits for admin approval');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_domain_role_rules', function (Blueprint $table) {
            $table->dropColumn('needs_admin_approval');
        });
    }
};
