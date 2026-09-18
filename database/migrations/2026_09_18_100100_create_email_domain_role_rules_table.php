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
        Schema::create('email_domain_role_rules', function (Blueprint $table) {
            $table->id();
            $table->string('pattern')->unique()->comment('Exact host (example.org) or subdomain wildcard (*.example.org)');
            // Orchid's roles table uses an int primary key, so the foreign key column
            // must be an unsigned int as well. foreignId() would create a bigint and
            // the constraint would be rejected. Mirrors employeetype_roles.
            $table->unsignedInteger('role_id')->comment('Reference to orchid roles table');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->integer('priority')->default(0)->comment('Lower value wins when several rules match');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_domain_role_rules');
    }
};
