<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Soar app. `last_version` is the per-app monotonic counter that becomes the
        // signed manifest `version`; it is shared by every branch, so versions are comparable
        // across branches and a device only ever moves forward.
        Schema::create('apps', function (Blueprint $t) {
            $t->id();
            $t->string('app_id', 128)->unique();
            $t->string('name');
            $t->char('public_key', 64);
            $t->unsignedBigInteger('last_version')->default(0);
            $t->timestamps();
        });

        // Expo: branch = a stream of updates.
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $t->string('name', 64);
            $t->timestamps();
            $t->unique(['app_id', 'name']);
        });

        // Expo: channel = what a build listens to. Points at one branch.
        Schema::create('channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $t->string('name', 64);
            $t->foreignId('branch_id')->constrained('branches');
            // Optional per-tier client key (sha256). When set, GET /updates needs `soar-ota-key`.
            $t->char('client_key_hash', 64)->nullable();
            $t->timestamps();
            $t->unique(['app_id', 'name']);
        });

        // Expo: update group = one publish; it holds one update per platform.
        Schema::create('update_groups', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained('branches');
            $t->string('message', 500)->nullable();
            $t->string('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('updates', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $t->foreignId('group_id')->constrained('update_groups')->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained('branches');
            $t->unsignedBigInteger('version');            // signed manifest `version`
            $t->string('platform', 8)->default('all');    // all | ios | android
            $t->string('kind', 24)->default('bundle');    // bundle | rollback_to_embedded
            $t->unsignedInteger('min_host_ver')->default(1); // host binary floor
            $t->string('app_version', 32)->nullable();    // Expo runtime version: exact app version match when set
            $t->json('kernels')->nullable();
            $t->string('apply_mode', 16)->default('next_launch');
            $t->unsignedTinyInteger('rollout_percent')->default(100);
            $t->char('bundle_sha256', 64)->nullable();
            $t->unsignedBigInteger('bundle_size')->nullable();
            $t->foreignId('republished_from_id')->nullable()->constrained('updates');
            $t->string('message', 500)->nullable();
            $t->timestamps();
            $t->unique(['app_id', 'version']);
            $t->index(['app_id', 'branch_id', 'platform', 'version']);
        });

        Schema::create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->char('token_hash', 64)->unique();      // sha256 of the token; the token is shown once
            $t->foreignId('app_id')->nullable()->constrained('apps')->cascadeOnDelete(); // null: all apps
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['api_tokens', 'updates', 'update_groups', 'channels', 'branches', 'apps'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
