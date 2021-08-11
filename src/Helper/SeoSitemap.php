<?php

namespace Gwd\SeoMeta\Helper;

use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

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
                '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            $lastmod = Carbon::now()->format('Y-m-d H:i:s');

            foreach ($model_items as $item) {
                $use_lastmod = $this->use_lastmod ? ($item->lastmod ?? $lastmod) : null;
                $xml .= '<url>' .
                    '<loc>' . (substr($item->url, 0, 1) == '/' ? url($item->url) : $item->url) . '</loc>' .
                    ($use_lastmod ? '<lastmod>' . $use_lastmod . '</lastmod>' : '') .
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
}
