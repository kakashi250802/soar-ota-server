<?php

namespace App\Ota;

use App\Models\Channel;
use App\Models\SoarApp;
use App\Models\Update;

/**
 * Decides which update a channel offers. This is the one place that encodes the Expo-style
 * concepts: channel to branch pointer, platform, runtime and app version filters, rollout percentage.
 */
class UpdateSelector
{
    private ?string $appVersion = null;

    /**
     * @param  string|null  $platform  ios | android; null (legacy static layout) only matches `all` updates
     * @param  int|null  $runtime  the host version; null skips the filter (the device checks min_host_ver itself)
     * @param  string|null  $deviceId  null (legacy) means the server cannot split devices
     */
    public function select(SoarApp $app, Channel $channel, ?string $platform, ?int $runtime, ?string $deviceId, ?string $appVersion = null): ?Selection
    {
        $this->appVersion = $appVersion;
        $pick = $this->head($app, $channel->branch_id, $platform, $runtime, $deviceId);

        return $pick ? new Selection($pick, $pick->rollout_percent) : null;
    }

    /**
     * Newest update on a branch that this platform and runtime can use. With a device id, an
     * update the device is outside the rollout of is skipped, so a 25% rollout leaves the other
     * 75% of devices on the previous update instead of on nothing.
     */
    public function head(SoarApp $app, int $branchId, ?string $platform, ?int $runtime, ?string $deviceId = null): ?Update
    {
        $q = Update::query()
            ->where('app_id', $app->id)
            ->where('branch_id', $branchId)
            ->whereIn('platform', array_filter([$platform, 'all']));
        if ($runtime !== null) {
            $q->where(fn ($w) => $w->where('kind', Update::KIND_ROLLBACK_TO_EMBEDDED)->orWhere('min_host_ver', '<=', $runtime));
        }

        // Expo runtime version: an update pinned to an app version only reaches builds of that version.
        $q->where(fn ($w) => $w->whereNull('app_version')->orWhere('app_version', $this->appVersion ?? ''));
        $q->orderByDesc('version');
        if ($deviceId === null) {
            return $q->first()?->setRelation('app', $app);
        }
        foreach ($q->limit(50)->get() as $u) {
            if (! $u->isBundle() || RolloutBucket::includes($deviceId, $app->app_id, (int) $u->version, (int) $u->rollout_percent)) {
                return $u->setRelation('app', $app);
            }
        }

        return null;
    }
}
