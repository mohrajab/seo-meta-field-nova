<?php

namespace Gwd\SeoMeta\Helper;

use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SeoSitemap
{
    /**
     * Array of the all the items in the sitemap
     *
     * @var array
     */
    private $items = [];

    /**
     * Should the sitemap have the lastmod var?
     *
     * @var bool
     */
    private $use_lastmod = true;

    /**
     * the path where the sitemap index will be saved in
     * for now will be static , will change it later
     * @var string
     */
    private $sitemap_index_path;

    private $sitemap_files_path;

    private $default_locale;

    private $available_locales;

    private $localized_sitemaps;

    /**
     * Construct the sitemap class
     *
     * @return void
     */
    public function __construct($use_lastmod = true)
    {
        $this->use_lastmod = $use_lastmod;

        $sitemap_models = config('seo.sitemap_models');
        $this->attachModelItems($sitemap_models);

        $this->sitemap_index_path = public_path('sitemap.xml');
        $this->sitemap_files_path = public_path('sitemap_files');
        File::ensureDirectoryExists($this->sitemap_files_path);

        $this->default_locale = config('seo.fallback_locale');
        $this->available_locales = config('seo.available_locales');
        $this->localized_sitemaps = config('seo.sitemap_localization');
    }

    /**
     * Attach the model items
     *
     * @param array $sitemap_models
     *
     * @return void
     */
    private function attachModelItems(array $sitemap_models = [])
    {
        $chunk_size = config('seo.sitemap_chunk_size', 100);
        $sitemap_max_tags_count = config('seo.sitemap_max_tags_count', 10000);
        foreach ($sitemap_models as $sitemap_model) {
            $class_name = class_basename($sitemap_model);
            $model_items = [];
            $sitemap_model::getSitemapItems()->chunk($chunk_size, function ($models) use (&$model_items) {
                if ($models && $models->count() > 0) {
                    $model_items = array_merge($model_items, $models->map(function ($item) {
                        return (object)[
                            'url' => $item->getSitemapItemUrl(),
                            'lastmod' => $item->getSitemapItemLastModified(),
                        ];
                    })->toArray());
                }
            });
            $chunked = array_chunk($model_items, $sitemap_max_tags_count);
            $model_items = [];
            foreach ($chunked as $index => $chunk) {
                $model_items[$index ? $class_name . '_' . $index : $class_name] = $chunk;
            }
            $this->items = array_merge($this->items, $model_items);
        }
    }

    /**
     * Attach a custom sitemap item
     *
     * @param string $path Path on the current site
     * @param string $lastmod Date of last edit
     *
     * @return SeoSitemap
     */
    public function attachCustom($path, $lastmod = null)
    {
        $this->items[] = (object)[
            'url' => url($path),
            'lastmod' => $lastmod
        ];
        return $this;
    }

    /**
     * Return sitemap items as array
     *
     * @return array
     */
    public function toArray()
    {
        return $this->items;
    }

    /**
     * Return xml for sitemap items
     *
     * @return string
     */
    public function toXml()
    {
        if (count($this->items)) {
            File::cleanDirectory($this->sitemap_files_path);
        }
        foreach ($this->items as $model_name => $model_items) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' .
                '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
                    xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 
                    http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd 
                    http://www.w3.org/TR/xhtml11/xhtml11_schema.html
                    http://www.w3.org/2002/08/xhtml/xhtml1-strict.xsd" 
                    xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" 
                    xmlns:xhtml="http://www.w3.org/TR/xhtml11/xhtml11_schema.html">';
            $lastmod = Carbon::now()->format('Y-m-d H:i:s');

            foreach ($model_items as $item) {
                $use_lastmod = $this->use_lastmod ? ($item->lastmod ?? $lastmod) : null;
                $xml .= '<url>' .
                    '<loc>' . $this->getDefaultUrl($item->url) . '</loc>';

                if ($this->localized_sitemaps) {
                    foreach ($this->available_locales as $locale) {
                        $xml .= '<xhtml:link rel="alternate" hreflang="' .
                            $locale . '" href="' . $this->getDefaultUrl($item->url,$locale) . '" />';
                    }
                }

                $xml .= ($use_lastmod ? '<lastmod>' . $use_lastmod . '</lastmod>' : '') .
                    '</url>';

                if ($item->lastmod) {
                    $lastmod = $item->lastmod;
                }
            }
            $xml .= '</urlset>';
            File::put($this->sitemap_files_path . "/$model_name.xml", $xml);
        }
        return $this->createSitemapIndex();
    }

    protected function createSitemapIndex()
    {
        $sitemap_files = File::files($this->sitemap_files_path);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($sitemap_files as $file) {
            $xml .= '<sitemap>' .
                '<loc>' . url('sitemap_files') . '/' . $file->getFilename() . '</loc>' .
                '</sitemap>';
        }
        $xml .= '</sitemapindex>';
        File::replace($this->sitemap_index_path, $xml);
        return File::get($this->sitemap_index_path);
    }

    /**
     * @param string $url
     * @param null $locale
     *
     * @return string
     */
    protected function getDefaultUrl(string $url,$locale = null): string
    {
        $append_locale = $this->localized_sitemaps;
        $parsed_url = parse_url($url);
        if (isset($parsed_url['path'])) {
            $path = Str::remove('/', explode('/', $parsed_url['path']));
            if (isset($path[1]) && in_array($path[1], $this->available_locales)) {
                unset($path[1]);
            }
            $url_path = implode('/', $path);
        } else {
            $url_path = '';
        }
        if (isset($parsed_url['query'])){
            $url_path.='?'.$parsed_url['query'];
        }
        if ($locale){
            return  url($locale . $url_path);
        }
        $url = $append_locale ? url($this->default_locale . $url_path) : url($url_path);
        return $url;
    }
}
