<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_finances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('projet_id')->constrained('projets')->cascadeOnDelete()->unique();

            $t->enum('estimation_statut', ['NONE','PENDING_CHEF','PENDING_ADMIN','APPROVED','REFUSED'])->default('NONE');

            $t->string('estimation_file_path')->nullable();
            $t->unsignedBigInteger('estimation_uploaded_by')->nullable();
            $t->unsignedBigInteger('estimation_uploaded_by_role_id')->nullable();
            $t->timestamp('estimation_uploaded_at')->nullable();

            $t->unsignedBigInteger('chef_approved_by')->nullable();
            $t->timestamp('chef_approved_at')->nullable();

            $t->unsignedBigInteger('admin_approved_by')->nullable();
            $t->timestamp('admin_approved_at')->nullable();

            $t->text('estimation_motif_refus')->nullable();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_finances');
    }
};
