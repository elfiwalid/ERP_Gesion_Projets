<?php


// database/migrations/2025_08_24_000001_create_soutenances_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('soutenances', function (Blueprint $t) {
      $t->id();
      $t->foreignId('depot_id')->constrained('depots')->cascadeOnDelete();

      // Planning
      $t->timestamp('date_time')->nullable();
      $t->string('lieu')->nullable();
      $t->string('meeting_url')->nullable();

      // Statut en FR : PLANIFIÉE | TENUE | ANNULÉE
      $t->string('statut')->default('PLANIFIÉE');

      // Notes libres (optionnel)
      $t->text('notes')->nullable();

      // JSON compactant invités & feedbacks
      // invites: [{user_id, statut:"INVITÉ|ACCEPTÉ|REFUSÉ", notified_at}]
      $t->json('invites')->nullable();

      // feedbacks: [{user_id, commentaire, client_demande_complements, complements_details, created_at}]
      $t->json('feedbacks')->nullable();

      $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

      $t->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('soutenances');
  }
};
