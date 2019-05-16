<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePlayersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('players', function (Blueprint $table) {
            $table->unsignedInteger('id')->unique();
            $table->string('name');
            $table->string('club_team');
            $table->unsignedTinyInteger('club_number')->nullable();
            $table->string('nationality');
            $table->unsignedTinyInteger('national_number')->nullable();
            $table->unsignedTinyInteger('height');
            $table->unsignedTinyInteger('weight');
            $table->unsignedTinyInteger('age');
            $table->enum('foot', ['Right foot', 'Left foot']);
            $table->enum('position', ['GK', 'CB', 'LB', 'RB', 'DMF', 'CMF', 'LMF', 'RMF', 'AMF', 'LWF', 'RWF', 'SS', 'CF']);
            $table->json('positions_all');
            $table->unsignedTinyInteger('overall_rating');
            $table->jsonb('abilities');
            $table->unsignedTinyInteger('max_level');
            $table->unsignedTinyInteger('overall_at_max_level');
            $table->json('abilities_all');
            $table->json('playing_styles');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('players');
    }
}
