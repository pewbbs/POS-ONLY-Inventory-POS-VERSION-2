<?php

namespace App\Services;

use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SiteContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SiteContentService
{
    /** @return array<string, string> */
    public function defaults(): array
    {
        return [
            'site_title' => 'POSONLY — Less admin. More selling.',
            'meta_description' => 'POSONLY connects your inventory, point of sale, and daily reports in one simple workspace. Less admin. More selling.',
            'hero_kicker' => 'CLOUD INVENTORY + POINT OF SALE',
            'hero_title_line1' => 'LESS ADMIN.',
            'hero_title_line2' => 'MORE SELLING.',
            'hero_description' => 'From the first scan to the last sale, keep your business moving with inventory and POS that just make sense.',
            'primary_cta' => 'Start your workspace',
            'footer_tagline' => 'INVENTORY / POINT OF SALE / POSSIBILITIES',
            'support_email' => '',
        ];
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        $content = Schema::hasTable('site_contents') ? SiteContent::where('key', 'landing')->first() : null;
        $published = $this->normalize($content?->published ?? []);
        $draft = $this->normalize($content?->draft ?? $content?->published ?? []);

        return ['draft' => $draft, 'published' => $published, 'version' => $content?->version ?? 0,
            'updated_at' => $content?->updated_at?->toIso8601String(), 'published_at' => $content?->published_at?->toIso8601String(),
            'has_unpublished_changes' => $draft !== $published];
    }

    /** @param array<string, mixed> $content
     * @return array<string, string>
     */
    private function normalize(array $content): array
    {
        return array_replace($this->defaults(), array_intersect_key($content, $this->defaults()));
    }

    /** @param array<string, string> $content */
    public function saveDraft(PlatformAdmin $actor, int $version, array $content, string $reason): void
    {
        $this->mutate($actor, $version, $reason, $content);
    }

    public function publish(PlatformAdmin $actor, int $version, string $reason): void
    {
        $this->mutate($actor, $version, $reason);
    }

    /** @param array<string, string>|null $draft */
    private function mutate(PlatformAdmin $actor, int $version, string $reason, ?array $draft = null): void
    {
        DB::transaction(function () use ($actor, $version, $reason, $draft): void {
            $admin = PlatformAdmin::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($admin->is_active && $admin->password !== null, 403, 'Developer access is unavailable.');
            SiteContent::firstOrCreate(['key' => 'landing']);
            $content = SiteContent::where('key', 'landing')->lockForUpdate()->firstOrFail();
            abort_unless($content->version === $version, 409, 'Website content changed in another session. Reload the editor before saving.');
            if ($draft !== null) {
                $content->draft = $this->normalize($draft);
            } else {
                $content->published = $this->normalize($content->draft ?? $content->published ?? []);
                $content->published_at = now();
            }
            $content->version++;
            $content->save();
            PlatformAuditLog::create(['platform_admin_id' => $admin->id, 'tenant_id' => null,
                'action' => $draft !== null ? 'content.draft_saved' : 'content.published', 'reason' => $reason,
                'metadata' => ['version' => $content->version, 'fields' => array_keys($this->defaults())], 'created_at' => now()]);
        }, 5);
    }
}
