<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('financial_offers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('projet_id')->unique();
            $t->decimal('total_cached', 12, 2)->default(0);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();

            $t->foreign('projet_id')->references('id')->on('projets')->onDelete('cascade');
        });

        Schema::create('financial_offer_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('financial_offer_id');
            $t->string('label', 255);
            $t->decimal('amount', 12, 2)->default(0);
            $t->unsignedInteger('position')->default(1);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();

            $t->foreign('financial_offer_id')
              ->references('id')->on('financial_offers')
              ->onDelete('cascade');

            $t->index(['financial_offer_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_offer_items');
        Schema::dropIfExists('financial_offers');
    }
};
