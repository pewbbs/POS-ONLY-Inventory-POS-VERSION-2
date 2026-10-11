<?php

namespace App\Http\Controllers;

use App\Services\PayMongoClient;
use App\Services\SeoService;
use App\Services\SiteContentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteContentController extends Controller
{
    public function home(SiteContentService $content, PayMongoClient $payments, SeoService $seo): View
    {
        return view('landing', ['site' => $content->state()['published'], 'plans' => config('billing.plans'), 'draftPreview' => false, 'paymentConfig' => $payments->configuration(), 'seo' => $seo->metadata('/')]);
    }

    public function preview(SiteContentService $content, PayMongoClient $payments, SeoService $seo): View
    {
        return view('landing', ['site' => $content->state()['draft'], 'plans' => config('billing.plans'), 'draftPreview' => true, 'paymentConfig' => $payments->configuration(), 'seo' => $seo->metadata('/developer/content/preview')]);
    }

    public function show(SiteContentService $content): JsonResponse
    {
        return response()->json($content->state())->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, SiteContentService $content): JsonResponse
    {
        $rules = ['version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:3', 'max:300'],
            'content' => ['required', 'array:'.implode(',', array_keys($content->defaults()))]];
        foreach (['site_title' => 120, 'meta_description' => 300, 'hero_kicker' => 80, 'hero_title_line1' => 60,
            'hero_title_line2' => 60, 'hero_description' => 400, 'primary_cta' => 60, 'footer_tagline' => 120] as $field => $max) {
            $rules['content.'.$field] = ['required', 'string', 'max:'.$max];
        }
        $rules['content.support_email'] = ['present', 'nullable', 'email', 'max:255'];
        $data = $request->validate($rules);
        $data['content']['support_email'] ??= '';
        $content->saveDraft($request->user('developer'), (int) $data['version'], $data['content'], $data['reason']);

        return $this->show($content);
    }

    public function publish(Request $request, SiteContentService $content): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:3', 'max:300']]);
        $content->publish($request->user('developer'), (int) $data['version'], $data['reason']);

        return $this->show($content);
    }
}
