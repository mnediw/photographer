<?php

defined('TYPO3') || die();

// Register PageTS for new content element wizard
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::registerPageTSConfigFile(
    'photoswipe',
    'Configuration/TsConfig/Page/Mod/Wizards.tsconfig',
    'Photoswipe Wizard'
);

// Add static TypoScript
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'photoswipe',
    'Configuration/TypoScript',
    'Photoswipe: Setup'
);
