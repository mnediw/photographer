<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// Register new content element CType = photoswipe
ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'LLL:EXT:photoswipe/Resources/Private/Language/locallang_db.xlf:plugin.title',
        'photoswipe',
        'content-photoswipe',
    ],
    'textmedia', // place after a common element
    'after'
);

// Display configuration: use core media field and our FlexForm for options
$GLOBALS['TCA']['tt_content']['types']['photoswipe'] = [
    'showitem' => '--div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,'
        . ' --palette--;;general,header,media,tx_photoswipe_watermark,pi_flexform,'
        . ' --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,hidden,starttime,endtime',
    'columnsOverrides' => [
        'media' => [
            'config' => [
                'maxitems' => 999,
                'overrideChildTca' => [
                    'types' => [
                        '0' => [
                            'showitem' => '--palette--;;filePalette',
                        ],
                        '2' => [
                            'showitem' => '--palette--;;filePalette',
                        ],
                        '3' => [
                            'showitem' => '--palette--;;filePalette',
                        ],
                    ],
                ],
            ],
        ],
        // Bind our FlexForm data structure to this CType
        'pi_flexform' => [
            'config' => [
                'ds' => [
                    'default' => 'FILE:EXT:photoswipe/Configuration/FlexForms/Gallery.xml',
                ],
            ],
        ],
    ],
];

// Note: FlexForm DS is assigned above via columnsOverrides for CType "photoswipe".

// Additionally register DS mapping keyed by CType to ensure BE reliably picks the DS
// in all contexts (some installations ignore columnsOverrides in certain editors).
// Format: '*,<CType>' => 'FILE:...'
$GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds']['*,photoswipe'] =
    'FILE:EXT:photoswipe/Configuration/FlexForms/Gallery.xml';

// Add FAL field for optional watermark image (max 1)
if (!isset($GLOBALS['TCA']['tt_content']['columns']['tx_photoswipe_watermark'])) {
    $newCol = [
        'tx_photoswipe_watermark' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:photoswipe/Resources/Private/Language/locallang_db.xlf:tt_content.tx_photoswipe_watermark',
            'description' => 'LLL:EXT:photoswipe/Resources/Private/Language/locallang_db.xlf:tt_content.tx_photoswipe_watermark.description',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'sys_file_reference',
                'foreign_field' => 'uid_foreign',
                'foreign_sortby' => 'sorting_foreign',
                'foreign_match_fields' => [
                    'tablenames' => 'tt_content',
                    'fieldname' => 'tx_photoswipe_watermark',
                ],
                'overrideChildTca' => [
                    'columns' => [
                        'uid_local' => [
                            'config' => [
                                'appearance' => [
                                    'elementBrowserType' => 'file',
                                    'elementBrowserAllowed' => 'jpg,jpeg,png,webp',
                                ],
                            ],
                        ],
                    ],
                    'types' => [
                        '0' => [
                            'showitem' => '--palette--;;filePalette',
                        ],
                        '2' => [
                            'showitem' => '--palette--;;filePalette',
                        ],
                        '3' => [
                            'showitem' => '--palette--;;filePalette',
                        ],
                    ],
                ],
                'appearance' => [
                    'useSortable' => false,
                    'createNewRelationLinkTitle' => 'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:cm.createNewRelation',
                ],
                'maxitems' => 1,
            ],
        ],
    ];
    ExtensionManagementUtility::addTCAcolumns('tt_content', $newCol);
}
