<?php

declare(strict_types=1);

return [
    'frontend' => [
        'diw/photoswipe/file' => [
            'target' => \Diw\Photoswipe\Middleware\PhotoswipeFileMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/tsfe',
            ],
        ],
        'diw/photoswipe/mark' => [
            'target' => \Diw\Photoswipe\Middleware\PhotoswipeMarkMiddleware::class,
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
