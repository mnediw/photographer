<?php

defined('TYPO3') || die();

// Register icon
/** @var \TYPO3\CMS\Core\Imaging\IconRegistry $iconRegistry */
$iconRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Imaging\IconRegistry::class);
$iconRegistry->registerIcon(
    'content-photoswipe',
    \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
    ['source' => 'EXT:photoswipe/Resources/Public/Icons/Extension.svg']
);

// Auto-include PageTSConfig for New Content Element Wizard (so the CE appears without manual PageTS include)
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addPageTSConfig(
    "@import 'EXT:photoswipe/Configuration/TsConfig/Page/Mod/Wizards.tsconfig'"
);

// Auto-include TypoScript setup (so rendering works without adding the static template manually)
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
    "@import 'EXT:photoswipe/Configuration/TypoScript/setup.typoscript'"
);

// Note: No custom backend preview renderer is needed for CType=photoswipe anymore.
