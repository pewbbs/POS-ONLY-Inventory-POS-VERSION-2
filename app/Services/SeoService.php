<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SeoService
{
    private const PUBLIC_ROUTES = ['/' => 'home', '/guide' => 'guide', '/privacy' => 'privacy'];

    public function siteUrl(): ?string
    {
        $url = rtrim(trim((string) config('app.url', '')), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []
            || (isset($parts['path']) && $parts['path'] !== '')
            || ! in_array($parts['scheme'], ['http', 'https'], true) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        if (app()->isProduction() && $parts['scheme'] !== 'https') {
            return null;
        }

        return $url;
    }

    public function indexingEnabled(): bool
    {
        return app()->isProduction() && (bool) config('seo.indexing_enabled', true) && $this->siteUrl() !== null;
    }

    /** @return array{canonical_url:?string,site_url:?string,site_name:string,locale:string,image_url:null,indexable:bool,robots:string} */
    public function metadata(string $path): array
    {
        $path = '/'.ltrim($path, '/');
        $routeName = self::PUBLIC_ROUTES[$path] ?? null;
        $siteUrl = $this->siteUrl();
        $indexable = $routeName !== null && $this->indexingEnabled();

        return ['canonical_url' => $routeName !== null && $siteUrl !== null ? $siteUrl.route($routeName, absolute: false) : null,
            'site_url' => $siteUrl, 'site_name' => (string) config('seo.site_name', 'POSONLY'),
            'locale' => (string) config('seo.locale', 'en_PH'), 'image_url' => null,
            'indexable' => $indexable, 'robots' => $indexable ? 'index, follow' : 'noindex, nofollow'];
    }

    /** @return list<string> */
    public function publicUrls(): array
    {
        if (! $this->indexingEnabled()) {
            return [];
        }

        return array_map(fn (string $path): string => $this->metadata($path)['canonical_url'], array_keys(self::PUBLIC_ROUTES));
    }

    public function applyHeaders(Request $request, Response $response): Response
    {
        $indexable = in_array($request->method(), ['GET', 'HEAD'], true)
            && $response->getStatusCode() === 200 && $this->metadata($request->path())['indexable'];
        $response->headers->set('X-Robots-Tag', $indexable ? 'index, follow' : 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
