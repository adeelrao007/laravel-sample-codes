<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTradePreferencesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('trade_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')
                ->references('id')->on('vendors')->onDelete('cascade');
            $table->enum('inventory', ['private', 'public', 'customize'])->nullable();

            // by default 80% of trade value is considered as fair
            $table->unsignedSmallInteger('fair_trade')->default(80);

            // by default vendor is 100% interested in sneakers, apparels and accessories
            $table->unsignedSmallInteger('sneaker_interest')->default(100);
            $table->unsignedSmallInteger('apparel_interest')->default(100);
            $table->unsignedSmallInteger('accessories_interest')->default(100);

            $table->json('size_types')->nullable();
            $table->json('sneaker_sizes')->nullable();
            $table->json('apparel_sizes')->nullable();
            $table->json('brands')->nullable();
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
        Schema::dropIfExists('trade_preferences');
    }
}
