<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('demandes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete(); // requis
            $t->string('type');           // BRIEF | APPEL_OFFRE
            $t->string('intitule')->nullable();
            $t->text('description')->nullable();
            $t->string('statut')->default('EN_COURS'); // EN_COURS | BROUILLON | TERMINEE (MVP)
            $t->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['client_id','type','statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes');
    }
};
