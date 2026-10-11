<?php

namespace App\Http\Controllers;

use App\Jobs\CheckLowStock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SettingsController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tax_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'low_stock_alerts' => ['sometimes', 'required', 'boolean'],
            'receipt_footer' => ['sometimes', 'nullable', 'string', 'max:200'],
            'receipt_address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'receipt_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'receipt_logo' => ['sometimes', 'nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
            'remove_receipt_logo' => ['sometimes', 'boolean'],
        ]);
        $actor = $request->user();
        if (array_key_exists('receipt_footer', $data) && $data['receipt_footer'] === null) {
            $data['receipt_footer'] = '';
        }
        abort_unless(User::where('tenant_id', $actor->tenant_id)->whereKey($actor->id)->where('is_active', true)->where('role', 'admin')->exists(), 403);
        $removeLogo = (bool) ($data['remove_receipt_logo'] ?? false);
        $upload = $request->file('receipt_logo');
        if ($upload !== null && $removeLogo) {
            throw ValidationException::withMessages(['receipt_logo' => 'Upload a replacement logo or remove the current logo, then save.']);
        }
        unset($data['receipt_logo'], $data['remove_receipt_logo']);
        $newPath = null;
        if ($upload !== null) {
            $extension = match ($upload->getMimeType()) {
                'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
                default => throw ValidationException::withMessages(['receipt_logo' => 'Choose a PNG, JPEG or WebP image.']),
            };
            $newPath = 'receipt-logos/tenant-'.$actor->tenant_id.'/'.Str::uuid().'.'.$extension;
            try {
                $stored = $upload->storeAs(dirname($newPath), basename($newPath), ['disk' => 'local', 'visibility' => 'private']);
                if ($stored !== $newPath) {
                    throw ValidationException::withMessages(['receipt_logo' => 'The logo could not be saved. Your existing logo was preserved.']);
                }
            } catch (Throwable) {
                $this->deleteLogo($newPath);
                throw ValidationException::withMessages(['receipt_logo' => 'The logo could not be saved. Your existing logo was preserved.']);
            }
        }
        try {
            $tenant = DB::transaction(function () use ($actor, $data, $newPath, $removeLogo): Tenant {
                $tenant = Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
                $currentActor = User::where('tenant_id', $tenant->id)->whereKey($actor->id)->lockForUpdate()->first();
                abort_unless($currentActor && $currentActor->is_active && $currentActor->role === 'admin', 403);
                $oldPath = $tenant->receiptLogoPath();
                $settings = array_replace($tenant->settings ?? [], $data);
                if ($newPath !== null || $removeLogo) {
                    $settings['receipt_logo_path'] = $newPath;
                }
                abort_unless($tenant->update(['settings' => $settings]), 503, 'Receipt settings could not be saved. Your previous settings were preserved.');
                if ($oldPath !== null && ($newPath !== null || $removeLogo)) {
                    DB::afterCommit(fn () => $this->deleteLogo($oldPath));
                }

                return $tenant;
            }, 5);
        } catch (Throwable $error) {
            if ($newPath !== null) {
                $this->deleteLogo($newPath);
            }
            if ($error instanceof HttpExceptionInterface || $error instanceof ValidationException) {
                throw $error;
            }
            abort(503, 'Receipt settings could not be saved. Your previous settings were preserved.');
        }
        CheckLowStock::dispatch($tenant->id);

        return response()->json($tenant->publicSettings())->header('Cache-Control', 'no-store, private');
    }

    public function logo(Request $request): BinaryFileResponse|JsonResponse
    {
        $actor = $request->user();
        abort_unless(User::where('tenant_id', $actor->tenant_id)->whereKey($actor->id)->where('is_active', true)->exists(), 403);
        $tenant = Tenant::findOrFail($actor->tenant_id);
        $path = $tenant->receiptLogoPath();
        abort_if($path === null, 404);
        try {
            $disk = Storage::disk('local');
            abort_unless($disk->exists($path), 404);
            $mime = $disk->mimeType($path);
            abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);

            return response()->file($disk->path($path), ['Content-Type' => $mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'])
                ->setPrivate()
                ->setContentDisposition('inline', 'receipt-logo.'.pathinfo($path, PATHINFO_EXTENSION));
        } catch (HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            return response()->json(['message' => 'The receipt logo is temporarily unavailable.'], 503)
                ->header('Cache-Control', 'no-store, private')->header('X-Content-Type-Options', 'nosniff');
        }
    }

    private function deleteLogo(string $path): void
    {
        try {
            Storage::disk('local')->delete($path);
        } catch (Throwable) {
        }
    }
}
