<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRondoManagedAccessTables extends Migration
{
    public function up()
    {
        Schema::create('rondo_managed_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->unique();
            $table->boolean('oidc_only')->default(true);
            $table->unsignedInteger('session_generation')->default(1);
            $table->timestamp('created_by_rondo_at');
            $table->timestamp('deactivated_at')->nullable();
            $table->unsignedInteger('conversion_audit_id')->nullable();
            $table->timestamps();
        });

        Schema::create('rondo_mailbox_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('stable_key', 64)->unique();
            $table->unsignedInteger('mailbox_id')->nullable()->unique();
            $table->string('verified_name', 255)->nullable();
            $table->string('verified_email', 255)->nullable();
            $table->string('policy_version', 64)->nullable();
            $table->string('state', 32)->default('draft');
            $table->string('source', 16)->default('ui');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('rondo_managed_mailbox_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('stable_key', 64);
            $table->unsignedInteger('mailbox_id');
            $table->unsignedInteger('user_id');
            $table->timestamps();
            $table->unique(['stable_key', 'user_id']);
            $table->index(['mailbox_id', 'user_id']);
        });

        Schema::create('rondo_activity_delivery_queue', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 64);
            $table->unsignedInteger('conversation_id');
            $table->unsignedInteger('customer_id');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('state', 32)->default('pending');
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['event_type', 'conversation_id']);
            $table->index(['state', 'next_attempt_at']);
        });

        Schema::create('rondo_integration_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 64);
            $table->unsignedInteger('actor_user_id')->nullable();
            $table->string('stable_key', 64)->nullable();
            $table->unsignedInteger('old_mailbox_id')->nullable();
            $table->unsignedInteger('new_mailbox_id')->nullable();
            $table->unsignedInteger('affected_count')->default(0);
            $table->string('result', 32);
            $table->string('correlation_id', 16);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('rondo_integration_audit');
        Schema::dropIfExists('rondo_activity_delivery_queue');
        Schema::dropIfExists('rondo_managed_mailbox_users');
        Schema::dropIfExists('rondo_mailbox_mappings');
        Schema::dropIfExists('rondo_managed_users');
    }
}
