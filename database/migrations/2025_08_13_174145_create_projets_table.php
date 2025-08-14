<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
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

            $t->enum('statut', ['BROUILLON','EN_VALIDATION','REFUSE','CLOTURE','ARCHIVE','PRET_DESIGN'])
              ->default('EN_VALIDATION');

            $t->timestamp('archived_at')->nullable();

            $t->foreignId('cree_par')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

            $t->timestamps();

            $t->index(['client_id','statut']);
            $t->index('demande_id');
        });
    }
    public function down(): void { Schema::dropIfExists('projets'); }
};
