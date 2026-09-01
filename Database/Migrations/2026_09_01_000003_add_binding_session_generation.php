<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBindingSessionGeneration extends Migration
{
    public function up()
    {
        Schema::table('rondo_oidc_bindings', function (Blueprint $table) {
            $table->unsignedInteger('session_generation')->default(1)->after('status');
        });
    }

    public function down()
    {
        Schema::table('rondo_oidc_bindings', function (Blueprint $table) {
            $table->dropColumn('session_generation');
        });
    }
}
