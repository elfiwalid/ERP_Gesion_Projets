<?php
// database/migrations/2025_08_11_000900_create_documents_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('documents', function (Blueprint $t) {
      $t->id();
      $t->foreignId('projet_id')->constrained('projets')->cascadeOnDelete();
      $t->string('name');
      $t->string('type', 50);
      $t->string('path'); // Option A: JSON+path (pas d’upload fichier)
      $t->enum('status', ['brouillon','en_attente','valide','rejete'])->default('brouillon');
      $t->enum('validation_gate', ['admin','cts','none'])->default('none');
      $t->json('shared_with'); // ex: ["admin_general","chef_terrain_superieur"]
      $t->foreignId('uploaded_by')->constrained('users');
      $t->foreignId('reviewed_by')->nullable()->constrained('users');
      $t->timestamp('reviewed_at')->nullable();
      $t->text('review_comment')->nullable();
      $t->timestamps();
    });
  }
  public function down(): void {
    Schema::dropIfExists('documents');
  }
};
