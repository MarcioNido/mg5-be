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
        Schema::create("categories", function (Blueprint $table) {
            $table->id();
            $table
                ->bigInteger("parent_id")
                ->unsigned()
                ->nullable();
            $table->string("name");
            $table->unsignedSmallInteger("level")->default(1);
            $table->enum("type", [
                "income",
                "deductions",
                "fixed expenses",
                "variable expenses",
                "financial transactions",
            ]);
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
        Schema::dropIfExists("categories");
    }
};
