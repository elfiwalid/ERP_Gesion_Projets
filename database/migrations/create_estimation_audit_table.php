<?php // database/migrations/2025_08_26_000002_create_estimation_audits_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('estimation_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('projet_id')->index();
            $table->unsignedBigInteger('actor_id')->index();
            $table->unsignedBigInteger('actor_role_id')->index();
            $table->string('action', 40); // save, approve, refuse
            $table->json('old_json')->nullable(); // snapshot avant
            $table->json('new_json')->nullable(); // snapshot après
            $table->text('motif')->nullable();    // pour refus
            $table->timestamps();

            $table->foreign('projet_id')->references('id')->on('projets')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('estimation_audits');
    }
};
