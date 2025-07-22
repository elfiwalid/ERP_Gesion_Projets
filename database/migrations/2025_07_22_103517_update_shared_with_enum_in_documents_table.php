<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;




return new class extends Migration {
    public function up()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('shared_with');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->enum('shared_with', [
                'chef_terrain',
                'chef_terrain_superieur',
                'admin_general',
                'responsable_administratif'
            ])->default('chef_terrain');
        });
    }

    public function down()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('shared_with');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->enum('shared_with', ['chef_terrain', 'chef_terrain_superieur'])->default('chef_terrain');
        });
    }
};
