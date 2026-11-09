<?php

namespace App\Ota;

use App\Models\Branch;
use App\Models\Channel;
use App\Models\SoarApp;
use App\Models\Update;
use App\Models\UpdateGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Write side of the server: publishing, republishing, rolling back and moving channels, one
 * method per EAS command. Every new update takes the next per-app version, so versions only
 * ever grow and a rollback is a new, higher version that carries an older bundle.
 */
class ReleaseService
{
    public function __construct(private readonly BundleStore $bundles)
    {
    }

    /**
     * `eas update`: publishes bundle bytes to a branch. One update per requested platform;
     * `all` (the default) is a single update every platform and the legacy client can take.
     *
     * @param  array{message?: ?string, platforms?: list<string>, min_host_ver?: int, kernels?: list<string>, apply_mode?: string, rollout_percent?: int}  $o
     */
    public function publish(SoarApp $app, Branch $branch, string $bytes, array $o, ?string $by = null): UpdateGroup
    {
        $max = config('ota.max_bundle_bytes');
        if ($bytes === '' || strlen($bytes) > $max) {
            throw ValidationException::withMessages(['bundle' => "The bundle must be 1..$max bytes."]);
        }
        $sha = hash('sha256', $bytes);
        $this->bundles->put("{$app->app_id}/blobs/$sha.soarb", $bytes);

        $specs = array_map(fn (string $platform) => [
            'platform' => $platform,
            'kind' => Update::KIND_BUNDLE,
            'min_host_ver' => $o['min_host_ver'] ?? 1,
            'app_version' => $o['app_version'] ?? null,
            'kernels' => array_values(array_unique($o['kernels'] ?? [])),
            'apply_mode' => $o['apply_mode'] ?? ApplyMode::NextLaunch->value,
            'rollout_percent' => $o['rollout_percent'] ?? 100,
            'bundle_sha256' => $sha,
            'bundle_size' => strlen($bytes),
        ], array_values(array_unique($o['platforms'] ?? ['all'])));

        return $this->newGroup($app, $branch, $o['message'] ?? null, $by, $specs);
    }

    /** `eas update:republish`: copies a group's updates onto a branch as new updates (new versions). */
    public function republish(SoarApp $app, UpdateGroup $source, Branch $target, ?string $message = null, ?string $by = null): UpdateGroup
    {
        $specs = $source->updates->map(fn (Update $u) => [
            'platform' => $u->platform, 'kind' => $u->kind, 'min_host_ver' => $u->min_host_ver, 'app_version' => $u->app_version, 'kernels' => $u->kernels,
            'apply_mode' => $u->apply_mode, 'rollout_percent' => $u->rollout_percent, 'bundle_sha256' => $u->bundle_sha256,
            'bundle_size' => $u->bundle_size, 'republished_from_id' => $u->id,
        ])->all();

        return $this->newGroup($app, $target, $message ?? "Republish of {$source->uuid}", $by, $specs);
    }

    /** `eas update:rollback --embedded`: tells devices on this branch to run the embedded bundle. */
    public function rollbackToEmbedded(SoarApp $app, Branch $branch, ?string $message = null, ?string $by = null): UpdateGroup
    {
        return $this->newGroup($app, $branch, $message ?? 'Roll back to embedded', $by, [[
            'platform' => 'all', 'kind' => Update::KIND_ROLLBACK_TO_EMBEDDED, 'min_host_ver' => 0, 'kernels' => [],
            'apply_mode' => ApplyMode::NextLaunch->value, 'rollout_percent' => 100,
        ]]);
    }

    /** The group published just before the newest bundle group of a branch. */
    public function previousGroup(Branch $branch): ?UpdateGroup
    {
        return UpdateGroup::where('branch_id', $branch->id)->orderByDesc('id')->skip(1)->first();
    }

    /** `eas channel:edit --branch`: points a channel at another branch, keeping versions moving forward. */
    public function repoint(SoarApp $app, Channel $channel, Branch $branch): Channel
    {
        return DB::transaction(function () use ($app, $channel, $branch) {
            $this->keepForward($app, $channel->branch_id, $branch);
            $channel->update(['branch_id' => $branch->id]);

            return $channel;
        });
    }

    /**
     * A device only installs a version above the one it has. If the branch we switch to is behind
     * the branch we leave, republish its newest group so its head is the highest version again.
     */
    private function keepForward(SoarApp $app, int $oldBranchId, Branch $new): void
    {
        $oldHead = (int) Update::where('branch_id', $oldBranchId)->max('version');
        $newHead = (int) Update::where('branch_id', $new->id)->max('version');
        if ($newHead > 0 && $newHead < $oldHead) {
            $latest = UpdateGroup::where('branch_id', $new->id)->orderByDesc('id')->with('updates')->first();
            $this->republish($app, $latest, $new, 'Republished to stay ahead after a channel change');
        }
    }

    /** @param list<array<string, mixed>> $specs */
    private function newGroup(SoarApp $app, Branch $branch, ?string $message, ?string $by, array $specs): UpdateGroup
    {
        return DB::transaction(function () use ($app, $branch, $message, $by, $specs) {
            $locked = SoarApp::whereKey($app->id)->lockForUpdate()->firstOrFail();
            $group = UpdateGroup::create([
                'uuid' => (string) Str::uuid(), 'app_id' => $app->id, 'branch_id' => $branch->id, 'message' => $message, 'created_by' => $by,
            ]);
            $version = (int) $locked->last_version;
            foreach ($specs as $spec) {
                Update::create($spec + [
                    'uuid' => (string) Str::uuid(), 'app_id' => $app->id, 'group_id' => $group->id, 'branch_id' => $branch->id,
                    'version' => ++$version, 'message' => $message,
                ]);
            }
            $locked->update(['last_version' => $version]);

            return $group->load('updates');
        });
    }
}
