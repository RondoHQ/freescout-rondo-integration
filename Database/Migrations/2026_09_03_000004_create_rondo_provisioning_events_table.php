<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRondoProvisioningEventsTable extends Migration
{
    public function up()
    {
        Schema::create('rondo_provisioning_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('event_id')->unique();
            $table->string('state', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'updated_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('rondo_provisioning_events');
    }
}
