<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create("balances", function (Blueprint $table) {
            $table->id();
            $table->string("account_number");
            $table->date("last_day_of_month")->nullable();
            $table->double("final_balance");
            $table->timestamps();
            $table->unique(["account_number", "last_day_of_month"]);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists("balances");
    }
};
