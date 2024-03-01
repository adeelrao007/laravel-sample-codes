<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RecreateTradeOffersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //drop previous table
        Schema::dropIfExists('trade_offers');

        Schema::create('trade_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trade_id');
            $table->foreign('trade_id')
                ->references('id')->on('trades')->onDelete('cascade');
            $table->unsignedBigInteger('inventory_id');
            $table->foreign('inventory_id')->references('id')->on('inventories');
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('trade_offers');
        Schema::enableForeignKeyConstraints();

        //restore previous table schema
        Schema::create('trade_offers', function (Blueprint $table) {
            $table->id();
            $table->string('status'); // [ "pending", "approved" ]
            $table->integer('listing_items_id');
            $table->integer('price');
            $table->timestamps();
        });
    }
}
