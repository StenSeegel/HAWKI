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
        if (!Schema::hasColumn('api_providers', 'provider_logo_svg')) {
            Schema::table('api_providers', function (Blueprint $table) {
                $table->text('provider_logo_svg')->nullable()->after('display_order');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('api_providers', 'provider_logo_svg')) {
            Schema::table('api_providers', function (Blueprint $table) {
                $table->dropColumn('provider_logo_svg');
            });
        }
    }
};

