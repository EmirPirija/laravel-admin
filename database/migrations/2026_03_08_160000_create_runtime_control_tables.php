<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('runtime_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->longText('value')->nullable();
            $table->string('value_type', 32)->default('json');
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('runtime_feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name', 180)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(false)->index();
            $table->string('rollout_mode', 24)->default('global');
            $table->unsignedTinyInteger('rollout_percentage')->nullable();
            $table->longText('roles')->nullable();
            $table->longText('user_ids')->nullable();
            $table->longText('payload')->nullable();
            $table->longText('conditions')->nullable();
            $table->string('variant', 120)->nullable();
            $table->integer('priority')->default(0)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('runtime_announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('title', 255);
            $table->text('message');
            $table->string('level', 24)->default('info');
            $table->string('placement', 48)->default('global_top');
            $table->string('channel', 24)->default('web');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_dismissible')->default(true);
            $table->longText('roles')->nullable();
            $table->longText('user_ids')->nullable();
            $table->unsignedTinyInteger('rollout_percentage')->nullable();
            $table->string('action_label', 120)->nullable();
            $table->string('action_url', 500)->nullable();
            $table->longText('metadata')->nullable();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->integer('priority')->default(0)->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('runtime_announcement_reads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('announcement_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id'], 'runtime_announcement_reads_announcement_user_unique');
        });

        Schema::create('runtime_plan_limits', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_key', 80)->index();
            $table->string('resource_key', 120)->index();
            $table->integer('limit_value')->nullable();
            $table->string('period', 24)->default('month');
            $table->boolean('is_hard_limit')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->longText('metadata')->nullable();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['plan_key', 'resource_key', 'period'], 'runtime_plan_limits_unique');
        });

        Schema::create('runtime_user_limit_overrides', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('resource_key', 120)->index();
            $table->integer('limit_value')->nullable();
            $table->string('period', 24)->default('month');
            $table->boolean('is_hard_limit')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->string('reason', 255)->nullable();
            $table->longText('metadata')->nullable();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['user_id', 'resource_key', 'period'], 'runtime_user_limit_overrides_unique');
        });

        Schema::create('runtime_config_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('version')->default(1);
            $table->string('last_hash', 64)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
        });

        DB::table('runtime_config_versions')->insert([
            'id' => 1,
            'version' => 1,
            'last_hash' => null,
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_config_versions');
        Schema::dropIfExists('runtime_user_limit_overrides');
        Schema::dropIfExists('runtime_plan_limits');
        Schema::dropIfExists('runtime_announcement_reads');
        Schema::dropIfExists('runtime_announcements');
        Schema::dropIfExists('runtime_feature_flags');
        Schema::dropIfExists('runtime_settings');
    }
};
