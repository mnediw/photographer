<?php

declare(strict_types=1);

namespace Diw\Photographer\DataProcessing;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileReference as CoreFileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

class PhotographerProcessor implements DataProcessorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ) {
        $data = $processedData['data'] ?? [];
        $contentUid = (int)($data['uid'] ?? 0);

        // Load images from tt_content.media (sys_file_reference uid)
        $images = [];
        try {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
            $qb = $connection->createQueryBuilder();
            $rows = $qb->select('uid')
                ->from('sys_file_reference')
                ->where(
                    $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                    $qb->expr()->eq('fieldname', $qb->createNamedParameter('media')),
                    $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($contentUid, \PDO::PARAM_INT)),
                    $qb->expr()->eq('deleted', 0),
                    $qb->expr()->eq('hidden', 0)
                )
                ->orderBy('sorting_foreign', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            /** @var ResourceFactory $resourceFactory */
            $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
            foreach ($rows as $row) {
                try {
                    /** @var CoreFileReference $fileRef */
                    $fileRef = $resourceFactory->getFileReferenceObject((int)$row['uid']);
                    $file = $fileRef->getOriginalFile();
                    $images[] = [
                        'refUid' => (int)$row['uid'],
                        'uid' => $file->getUid(),
                        'publicUrl' => $fileRef->getPublicUrl(),
                        'width' => (int)$file->getProperty('width'),
                        'height' => (int)$file->getProperty('height'),
                        'title' => (string)($fileRef->getProperty('title') ?: $file->getProperty('title') ?: ''),
                        'description' => (string)($fileRef->getProperty('description') ?: ''),
                    ];
                } catch (\Throwable $e) {
                    $this->logger?->warning('Failed to build image from media', ['refUid' => $row['uid'], 'error' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->error('PhotographerProcessor sys_file_reference error', ['error' => $e->getMessage()]);
        }

        // FlexForm values (robust parsing)
        $allowedUser = 0;
        $maxSelectable = 0;
        // PhotoSwipe configuration options (subset)
        $initialZoomLevel = 1.0;
        $secondaryZoomLevel = 2.0;
        $maxZoomLevel = 4.0;
        $mouseMovePan = true;
        $showHideAnimationType = 'zoom'; // zoom|fade|none
        $bgOpacity = 0.8;
        $colsMd = 3;
        $colsLg = 3;
        $ffXml = (string)($data['pi_flexform'] ?? '');
        if ($ffXml !== '') {
            // First try: FlexFormService (works in most cases)
            try {
                /** @var FlexFormService $ffs */
                $ffs = GeneralUtility::makeInstance(FlexFormService::class);
                $arr = $ffs->convertFlexFormContentToArray($ffXml) ?? [];
                // Normalize different shapes (scalar, array)
                $allowedUserVal = $arr['allowedUser'] ?? 0;
                if (is_array($allowedUserVal)) {
                    // pick first scalar value if provided as array
                    $first = array_values($allowedUserVal)[0] ?? 0;
                    $allowedUserVal = is_array($first) ? (array_values($first)[0] ?? 0) : $first;
                }
                $allowedUser = (int)$allowedUserVal;

                $maxSelectableVal = $arr['maxSelectable'] ?? 0;
                if (is_array($maxSelectableVal)) {
                    $first = array_values($maxSelectableVal)[0] ?? 0;
                    $maxSelectableVal = is_array($first) ? (array_values($first)[0] ?? 0) : $first;
                }
                $maxSelectable = (int)$maxSelectableVal;

                // PhotoSwipe options from FlexForm
                $initialZoomLevelVal = $arr['initialZoomLevel'] ?? $initialZoomLevel;
                if (is_array($initialZoomLevelVal)) {
                    $first = array_values($initialZoomLevelVal)[0] ?? $initialZoomLevel;
                    $initialZoomLevelVal = is_array($first) ? (array_values($first)[0] ?? $initialZoomLevel) : $first;
                }
                $initialZoomLevel = (float)$initialZoomLevelVal;

                $secondaryZoomLevelVal = $arr['secondaryZoomLevel'] ?? $secondaryZoomLevel;
                if (is_array($secondaryZoomLevelVal)) {
                    $first = array_values($secondaryZoomLevelVal)[0] ?? $secondaryZoomLevel;
                    $secondaryZoomLevelVal = is_array($first) ? (array_values($first)[0] ?? $secondaryZoomLevel) : $first;
                }
                $secondaryZoomLevel = (float)$secondaryZoomLevelVal;

                $maxZoomLevelVal = $arr['maxZoomLevel'] ?? $maxZoomLevel;
                if (is_array($maxZoomLevelVal)) {
                    $first = array_values($maxZoomLevelVal)[0] ?? $maxZoomLevel;
                    $maxZoomLevelVal = is_array($first) ? (array_values($first)[0] ?? $maxZoomLevel) : $first;
                }
                $maxZoomLevel = (float)$maxZoomLevelVal;

                $mouseMovePanVal = $arr['mouseMovePan'] ?? ($mouseMovePan ? 1 : 0);
                if (is_array($mouseMovePanVal)) {
                    $first = array_values($mouseMovePanVal)[0] ?? ($mouseMovePan ? 1 : 0);
                    $mouseMovePanVal = is_array($first) ? (array_values($first)[0] ?? ($mouseMovePan ? 1 : 0)) : $first;
                }
                $mouseMovePan = (string)$mouseMovePanVal === '1' || (bool)$mouseMovePanVal;

                $showHideAnimationTypeVal = $arr['showHideAnimationType'] ?? $showHideAnimationType;
                if (is_array($showHideAnimationTypeVal)) {
                    $first = array_values($showHideAnimationTypeVal)[0] ?? $showHideAnimationType;
                    $showHideAnimationTypeVal = is_array($first) ? (array_values($first)[0] ?? $showHideAnimationType) : $first;
                }
                $showHideAnimationType = (string)$showHideAnimationTypeVal;

                $bgOpacityVal = $arr['bgOpacity'] ?? $bgOpacity;
                if (is_array($bgOpacityVal)) {
                    $first = array_values($bgOpacityVal)[0] ?? $bgOpacity;
                    $bgOpacityVal = is_array($first) ? (array_values($first)[0] ?? $bgOpacity) : $first;
                }
                $bgOpacity = (float)$bgOpacityVal;

                // Columns for Bootstrap grid
                $colsMdVal = $arr['colsMd'] ?? 3;
                if (is_array($colsMdVal)) {
                    $first = array_values($colsMdVal)[0] ?? 3;
                    $colsMdVal = is_array($first) ? (array_values($first)[0] ?? 3) : $first;
                }
                $colsMd = max(1, min(12, (int)$colsMdVal));

                $colsLgVal = $arr['colsLg'] ?? 3;
                if (is_array($colsLgVal)) {
                    $first = array_values($colsLgVal)[0] ?? 3;
                    $colsLgVal = is_array($first) ? (array_values($first)[0] ?? 3) : $first;
                }
                $colsLg = max(1, min(12, (int)$colsLgVal));
            } catch (\Throwable) {
            }

            // Fallback: parse minimal values via DOM if FlexFormService did not yield values
            if ($allowedUser === 0 && $maxSelectable === 0) {
                try {
                    $dom = new \DOMDocument();
                    $dom->loadXML($ffXml);
                    foreach ($dom->getElementsByTagName('field') as $field) {
                        $index = $field->getAttribute('index');
                        $vdef = $field->getElementsByTagName('value')->item(0)?->textContent ?? '';
                        if ($index === 'allowedUser') {
                            $allowedUser = (int)$vdef;
                        } elseif ($index === 'maxSelectable') {
                            $maxSelectable = (int)$vdef;
                        } elseif ($index === 'initialZoomLevel') {
                            $initialZoomLevel = (float)$vdef;
                        } elseif ($index === 'secondaryZoomLevel') {
                            $secondaryZoomLevel = (float)$vdef;
                        } elseif ($index === 'maxZoomLevel') {
                            $maxZoomLevel = (float)$vdef;
                        } elseif ($index === 'mouseMovePan') {
                            $mouseMovePan = ((string)$vdef === '1');
                        } elseif ($index === 'showHideAnimationType') {
                            $showHideAnimationType = (string)$vdef;
                        } elseif ($index === 'bgOpacity') {
                            $bgOpacity = (float)$vdef;
                        } elseif ($index === 'colsMd') {
                            $colsMd = max(1, min(12, (int)$vdef));
                        } elseif ($index === 'colsLg') {
                            $colsLg = max(1, min(12, (int)$vdef));
                        }
                    }
                } catch (\Throwable) {
                }
            }
        }

        // Access and marks
        $feUser = $GLOBALS['TSFE']->fe_user ?? null;
        $feUserUid = (int)($feUser?->user['uid'] ?? 0);
        $hasAccess = $allowedUser === 0 || ($feUserUid > 0 && $allowedUser === $feUserUid);

        $markedRefUids = [];
        if ($feUserUid > 0 && $hasAccess) {
            try {
                $feConn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('fe_users');
                $row = $feConn->select(['tx_photographer_marks'], 'fe_users', ['uid' => $feUserUid])->fetchAssociative();
                $json = (string)($row['tx_photographer_marks'] ?? '');
                if ($json !== '') {
                    $dataMarks = json_decode($json, true);
                    if (is_array($dataMarks)) {
                        $list = $dataMarks[(string)$contentUid] ?? [];
                        if (is_array($list)) {
                            $markedRefUids = array_values(array_map('intval', $list));
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        $processedData['images'] = $images;
        $processedData['allowedUser'] = $allowedUser;
        $processedData['maxSelectable'] = $maxSelectable;
        $processedData['initialZoomLevel'] = $initialZoomLevel;
        $processedData['secondaryZoomLevel'] = $secondaryZoomLevel;
        $processedData['maxZoomLevel'] = $maxZoomLevel;
        $processedData['mouseMovePan'] = $mouseMovePan;
        $processedData['showHideAnimationType'] = $showHideAnimationType;
        $processedData['bgOpacity'] = $bgOpacity;
        $processedData['colsMd'] = $colsMd;
        $processedData['colsLg'] = $colsLg;
        $processedData['hasAccess'] = $hasAccess;
        $processedData['isLoggedInUser'] = $feUserUid;
        $processedData['contentUid'] = $contentUid;
        $processedData['markedRefUids'] = $markedRefUids;

        return $processedData;
    }
}
