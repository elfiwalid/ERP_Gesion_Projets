<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('raison_sociale');
            $t->string('contact_nom')->nullable();
            $t->string('contact_email')->nullable();
            $t->string('contact_telephone')->nullable();
            $t->text('adresse')->nullable();
            $t->json('metadonnees')->nullable();
            $t->timestamps();

            $t->index('raison_sociale');
        });
    }
    public function down(): void { Schema::dropIfExists('clients'); }
};
