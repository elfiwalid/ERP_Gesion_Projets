<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // 🔥 Supprimer la colonne shared_with
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('shared_with');
        });

        // 💥 La recréer avec les nouvelles valeurs
        Schema::table('documents', function (Blueprint $table) {
            $table->enum('shared_with', ['chef_terrain', 'chef_terrain_supérieur'])
                  ->default('chef_terrain_supérieur')
                  ->after('status');
        });
    }

    public function down()
    {
        // ⏪ Retour arrière
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('shared_with');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->enum('shared_with', ['chef_superchef', 'admin_superchef'])
                  ->default('chef_superchef')
                  ->after('status');
        });
    }
};
