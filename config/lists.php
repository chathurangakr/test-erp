<?php

/*
|--------------------------------------------------------------------------
| Lists
|--------------------------------------------------------------------------
|
| Every list screen offers the same choice of how many rows to show a page, plus "All". "All"
| shows every matching record — the whole history unless a From / To period is chosen — up to
| `all_limit` rows on one page; anything beyond that pages on, so a very large history never
| loads into one browser tab. See App\Support\ListQuery.
|
*/

return [
    'per_page_options' => [25, 50, 100],

    'default_per_page' => 25,

    'all_limit' => (int) env('LIST_ALL_LIMIT', 5000),
];
