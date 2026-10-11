<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReceiptBrandingTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{tenant: Tenant, user: User} */
    private function company(string $role = 'admin'): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Registered Company & Co.']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);

        return compact('tenant', 'user');
    }

    private function uploadLogo(array $company, string $filename = 'logo.png'): string
    {
        $this->actingAs($company['user'])->post('/api/settings', [
            '_method' => 'PUT', 'receipt_logo' => UploadedFile::fake()->image($filename, 120, 60),
        ], ['Accept' => 'application/json'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        return $company['tenant']->refresh()->settings['receipt_logo_path'];
    }

    public function test_admin_saves_multipart_branding_with_private_logo_and_public_settings_only(): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $original = $company['tenant']->settings;

        $response = $this->actingAs($company['user'])->post('/api/settings', [
            '_method' => 'PUT', 'receipt_address' => "Unit 1, Manila\nPhilippines", 'receipt_phone' => '+63 900 123 4567',
            'receipt_footer' => 'Thank you for visiting our shop.', 'receipt_logo' => UploadedFile::fake()->image('my-company.png', 100, 80),
            'tenant_id' => 999, 'receipt_logo_path' => 'someone-elses-file', 'receipt_logo_url' => 'https://foreign.invalid/image',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('receipt_address', "Unit 1, Manila\nPhilippines")->assertJsonPath('receipt_phone', '+63 900 123 4567')
            ->assertJsonPath('receipt_footer', 'Thank you for visiting our shop.')->assertJsonMissingPath('receipt_logo_path');

        $path = $company['tenant']->refresh()->settings['receipt_logo_path'];
        $url = $response->json('receipt_logo_url');
        $this->assertStringStartsWith('receipt-logos/tenant-'.$company['tenant']->id.'/', $path);
        $this->assertStringStartsWith('/api/settings/receipt-logo?v=', $url);
        $this->assertStringNotContainsString($path, $response->getContent());
        $this->assertSame($original['plan'], $response->json('plan'));
        $this->assertSame($original['tax_percent'], $response->json('tax_percent'));
        $this->assertSame('Registered Company & Co.', $company['tenant']->name);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('private', Storage::disk('local')->getVisibility($path));
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    #[DataProvider('allowedImages')]
    public function test_logo_serves_allowed_image_bytes_with_safe_private_headers(string $filename, string $mime): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $path = $this->uploadLogo($company, $filename);

        $response = $this->get('/api/settings/receipt-logo')->assertOk()->assertHeader('Content-Type', $mime)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(Storage::disk('local')->path($path), $response->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith('inline; filename=receipt-logo.', $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString($path, json_encode($response->headers->all()));
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public static function allowedImages(): array
    {
        return ['PNG' => ['company.png', 'image/png'], 'JPEG' => ['company.jpeg', 'image/jpeg'], 'WebP' => ['company.webp', 'image/webp']];
    }

    public function test_replacement_changes_public_url_and_removes_old_file_only_after_save(): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $first = $this->uploadLogo($company);
        $originalUrl = $company['tenant']->publicSettings()['receipt_logo_url'];
        $second = $this->uploadLogo($company, 'replacement.jpg');

        $this->assertNotSame($first, $second);
        $this->assertNotSame($originalUrl, $company['tenant']->publicSettings()['receipt_logo_url']);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        Storage::disk('local')->assertCount('receipt-logos/tenant-'.$company['tenant']->id, 1);
        $this->get($originalUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public function test_removal_clears_logo_without_losing_other_company_settings(): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $path = $this->uploadLogo($company);
        $this->putJson('/api/settings', ['receipt_address' => 'Manila', 'receipt_phone' => '+63 900'])->assertOk();

        $this->putJson('/api/settings', ['remove_receipt_logo' => true])->assertOk()->assertJsonPath('receipt_logo_url', null)
            ->assertJsonPath('receipt_address', 'Manila')->assertJsonPath('receipt_phone', '+63 900')->assertJsonPath('currency', 'PHP');

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($company['tenant']->refresh()->receiptLogoPath());
        $this->getJson('/api/settings/receipt-logo')->assertNotFound();
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public function test_text_only_updates_preserve_logo_and_unrelated_settings(): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $path = $this->uploadLogo($company);

        $response = $this->putJson('/api/settings', ['receipt_address' => 'Updated address', 'receipt_phone' => null])->assertOk()
            ->assertJsonPath('receipt_address', 'Updated address')->assertJsonPath('receipt_phone', null)->assertJsonPath('plan', 'grow');

        $this->assertNotNull($response->json('receipt_logo_url'));
        $this->assertSame($path, $company['tenant']->refresh()->receiptLogoPath());
        Storage::disk('local')->assertCount('receipt-logos/tenant-'.$company['tenant']->id, 1);
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    #[DataProvider('staffRoles')]
    public function test_non_admin_staff_can_read_own_logo_but_cannot_edit_company_branding(string $role): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $path = $this->uploadLogo($company);
        $staff = User::factory()->create(['tenant_id' => $company['tenant']->id, 'role' => $role]);

        $this->actingAs($staff)->get('/api/settings/receipt-logo')->assertOk();
        $this->putJson('/api/settings', ['receipt_phone' => 'Changed', 'remove_receipt_logo' => true])->assertForbidden();

        $this->assertSame($path, $company['tenant']->refresh()->receiptLogoPath());
        Storage::disk('local')->assertExists($path);
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public static function staffRoles(): array
    {
        return ['manager' => ['manager'], 'cashier' => ['cashier']];
    }

    public function test_logo_endpoint_never_uses_client_tenant_or_path_to_read_a_foreign_file(): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $foreign = $this->company();
        $foreignPath = $this->uploadLogo($foreign);
        $company = $this->company();
        $params = http_build_query(['tenant_id' => $foreign['tenant']->id, 'path' => $foreignPath]);

        $this->actingAs($company['user'])->getJson('/api/settings/receipt-logo?'.$params)->assertNotFound();
        $ownPath = $this->uploadLogo($company, 'own.jpg');
        $response = $this->get('/api/settings/receipt-logo?'.$params)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->assertSame(Storage::disk('local')->path($ownPath), $response->baseResponse->getFile()->getPathname());
        Storage::disk('local')->assertExists($foreignPath);
        Queue::assertPushed(CheckLowStock::class, 2);
    }

    public function test_guests_cannot_read_or_write_receipt_branding(): void
    {
        Storage::fake('local');

        $this->getJson('/api/settings/receipt-logo')->assertUnauthorized();
        $this->putJson('/api/settings', ['receipt_phone' => '123'])->assertUnauthorized();

        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    #[DataProvider('staleAdministrators')]
    public function test_branding_write_rechecks_current_active_admin_before_storage(string $field, mixed $value): void
    {
        Storage::fake('local');
        $company = $this->company();
        User::whereKey($company['user']->id)->update([$field => $value]);

        $this->actingAs($company['user'])->post('/api/settings', ['_method' => 'PUT', 'receipt_logo' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
            ->assertForbidden();

        Storage::disk('local')->assertDirectoryEmpty('/');
        $this->assertNull($company['tenant']->refresh()->receiptLogoPath());
    }

    public static function staleAdministrators(): array
    {
        return ['demoted' => ['role', 'manager'], 'paused' => ['is_active', false]];
    }

    public function test_logo_read_rechecks_suspended_staff_from_database(): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $path = $this->uploadLogo($company);
        $staff = User::factory()->create(['tenant_id' => $company['tenant']->id, 'role' => 'cashier']);
        User::whereKey($staff->id)->update(['is_active' => false]);

        $this->actingAs($staff)->getJson('/api/settings/receipt-logo')->assertForbidden();

        Storage::disk('local')->assertExists($path);
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    #[DataProvider('invalidLogoFiles')]
    public function test_invalid_upload_preserves_original_logo_and_settings(string $case): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $path = $this->uploadLogo($company);
        $before = $company['tenant']->getAttributes();
        $file = match ($case) {
            'SVG' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><script>alert(1)</script></svg>'),
            'GIF' => UploadedFile::fake()->image('logo.gif', 20, 20),
            'forged image' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo "unsafe"; ?>'),
            'oversize' => UploadedFile::fake()->image('logo.png')->size(1025),
            'too wide' => UploadedFile::fake()->image('logo.png', 2001, 1),
            'too tall' => UploadedFile::fake()->image('logo.png', 1, 2001),
        };

        $this->post('/api/settings', ['_method' => 'PUT', 'receipt_logo' => $file, 'receipt_phone' => 'Changed'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('receipt_logo');

        $this->assertSame($before, $company['tenant']->refresh()->getAttributes());
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertCount('receipt-logos/tenant-'.$company['tenant']->id, 1);
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public static function invalidLogoFiles(): array
    {
        return ['SVG' => ['SVG'], 'GIF' => ['GIF'], 'forged image' => ['forged image'], 'oversize' => ['oversize'], 'too wide' => ['too wide'], 'too tall' => ['too tall']];
    }

    public function test_upload_and_remove_in_one_request_is_rejected_without_deleting_current_logo(): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $path = $this->uploadLogo($company);

        $this->post('/api/settings', ['_method' => 'PUT', 'receipt_logo' => UploadedFile::fake()->image('next.png'), 'remove_receipt_logo' => '1'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('receipt_logo');

        $this->assertSame($path, $company['tenant']->refresh()->receiptLogoPath());
        Storage::disk('local')->assertCount('receipt-logos/tenant-'.$company['tenant']->id, 1);
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    #[DataProvider('invalidTextSettings')]
    public function test_branding_fields_validate_lengths_and_removal_boolean(string $field, mixed $value): void
    {
        $company = $this->company();
        $before = $company['tenant']->refresh()->getAttributes();

        $this->actingAs($company['user'])->putJson('/api/settings', [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame($before, $company['tenant']->refresh()->getAttributes());
    }

    public static function invalidTextSettings(): array
    {
        return ['address too long' => ['receipt_address', str_repeat('a', 301)], 'phone too long' => ['receipt_phone', str_repeat('1', 51)],
            'invalid removal flag' => ['remove_receipt_logo', 'yes']];
    }

    #[DataProvider('staffAndAdminRoles')]
    public function test_bootstrap_returns_own_branding_without_a_storage_path_for_every_staff_role(string $role): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $path = $this->uploadLogo($company);
        $this->putJson('/api/settings', ['receipt_address' => '123 Main Street', 'receipt_phone' => '+63 123'])->assertOk();
        $user = $role === 'admin' ? $company['user']->fresh() : User::factory()->create(['tenant_id' => $company['tenant']->id, 'role' => $role]);

        $response = $this->actingAs($user)->getJson('/api/bootstrap')->assertOk()->assertJsonPath('tenant.name', 'Registered Company & Co.')
            ->assertJsonPath('settings.receipt_address', '123 Main Street')->assertJsonPath('settings.receipt_phone', '+63 123')->assertJsonMissingPath('settings.receipt_logo_path');

        $this->assertStringStartsWith('/api/settings/receipt-logo?v=', $response->json('settings.receipt_logo_url'));
        $this->assertStringNotContainsString($path, $response->getContent());
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public static function staffAndAdminRoles(): array
    {
        return ['admin' => ['admin'], ...self::staffRoles()];
    }

    public function test_old_company_settings_without_branding_serialize_null_defaults(): void
    {
        $company = $this->company();
        $company['tenant']->update(['settings' => ['currency' => 'PHP', 'tax_percent' => 12]]);

        $this->actingAs($company['user'])->getJson('/api/bootstrap')->assertOk()->assertJsonPath('settings.receipt_address', null)
            ->assertJsonPath('settings.receipt_phone', null)->assertJsonPath('settings.receipt_logo_url', null);
    }

    public function test_internal_cross_tenant_or_traversal_path_cannot_be_served_or_exposed(): void
    {
        Storage::fake('local');
        $company = $this->company();
        $foreign = $this->company();
        $path = 'receipt-logos/tenant-'.$foreign['tenant']->id.'/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.png';
        Storage::disk('local')->put($path, UploadedFile::fake()->image('foreign.png')->get());
        $company['tenant']->update(['settings' => array_replace($company['tenant']->settings, ['receipt_logo_path' => $path])]);

        $this->actingAs($company['user'])->getJson('/api/settings/receipt-logo')->assertNotFound();
        $response = $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('settings.receipt_logo_url', null)->assertJsonMissingPath('settings.receipt_logo_path');

        $this->assertStringNotContainsString($path, $response->getContent());
        $company['tenant']->update(['settings' => array_replace($company['tenant']->settings, ['receipt_logo_path' => '../foreign.png'])]);
        $this->getJson('/api/settings/receipt-logo')->assertNotFound();
        Storage::disk('local')->assertExists($path);
    }

    #[DataProvider('databaseFailures')]
    public function test_database_failure_preserves_old_logo_and_cleans_the_new_upload(string $failure): void
    {
        Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $path = $this->uploadLogo($company);
        $before = $company['tenant']->getAttributes();
        $dispatcher = Tenant::getEventDispatcher();
        $temporaryDispatcher = clone $dispatcher;
        Tenant::setEventDispatcher($temporaryDispatcher);
        $temporaryDispatcher->listen('eloquent.updating: '.Tenant::class, fn () => $failure === 'exception' ? throw new RuntimeException('private database details') : false);
        try {
            $response = $this->post('/api/settings', ['_method' => 'PUT', 'receipt_logo' => UploadedFile::fake()->image('new.png'), 'receipt_phone' => 'Changed'], ['Accept' => 'application/json'])
                ->assertStatus(503);
        } finally {
            Tenant::setEventDispatcher($dispatcher);
        }

        $this->assertSame($before, $company['tenant']->refresh()->getAttributes());
        $this->assertStringNotContainsString('private database details', $response->getContent());
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertCount('receipt-logos/tenant-'.$company['tenant']->id, 1);
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public static function databaseFailures(): array
    {
        return ['database exception' => ['exception'], 'rejected save' => ['rejected']];
    }

    public function test_storage_failure_preserves_old_logo_and_does_not_change_settings(): void
    {
        $disk = Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $path = $this->uploadLogo($company);
        $before = $company['tenant']->getAttributes();
        $unwritable = Mockery::mock(FilesystemAdapter::class);
        $unwritable->shouldReceive('putFileAs')->once()->andReturn(false);
        $unwritable->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($unwritable);

        $this->post('/api/settings', ['_method' => 'PUT', 'receipt_logo' => UploadedFile::fake()->image('new.png'), 'receipt_phone' => 'Changed'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('receipt_logo');

        $this->assertSame($before, $company['tenant']->refresh()->getAttributes());
        $disk->assertExists($path);
        $disk->assertCount('receipt-logos/tenant-'.$company['tenant']->id, 1);
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public function test_clearing_footer_persists_empty_text_without_default_message_reappearing(): void
    {
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();

        $this->actingAs($company['user'])->putJson('/api/settings', ['receipt_footer' => ''])->assertOk()->assertJsonPath('receipt_footer', '');
        $this->actingAs($company['user']->fresh())->getJson('/api/bootstrap')->assertOk()->assertJsonPath('settings.receipt_footer', '');

        $this->assertSame('', $company['tenant']->refresh()->settings['receipt_footer']);
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public function test_admin_demotion_during_upload_cleans_new_file_and_preserves_original_logo(): void
    {
        $disk = Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $oldPath = $this->uploadLogo($company);
        $before = $company['tenant']->getAttributes();
        $changingDisk = Mockery::mock(FilesystemAdapter::class);
        $changingDisk->shouldReceive('putFileAs')->once()->andReturnUsing(function (string $directory, UploadedFile $file, string $name, array $options) use ($disk, $company): string {
            $path = $disk->putFileAs($directory, $file, $name, $options);
            User::whereKey($company['user']->id)->update(['role' => 'manager']);

            return $path;
        });
        $changingDisk->shouldReceive('delete')->once()->andReturnUsing(fn (string $path): bool => $disk->delete($path));
        Storage::shouldReceive('disk')->with('local')->andReturn($changingDisk);

        $this->post('/api/settings', ['_method' => 'PUT', 'receipt_logo' => UploadedFile::fake()->image('new.png'), 'receipt_phone' => 'Changed'], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame($before, $company['tenant']->refresh()->getAttributes());
        $disk->assertExists($oldPath);
        $disk->assertCount('receipt-logos/tenant-'.$company['tenant']->id, 1);
        Queue::assertPushed(CheckLowStock::class, 1);
    }

    public function test_logo_storage_read_failure_returns_safe_unavailable_response_without_paths(): void
    {
        $disk = Storage::fake('local');
        Queue::fake([CheckLowStock::class]);
        $company = $this->company();
        $path = $this->uploadLogo($company);
        $unavailable = Mockery::mock(FilesystemAdapter::class);
        $unavailable->shouldReceive('exists')->once()->with($path)->andThrow(new RuntimeException('Cannot access private directory '.$path));
        Storage::shouldReceive('disk')->with('local')->andReturn($unavailable);

        $response = $this->getJson('/api/settings/receipt-logo')->assertStatus(503)->assertExactJson(['message' => 'The receipt logo is temporarily unavailable.'])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringNotContainsString($path, $response->getContent());
        $disk->assertExists($path);
        $this->assertSame($path, $company['tenant']->refresh()->receiptLogoPath());
        Queue::assertPushed(CheckLowStock::class, 1);
    }
}
