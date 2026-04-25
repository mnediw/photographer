<?php

defined('TYPO3') || die();

// Register icon
/** @var \TYPO3\CMS\Core\Imaging\IconRegistry $iconRegistry */
$iconRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Imaging\IconRegistry::class);
$iconRegistry->registerIcon(
    'content-photographer',
    \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
    ['source' => 'EXT:photographer/Resources/Public/Icons/Extension.svg']
);

// Auto-include PageTSConfig for New Content Element Wizard (so the CE appears without manual PageTS include)
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addPageTSConfig(
    "@import 'EXT:photographer/Configuration/TsConfig/Page/Mod/Wizards.tsconfig'"
);

// Auto-include TypoScript setup (so rendering works without adding the static template manually)
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
    "@import 'EXT:photographer/Configuration/TypoScript/setup.typoscript'"
);
