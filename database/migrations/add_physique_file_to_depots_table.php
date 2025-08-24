// database/migrations/2025_08_22_000000_add_physique_file_to_depots_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('depots', function (Blueprint $table) {
            $table->string('physique_file_path')->nullable()->after('meta_physical');
            $table->string('physique_file_name')->nullable()->after('physique_file_path');
        });
    }
    public function down(): void {
        Schema::table('depots', function (Blueprint $table) {
            $table->dropColumn(['physique_file_path','physique_file_name']);
        });
    }
};
