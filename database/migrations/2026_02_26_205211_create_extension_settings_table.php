<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_settings', function (Blueprint $table) {
            $table->id();
            $table->string('package');          // Composer package name, e.g. hawki/demo-extension
            $table->string('key');              // Setting key without package prefix, e.g. api_key
            $table->text('value')->nullable();  // Stored value
            $table->string('type')->default('string'); // string, boolean, integer, json
            $table->timestamps();

            $table->unique(['package', 'key']);
            $table->index('package');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_settings');
    }
};
