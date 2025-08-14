<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pieces', function (Blueprint $t) {
            $t->id();

            // Rattachement projet
            $t->foreignId('projet_id')
              ->constrained('projets')
              ->cascadeOnDelete();

            // Données de la pièce
            $t->string('nom');
            $t->text('description')->nullable();
            $t->boolean('obligatoire')->default(true);

            // Cycle de vie (mêmes valeurs que pour les docs de demande)
            $t->string('statut')->default('A_IMPORTER'); // A_IMPORTER | EN_COURS | VALIDE | REFUSE

            // Fichier stocké (disk "public" recommandé)
            $t->string('fichier_path')->nullable();

            // Auteur de l’upload
            $t->foreignId('uploaded_by')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

            // Assignation simple (sans assigned_role)
            $t->foreignId('assigned_user_id')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

            // Qui a effectué l’assignation (souvent AdminG)
            $t->foreignId('assigned_by')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

            // Échéance de remise/validation de la pièce
            $t->date('due_date')->nullable();

            $t->timestamps();

            // Index utiles
            $t->index(['projet_id', 'statut']);
            $t->index('assigned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces');
    }
};
