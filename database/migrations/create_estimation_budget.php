<?php


// database/migrations/2025_08_26_000001_create_project_finance_items.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('project_finance_items', function (Blueprint $table) {
      $table->id();
      $table->unsignedBigInteger('projet_id');
      $table->string('label');
      $table->decimal('amount', 12, 2);              // prix unitaire / montant
      $table->unsignedInteger('position')->default(1);
      $table->unsignedBigInteger('created_by')->nullable();
      $table->unsignedBigInteger('updated_by')->nullable();
      $table->timestamps();

      $table->foreign('projet_id')->references('id')->on('projets')->onDelete('cascade');
    });
  }
  public function down(): void {
    Schema::dropIfExists('project_finance_items');
  }
};
