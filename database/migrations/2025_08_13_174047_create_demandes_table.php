<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('demandes', function (Blueprint $t) {
            $t->id();

            $t->foreignId('client_id')
              ->constrained('clients')
              ->cascadeOnDelete();

            // ENUM MySQL (PHP 7.4 friendly)
            $t->enum('type', ['BRIEF','APPEL_OFFRE']);
            $t->string('intitule')->nullable();
            $t->text('description')->nullable();
            $t->enum('statut', ['BROUILLON','EN_COURS','TERMINEE'])->default('EN_COURS');

            $t->foreignId('cree_par')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

            $t->timestamps();

            $t->index(['client_id','type','statut']);
        });
    }
    public function down(): void { Schema::dropIfExists('demandes'); }
};
