<?php

namespace App\Http\Controllers;

use App\Services\SeoService;
use App\Services\SiteContentService;
use Illuminate\Contracts\View\View;

class MarketingPageController extends Controller
{
    public function inventory(SiteContentService $content, SeoService $seo): View
    {
        return $this->page('inventory', $content, $seo);
    }

    public function pos(SiteContentService $content, SeoService $seo): View
    {
        return $this->page('pos', $content, $seo);
    }

    public function about(SiteContentService $content, SeoService $seo): View
    {
        return $this->page('about', $content, $seo);
    }

    private function page(string $key, SiteContentService $content, SeoService $seo): View
    {
        $page = config('marketing.pages.'.$key);

        return view('marketing', ['page' => $page, 'pageKey' => $key,
            'site' => $content->state()['published'], 'plans' => config('billing.plans'),
            'seo' => $seo->metadata($page['path'])]);
    }
}
