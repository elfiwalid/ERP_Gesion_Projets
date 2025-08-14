<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pieces', function (Blueprint $t) {
            $t->id();

            $t->foreignId('projet_id')
              ->constrained('projets')
              ->cascadeOnDelete();

            $t->string('nom');
            $t->text('description')->nullable();
            $t->boolean('obligatoire')->default(true);

            $t->enum('statut', ['A_IMPORTER','EN_COURS','VALIDE','REFUSE'])
              ->default('A_IMPORTER');

            $t->string('fichier_path')->nullable();

            $t->foreignId('uploaded_by')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

            $t->foreignId('assigned_user_id')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

            $t->foreignId('assigned_by')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

            $t->date('due_date')->nullable();

            $t->timestamps();

            $t->index(['projet_id','statut']);
            $t->index('assigned_user_id');
        });
    }
    public function down(): void { Schema::dropIfExists('pieces'); }
};
