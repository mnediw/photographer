<?php

defined('TYPO3') || die();

// Register PageTS for new content element wizard
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::registerPageTSConfigFile(
    'photographer',
    'Configuration/TsConfig/Page/Mod/Wizards.tsconfig',
    'Photographer Wizard'
);

// Add static TypoScript
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'photographer',
    'Configuration/TypoScript',
    'Photographer: Setup'
);
