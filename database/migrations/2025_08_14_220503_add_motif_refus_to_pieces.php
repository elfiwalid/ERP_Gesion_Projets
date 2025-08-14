<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pieces', function (Blueprint $table) {
            if (!Schema::hasColumn('pieces', 'motif_refus')) {
                $table->text('motif_refus')->nullable()->after('fichier_path');
            }
        });
    }
    public function down(): void
    {
        Schema::table('pieces', function (Blueprint $table) {
            if (Schema::hasColumn('pieces', 'motif_refus')) {
                $table->dropColumn('motif_refus');
            }
        });
    }
};
