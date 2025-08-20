<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('project_finances', function (Blueprint $t) {
            // Clef étrangère vers projets + unique (1 finance par projet)
            $t->foreignId('projet_id')
              ->after('id')
              ->constrained('projets')
              ->cascadeOnDelete()
              ->unique();

            // Statut du cycle d’estimation
            $t->enum('estimation_statut', ['NONE','PENDING_CHEF','PENDING_ADMIN','APPROVED','REFUSED'])
              ->default('NONE')
              ->after('projet_id');

            // Fichier & métadonnées d’upload
            $t->string('estimation_file_path')->nullable()->after('estimation_statut');
            $t->unsignedBigInteger('estimation_uploaded_by')->nullable()->after('estimation_file_path');
            $t->unsignedBigInteger('estimation_uploaded_by_role_id')->nullable()->after('estimation_uploaded_by');
            $t->timestamp('estimation_uploaded_at')->nullable()->after('estimation_uploaded_by_role_id');

            // Validations
            $t->unsignedBigInteger('chef_approved_by')->nullable()->after('estimation_uploaded_at');
            $t->timestamp('chef_approved_at')->nullable()->after('chef_approved_by');

            $t->unsignedBigInteger('admin_approved_by')->nullable()->after('chef_approved_at');
            $t->timestamp('admin_approved_at')->nullable()->after('admin_approved_by');

            // Motif de refus
            $t->text('estimation_motif_refus')->nullable()->after('admin_approved_at');

            // (optionnel) FKs vers users
            $t->foreign('estimation_uploaded_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('chef_approved_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('admin_approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_finances', function (Blueprint $t) {
            // drop FKs si ta version MySQL le demande dans cet ordre
            $t->dropForeign(['admin_approved_by']);
            $t->dropForeign(['chef_approved_by']);
            $t->dropForeign(['estimation_uploaded_by']);
            $t->dropForeign(['projet_id']);

            $t->dropColumn([
                'projet_id',
                'estimation_statut',
                'estimation_file_path',
                'estimation_uploaded_by',
                'estimation_uploaded_by_role_id',
                'estimation_uploaded_at',
                'chef_approved_by',
                'chef_approved_at',
                'admin_approved_by',
                'admin_approved_at',
                'estimation_motif_refus',
            ]);
        });
    }
};
