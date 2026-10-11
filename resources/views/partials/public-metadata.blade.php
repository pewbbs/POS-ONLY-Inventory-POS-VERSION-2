@php
    $metadata = $seo ?? [];
    $siteName = $metadata['site_name'] ?? 'POSONLY';
    $siteUrl = $metadata['site_url'] ?? null;
    $canonicalUrl = $metadata['canonical_url'] ?? null;
    $imageUrl = $metadata['image_url'] ?? null;
    $structuredData = $siteUrl && $canonicalUrl
        ? app(\App\Services\SeoService::class)->structuredData($metadata, $seoTitle, $seoDescription)
        : null;
@endphp
<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
<meta name="robots" content="{{ $metadata['robots'] ?? 'noindex, nofollow' }}">
@if($metadata['indexable'] ?? false)
<meta name="googlebot" content="index, follow, max-image-preview:large">
@endif
@if($canonicalUrl)
<link rel="canonical" href="{{ $canonicalUrl }}">
<meta property="og:url" content="{{ $canonicalUrl }}">
@endif
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:locale" content="{{ $metadata['locale'] ?? 'en_PH' }}">
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
@if($imageUrl)
<meta property="og:image" content="{{ $imageUrl }}">
@if(str_starts_with($imageUrl, 'https://'))
<meta property="og:image:secure_url" content="{{ $imageUrl }}">
@endif
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="POSONLY cloud inventory management and point of sale">
<meta name="twitter:image" content="{{ $imageUrl }}">
<meta name="twitter:image:alt" content="POSONLY cloud inventory management and point of sale">
@endif
<meta name="twitter:card" content="{{ $imageUrl ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $seoTitle }}">
<meta name="twitter:description" content="{{ $seoDescription }}">
<link rel="icon" type="image/png" sizes="192x192" href="/images/favicon.png">
@if($structuredData)
<script type="application/ld+json">{!! \Illuminate\Support\Js::encode($structuredData) !!}</script>
@endif
