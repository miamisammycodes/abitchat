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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Free, Pro, Business
            $table->string('slug')->unique(); // free, pro, business
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('billing_period')->default('monthly'); // monthly, yearly
            $table->integer('conversations_limit')->default(-1); // -1 = unlimited
            $table->integer('messages_per_conversation')->default(-1);
            $table->integer('knowledge_items_limit')->default(-1);
            $table->integer('tokens_limit')->default(-1); // monthly token limit
            $table->integer('leads_limit')->default(-1);
            $table->json('features')->nullable(); // additional features as JSON
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Add plan_id to tenants table
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('plan')->constrained()->nullOnDelete();
            $table->timestamp('plan_expires_at')->nullable()->after('plan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn(['plan_id', 'plan_expires_at']);
        });

        Schema::dropIfExists('plans');
    }
};
