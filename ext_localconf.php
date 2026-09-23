<?php

defined('TYPO3') || die();

// Icon registration now happens via Configuration/Icons.php (TYPO3 v14:
// instantiating IconRegistry in ext_localconf.php is no longer allowed).

// PageTSConfig for the New Content Element Wizard is auto-included via Configuration/page.tsconfig
// (ExtensionManagementUtility::addPageTSConfig() was removed in TYPO3 v14).

// Auto-include TypoScript setup (so rendering works without adding the static template manually)
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
    "@import 'EXT:photographer/Configuration/TypoScript/setup.typoscript'"
);
