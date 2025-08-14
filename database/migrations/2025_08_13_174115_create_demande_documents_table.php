<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('demande_documents', function (Blueprint $t) {
            $t->id();

            $t->foreignId('demande_id')
              ->constrained('demandes')
              ->cascadeOnDelete();

            $t->string('nom');
            $t->boolean('is_brief')->default(false);

            $t->enum('statut', ['A_IMPORTER','EN_COURS','VALIDE','REFUSE'])->default('A_IMPORTER');
            $t->string('fichier_path')->nullable();

            $t->foreignId('uploaded_by')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

            $t->timestamps();

            $t->index(['demande_id','statut']);
            $t->index('is_brief');
        });
    }
    public function down(): void { Schema::dropIfExists('demande_documents'); }
};
