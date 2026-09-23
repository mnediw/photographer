<?php

$EM_CONF['photographer'] = [
    'title' => 'DIW Photographer',
    'description' => 'Photographer toolbox with PhotoSwipe gallery (per-user photo marking capability)',
    'category' => 'plugin',
    'version' => '1.1.0',
    'state' => 'stable',
    'author' => 'Martin Neumann',
    'author_email' => 'forum@die-internet-werkstatt.de',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
