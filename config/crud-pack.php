<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CRUD Resources (for navbar dropdown)
    |--------------------------------------------------------------------------
    | Each item:
    | - label: text in UI
    | - route: base resource route name (ex: products)
    | - soft_deletes: true/false (shows Trash link if true)
    |
    */

    'resources' => [
        [
            'label' => 'Products',
            'route' => 'products',
            'soft_deletes' => true,
            
            // Optional override (defaults to "{resource}.trash" when soft_deletes=true)
            // 'trash_route' => 'products.trash',
        ],
        [
            'label' => 'Categories',
            'route' => 'categories',
            'soft_deletes' => false,
        ],
    ],
];
