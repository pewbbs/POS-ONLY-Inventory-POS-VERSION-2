<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SeoService
{
    private const PUBLIC_ROUTES = [
        '/' => 'home',
        '/inventory-management' => 'marketing.inventory',
        '/point-of-sale' => 'marketing.pos',
        '/about' => 'marketing.about',
        '/guide' => 'guide',
        '/privacy' => 'privacy',
    ];

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

    /** @return array{canonical_url:?string,site_url:?string,site_name:string,locale:string,image_url:?string,indexable:bool,robots:string,page_type:string,software_page:bool,breadcrumb_label:?string} */
    public function metadata(string $path): array
    {
        $path = '/'.ltrim($path, '/');
        $routeName = self::PUBLIC_ROUTES[$path] ?? null;
        $siteUrl = $this->siteUrl();
        $indexable = $routeName !== null && $this->indexingEnabled();

        return ['canonical_url' => $routeName !== null && $siteUrl !== null ? $siteUrl.route($routeName, absolute: false) : null,
            'site_url' => $siteUrl, 'site_name' => (string) config('seo.site_name', 'POSONLY'),
            'locale' => (string) config('seo.locale', 'en_PH'),
            'image_url' => $routeName !== null && $siteUrl !== null ? $siteUrl.'/images/posonly-social.png' : null,
            'indexable' => $indexable, 'robots' => $indexable ? 'index, follow' : 'noindex, nofollow',
            'page_type' => $path === '/about' ? 'AboutPage' : 'WebPage',
            'software_page' => in_array($path, ['/', '/inventory-management', '/point-of-sale'], true),
            'breadcrumb_label' => match ($path) {
                '/inventory-management' => (string) config('marketing.pages.inventory.label'),
                '/point-of-sale' => (string) config('marketing.pages.pos.label'),
                '/about' => (string) config('marketing.pages.about.label'),
                default => null,
            }];
    }

    /**
     * @param  array{canonical_url:?string,site_url:?string,site_name:string,locale:string,image_url:?string,indexable:bool,robots:string,page_type:string,software_page:bool,breadcrumb_label:?string}  $metadata
     * @return array<string, mixed>|null
     */
    public function structuredData(array $metadata, string $title, string $description): ?array
    {
        if ($metadata['site_url'] === null || $metadata['canonical_url'] === null) {
            return null;
        }

        $homeUrl = $metadata['site_url'].'/';
        $organizationId = $homeUrl.'#organization';
        $websiteId = $homeUrl.'#website';
        $softwareId = $homeUrl.'#software';
        $page = [
            '@type' => $metadata['page_type'],
            '@id' => $metadata['canonical_url'].'#webpage',
            'url' => $metadata['canonical_url'],
            'name' => $title,
            'description' => $description,
            'inLanguage' => 'en-PH',
            'isPartOf' => ['@id' => $websiteId],
            'publisher' => ['@id' => $organizationId],
            'primaryImageOfPage' => [
                '@type' => 'ImageObject',
                'url' => $metadata['image_url'],
                'width' => 1200,
                'height' => 630,
            ],
        ];
        if ($metadata['software_page']) {
            $page['mainEntity'] = ['@id' => $softwareId];
        } elseif ($metadata['page_type'] === 'AboutPage') {
            $page['about'] = ['@id' => $organizationId];
        }
        if ($metadata['breadcrumb_label'] !== null) {
            $page['breadcrumb'] = ['@id' => $metadata['canonical_url'].'#breadcrumb'];
        }

        $organization = [
            '@type' => 'Organization',
            '@id' => $organizationId,
            'name' => $metadata['site_name'],
            'url' => $homeUrl,
            'areaServed' => ['@type' => 'Country', 'name' => 'Philippines'],
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $metadata['site_url'].'/images/favicon.png',
                'width' => 192,
                'height' => 192,
            ],
        ];
        $officialProfiles = array_values((array) config('seo.official_profiles', []));
        if ($officialProfiles !== []) {
            $organization['sameAs'] = $officialProfiles;
        }

        $graph = [
            $organization,
            [
                '@type' => 'WebSite',
                '@id' => $websiteId,
                'name' => $metadata['site_name'],
                'url' => $homeUrl,
                'description' => $metadata['canonical_url'] === $homeUrl ? $description : (string) config('seo.product_description'),
                'inLanguage' => 'en-PH',
                'publisher' => ['@id' => $organizationId],
            ],
            $page,
        ];
        if ($metadata['software_page']) {
            $graph[] = [
                '@type' => 'SoftwareApplication',
                '@id' => $softwareId,
                'name' => $metadata['site_name'],
                'url' => $homeUrl,
                'description' => (string) config('seo.product_description'),
                'applicationCategory' => 'BusinessApplication',
                'applicationSubCategory' => 'Inventory management and point of sale',
                'operatingSystem' => 'Web browser',
                'softwareRequirements' => 'An internet connection and a modern web browser',
                'countriesSupported' => ['PH'],
                'inLanguage' => 'en-PH',
                'publisher' => ['@id' => $organizationId],
                'audience' => ['@type' => 'BusinessAudience', 'audienceType' => 'Small and medium businesses'],
                'image' => $metadata['image_url'],
                'softwareHelp' => ['@type' => 'WebPage', 'url' => $metadata['site_url'].route('guide', absolute: false)],
                'featureList' => (array) config('seo.product_features'),
                'offers' => $this->subscriptionOffers($homeUrl),
            ];
        }
        if ($metadata['breadcrumb_label'] !== null) {
            $graph[] = [
                '@type' => 'BreadcrumbList',
                '@id' => $metadata['canonical_url'].'#breadcrumb',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $homeUrl],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $metadata['breadcrumb_label'], 'item' => $metadata['canonical_url']],
                ],
            ];
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /** @return list<array<string, mixed>> */
    private function subscriptionOffers(string $homeUrl): array
    {
        $offers = [];
        foreach ((array) config('billing.plans', []) as $plan) {
            foreach (['monthly' => 'P1M', 'annual' => 'P1Y'] as $interval => $duration) {
                $price = number_format($plan[$interval.'_cents'] / 100, 2, '.', '');
                $offers[] = [
                    '@type' => 'Offer',
                    'name' => $plan['name'].' '.($interval === 'monthly' ? 'monthly' : 'annual').' subscription',
                    'url' => $homeUrl.'#pricing',
                    'price' => $price,
                    'priceCurrency' => 'PHP',
                    'description' => 'Subscription invoice paid individually for '.($interval === 'monthly' ? 'one month' : 'one year').'; no automatic recurring charge.',
                    'priceSpecification' => [
                        '@type' => 'UnitPriceSpecification',
                        'price' => $price,
                        'priceCurrency' => 'PHP',
                        'billingDuration' => $duration,
                    ],
                ];
            }
        }

        return $offers;
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
