<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRondoOidcBindingsTable extends Migration
{
    public function up()
    {
        Schema::create('rondo_oidc_bindings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('active_user_id')->nullable()->unique();
            $table->unsignedInteger('last_user_id');
            $table->string('issuer', 500);
            $table->string('subject', 255);
            $table->char('identity_fingerprint', 64)->unique();
            $table->string('status', 32)->default('active');
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->foreign('active_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->index(['last_user_id', 'status']);
        });

        Schema::create('rondo_oidc_binding_recoveries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('token_hash', 64)->unique();
            $table->unsignedInteger('target_user_id');
            $table->unsignedInteger('actor_user_id');
            $table->text('reason');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['target_user_id', 'expires_at']);
        });

        Schema::create('rondo_oidc_binding_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 64);
            $table->unsignedInteger('target_user_id')->nullable();
            $table->unsignedInteger('actor_user_id')->nullable();
            $table->string('old_fingerprint', 16)->nullable();
            $table->string('new_fingerprint', 16)->nullable();
            $table->text('reason')->nullable();
            $table->string('correlation_id', 16);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['target_user_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('rondo_oidc_binding_audit');
        Schema::dropIfExists('rondo_oidc_binding_recoveries');
        Schema::dropIfExists('rondo_oidc_bindings');
    }
}
