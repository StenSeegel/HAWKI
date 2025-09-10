<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('provider_settings', function (Blueprint $table) {
            // Add display_name column after provider_name
            $table->string('display_name')->nullable()->after('provider_name');
        });
        
        // Populate display_name with current provider_name values
        DB::table('provider_settings')->get()->each(function ($provider) {
            DB::table('provider_settings')
                ->where('id', $provider->id)
                ->update(['display_name' => $provider->provider_name]);
        });
        
        // Make display_name not nullable after populating
        Schema::table('provider_settings', function (Blueprint $table) {
            $table->string('display_name')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_settings', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
