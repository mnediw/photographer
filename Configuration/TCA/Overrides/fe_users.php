<?php

declare(strict_types=1);

defined('TYPO3') or die();

$newColumn = [
    'tx_photoswipe_marks' => [
        'exclude' => 1,
        'label' => 'LLL:EXT:photoswipe/Resources/Private/Language/locallang_db.xlf:fe_users.tx_photoswipe_marks',
        'config' => [
            'type' => 'text',
            'renderType' => 't3editor',
            'format' => 'json',
            'rows' => 3,
            'enableRichtext' => false,
            'default' => ''
        ],
    ],
];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('fe_users', $newColumn);

// Show field in a dedicated tab for all fe_users records
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'fe_users',
    '--div--;LLL:EXT:photoswipe/Resources/Private/Language/locallang_db.xlf:tab.photoswipe, tx_photoswipe_marks',
    '',
    'after:description'
);
