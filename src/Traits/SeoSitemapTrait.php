<?php

namespace Gwd\SeoMeta\Traits;

use Illuminate\Database\Eloquent\Builder;

trait SeoSitemapTrait
{
    /**
     * @return String
     */
    abstract public function getSitemapItemUrl(): string;

    /**
     * @return string|null
     */
    public function getSitemapItemLastModified(): ?string
    {
        if (isset($this->updated_at) || isset($this->created_at)) {
            return isset($this->updated_at) ? $this->updated_at : $this->created_at;
        }
        return null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    abstract public static function getSitemapItems(): Builder;
}
