<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projets', function (Blueprint $t) {
            $t->id();

            $t->foreignId('client_id')
              ->constrained('clients')
              ->cascadeOnDelete();

            $t->foreignId('demande_id')
              ->nullable()
              ->constrained('demandes')
              ->nullOnDelete();

            $t->string('nom');
            $t->date('date_debut')->nullable();
            $t->date('date_fin_prevue')->nullable();

            // Statut global du projet
            $t->string('statut')->default('EN_VALIDATION'); // StatutProjet
            $t->timestamp('archived_at')->nullable();

            // Créateur (RA)
            $t->foreignId('cree_par')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

            $t->timestamps();

            // Index utiles
            $t->index(['client_id', 'statut']);
            $t->index(['demande_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projets');
    }
};
