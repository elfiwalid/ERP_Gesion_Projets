<?php

// database/migrations/2025_08_22_000000_create_depots_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('depots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projet_id')->constrained('projets')->cascadeOnDelete();

            // EMAIL | PHYSIQUE | PLATEFORME
            $table->string('mode');

            // PENDING | SENT (ou autre si besoin)
            $table->string('status')->default('SENT');

            // Métadonnées par mode (JSON)
            $table->json('meta_email')->nullable();     // {to, subject, body}
            $table->json('meta_physical')->nullable();  // {ref, contact, motif}
            $table->json('meta_platform')->nullable();  // {url}

            // Traçabilité
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depots');
    }
};
