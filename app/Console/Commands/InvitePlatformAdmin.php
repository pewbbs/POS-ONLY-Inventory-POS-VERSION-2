<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class InvitePlatformAdmin extends Command
{
    protected $signature = 'platform:invite {email : Developer email} {--name=Developer : Developer display name} {--output= : Private HTML setup file outside public/}';

    protected $description = 'Create or rotate a pending developer invitation without printing credentials';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) $this->option('name'));
        $validator = Validator::make(['email' => $email, 'name' => $name], ['email' => ['required', 'email', 'max:255'], 'name' => ['required', 'string', 'max:100']]);
        if ($validator->fails()) {
            $this->error('A valid email and developer name are required.');

            return self::FAILURE;
        }
        $directory = storage_path('app/private');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            $this->error('A private setup file could not be prepared.');

            return self::FAILURE;
        }
        $output = $this->option('output') ?: $directory.'/developer-setup.html';
        if (! str_starts_with($output, '/')) {
            $output = base_path($output);
        }
        $parent = realpath(dirname($output));
        $public = realpath(public_path());
        if ($parent === false || ! is_writable($parent) || is_link($output)
            || strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'html'
            || ($public !== false && ($parent === $public || str_starts_with($parent, $public.DIRECTORY_SEPARATOR)))) {
            $this->error('Choose a writable private HTML file outside the public directory.');

            return self::FAILURE;
        }
        $output = $parent.DIRECTORY_SEPARATOR.basename($output);
        $urlParts = parse_url((string) config('app.url'));
        if (! is_array($urlParts) || ! isset($urlParts['scheme'], $urlParts['host'])
            || ! in_array($urlParts['scheme'], ['http', 'https'], true)
            || ($urlParts['scheme'] === 'http' && ! in_array($urlParts['host'], ['localhost', '127.0.0.1', '::1', '[::1]'], true))
            || isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['query']) || isset($urlParts['fragment'])) {
            $this->error('Configure a trusted HTTPS or local application URL before inviting a developer.');

            return self::FAILURE;
        }
        $token = bin2hex(random_bytes(32));
        $setupPath = route('developer.setup', ['token' => $token, 'email' => $email], false);
        $url = rtrim((string) config('app.url'), '/').$setupPath;
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow"><title>Private developer setup</title><body><h1>Set up your developer account</h1><p>This private link expires in 24 hours and works once. Keep this file private and delete it after setup.</p><p><a rel="noreferrer" href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">Set developer password</a></p></body></html>';
        $temporary = tempnam($parent, '.developer-setup-');
        if ($temporary === false) {
            $this->error('A private setup file could not be prepared.');

            return self::FAILURE;
        }
        try {
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $html) !== strlen($html)) {
                throw new \RuntimeException('Private setup file unavailable.');
            }
            DB::transaction(function () use ($email, $name, $token, $temporary, $output): void {
                $account = PlatformAdmin::where('email', $email)->lockForUpdate()->first();
                if ($account && ($account->is_active || $account->password !== null)) {
                    throw new \DomainException('An activated developer account already uses this email.');
                }
                $values = ['name' => $name, 'password' => null, 'is_active' => false,
                    'setup_token_hash' => hash('sha256', $token), 'setup_expires_at' => now()->addHours(24)];
                $account ? $account->update($values) : $account = PlatformAdmin::create($values + ['email' => $email]);
                PlatformAuditLog::create(['platform_admin_id' => null, 'tenant_id' => null,
                    'action' => 'developer.account_invited', 'reason' => 'Private invitation created by the system operator',
                    'metadata' => ['account_id' => $account->id], 'created_at' => now()]);
                if (! rename($temporary, $output)) {
                    throw new \RuntimeException('Private setup file unavailable.');
                }
            }, 1);
        } catch (\DomainException) {
            $this->error('An activated developer account already uses this email. No invitation was changed.');

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('The developer invitation could not be created. Check the private output directory and database.');

            return self::FAILURE;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $this->info('Private setup file saved: '.$output);

        return self::SUCCESS;
    }
}
