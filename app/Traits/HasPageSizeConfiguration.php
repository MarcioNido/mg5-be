<?php

namespace App\Traits;

/**
 * Allow page size configuration passing the URL parameter page_size, i.e:
 * - GET api/feature-flags?page_size=2&page=2
 * Hard limit of 1000 per page.
 */
trait HasPageSizeConfiguration
{
    private int $pageSizeLimit = 1000;

    public function getPerPage()
    {
        $pageSize = request('page_size', $this->perPage);

        return min($pageSize, $this->pageSizeLimit) ?: $this->perPage;
    }
}
