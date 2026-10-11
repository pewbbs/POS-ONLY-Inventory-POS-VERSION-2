@php
    $metadata = $seo ?? [];
    $siteName = $metadata['site_name'] ?? 'POSONLY';
    $siteUrl = $metadata['site_url'] ?? null;
    $canonicalUrl = $metadata['canonical_url'] ?? null;
    $structuredData = null;

    if (($seoHome ?? false) && $siteUrl && $canonicalUrl) {
        $organizationId = $canonicalUrl.'#organization';
        $structuredData = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => $organizationId,
                    'name' => $siteName,
                    'url' => $canonicalUrl,
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => rtrim($siteUrl, '/').'/images/favicon.png',
                        'width' => 192,
                        'height' => 192,
                    ],
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $canonicalUrl.'#website',
                    'name' => $siteName,
                    'url' => $canonicalUrl,
                    'description' => $seoDescription,
                    'inLanguage' => 'en-PH',
                    'publisher' => ['@id' => $organizationId],
                ],
            ],
        ];
    }
@endphp
<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
<meta name="robots" content="{{ $metadata['robots'] ?? 'noindex, nofollow' }}">
@if($canonicalUrl)
<link rel="canonical" href="{{ $canonicalUrl }}">
<meta property="og:url" content="{{ $canonicalUrl }}">
@endif
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:locale" content="{{ $metadata['locale'] ?? 'en_PH' }}">
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $seoTitle }}">
<meta name="twitter:description" content="{{ $seoDescription }}">
<link rel="icon" type="image/png" sizes="192x192" href="/images/favicon.png">
@if($structuredData)
<script type="application/ld+json">{!! \Illuminate\Support\Js::encode($structuredData) !!}</script>
@endif
