<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTeamsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->unsignedInteger('id')->unique();
            $table->unsignedInteger('league_id');
            $table->string('name');
            $table->string('uri');
            $table->unsignedTinyInteger('ovr');
            $table->unsignedTinyInteger('def');
            $table->unsignedTinyInteger('mid');
            $table->unsignedTinyInteger('fwd');
            $table->unsignedTinyInteger('phy');
            $table->unsignedTinyInteger('spd');
            $table->index(['league_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('teams');
    }
}
