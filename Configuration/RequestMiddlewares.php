<?php

declare(strict_types=1);

return [
    'frontend' => [
        'diw/photographer/file' => [
            'target' => \Diw\Photographer\Middleware\PhotographerFileMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/tsfe',
            ],
        ],
        'diw/photographer/mark' => [
            'target' => \Diw\Photographer\Middleware\PhotographerMarkMiddleware::class,
            // Ensure FE authentication already ran so we can read user context
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
            // Run before TSFE builds the page; we short-circuit with a JSON response
            'before' => [
                'typo3/cms-frontend/tsfe',
            ],
        ],
    ],
];
