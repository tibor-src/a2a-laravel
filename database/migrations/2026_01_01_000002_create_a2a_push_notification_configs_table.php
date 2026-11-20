<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a2a_push_notification_configs', function (Blueprint $table): void {
            $table->string('id');
            $table->string('task_id')->index();
            $table->json('payload');
            $table->timestamps();
            $table->primary(['id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a2a_push_notification_configs');
    }
};
