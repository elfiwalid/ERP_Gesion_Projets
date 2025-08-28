<?php
// database/migrations/2025_08_26_000001_create_estimation_states_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('estimation_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('projet_id')->unique()->index();
            $table->enum('statut', ['NONE','PENDING_CHEF','PENDING_ADMIN','APPROVED','REFUSED'])->default('NONE');
            $table->text('motif_refus')->nullable();

            // qui a saisi/modifié récemment
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->unsignedBigInteger('uploaded_by_role_id')->nullable();
            $table->timestamp('uploaded_at')->nullable();

            // validations
            $table->unsignedBigInteger('chef_approved_by')->nullable()->index();
            $table->timestamp('chef_approved_at')->nullable();
            $table->unsignedBigInteger('admin_approved_by')->nullable()->index();
            $table->timestamp('admin_approved_at')->nullable();

            $table->timestamps();

            $table->foreign('projet_id')->references('id')->on('projets')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('estimation_states');
    }
};
