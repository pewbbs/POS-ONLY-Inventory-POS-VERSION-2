<?php

namespace App\Http\Controllers;

use App\Services\SeoService;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function sitemap(SeoService $seo): Response
    {
        abort_if($seo->siteUrl() === null, 503, 'Sitemap configuration is unavailable.');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($seo->publicUrls() as $url) {
            $xml .= '  <url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml)->header('Content-Type', 'application/xml; charset=UTF-8')->header('X-Content-Type-Options', 'nosniff');
    }

    public function robots(SeoService $seo): Response
    {
        $body = $seo->indexingEnabled()
            ? "User-agent: *\nAllow: /\nSitemap: ".$seo->siteUrl().route('seo.sitemap', absolute: false)."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body)->header('Content-Type', 'text/plain; charset=UTF-8')->header('X-Content-Type-Options', 'nosniff');
    }
}
