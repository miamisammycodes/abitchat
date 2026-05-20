<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['super_admin', 'owner', 'manager', 'agent']);
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'tenant_id']);
            $table->index(['tenant_id', 'role']);
            $table->unique(['user_id', 'role', 'tenant_id'], 'user_roles_user_role_tenant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
