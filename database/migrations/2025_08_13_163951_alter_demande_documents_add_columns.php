<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('demande_documents', function (Blueprint $t) {
            // Colonnes principales
            $t->foreignId('demande_id')
              ->after('id')
              ->constrained('demandes')
              ->cascadeOnDelete();

            $t->string('nom')->after('demande_id');
            $t->boolean('is_brief')->default(false)->after('nom');

            // Cycle de vie
            $t->string('statut')->default('A_IMPORTER')->after('is_brief'); // A_IMPORTER | EN_COURS | VALIDE | REFUSE

            // Fichier
            $t->string('fichier_path')->nullable()->after('statut');

            // Auteur upload
            $t->foreignId('uploaded_by')
              ->nullable()
              ->after('fichier_path')
              ->constrained('users')
              ->nullOnDelete();

            // Index utiles
            $t->index(['demande_id','statut']);
            $t->index('is_brief');
        });
    }

    public function down(): void
    {
        Schema::table('demande_documents', function (Blueprint $t) {
            // Drop indexes
            $t->dropIndex(['demande_id','statut']);
            $t->dropIndex(['is_brief']);

            // Drop FKs
            $t->dropForeign(['demande_id']);
            $t->dropForeign(['uploaded_by']);

            // Drop colonnes
            $t->dropColumn(['demande_id','nom','is_brief','statut','fichier_path','uploaded_by']);
        });
    }
};
