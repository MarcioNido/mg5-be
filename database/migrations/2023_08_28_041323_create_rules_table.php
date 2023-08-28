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
        Schema::create("rules", function (Blueprint $table) {
            $table->id();
            $table->string("content");
            $table->string("account_number")->nullable();
            $table
                ->foreign("account_number")
                ->references("account_number")
                ->on("accounts")
                ->onDelete("cascade");
            $table
                ->foreignId("category_id")
                ->constrained("categories")
                ->onDelete("cascade");
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists("rules");
    }
};
