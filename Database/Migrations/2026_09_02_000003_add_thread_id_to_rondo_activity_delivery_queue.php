<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddThreadIdToRondoActivityDeliveryQueue extends Migration
{
    public function up()
    {
        Schema::table('rondo_activity_delivery_queue', function (Blueprint $table) {
            $table->dropUnique(['event_type', 'conversation_id']);
            $table->unsignedInteger('thread_id')->default(0)->after('conversation_id');
            $table->unique(['event_type', 'conversation_id', 'thread_id'], 'rondo_activity_event_unique');
        });
    }

    public function down()
    {
        DB::table('rondo_activity_delivery_queue')->where('thread_id', '>', 0)->delete();
        Schema::table('rondo_activity_delivery_queue', function (Blueprint $table) {
            $table->dropUnique('rondo_activity_event_unique');
            $table->dropColumn('thread_id');
            $table->unique(['event_type', 'conversation_id']);
        });
    }
}
