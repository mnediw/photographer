<?php

declare(strict_types=1);

namespace Diw\Photographer\DataProcessing;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Context\Context;
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
        $listMaxWidth = 1024; // px, 0 = no resize
        $lightboxMaxWidth = 2048; // px, 0 = no resize
        $ffXml = (string)($data['pi_flexform'] ?? '');
        if ($ffXml !== '') {
            // Parse FlexForm values directly via DOM (avoids dependency on FlexFormService,
            // which is deprecated as of TYPO3 v14 in favor of FlexFormTools)
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
                    } elseif ($index === 'listMaxWidth') {
                        $listMaxWidth = max(0, min(10000, (int)$vdef));
                    } elseif ($index === 'lightboxMaxWidth') {
                        $lightboxMaxWidth = max(0, min(10000, (int)$vdef));
                    }
                }
            } catch (\Throwable) {
            }
        }

        // Access and marks (TSFE->fe_user was removed in TYPO3 13.0; use the Context aspect instead)
        $context = GeneralUtility::makeInstance(Context::class);
        $feUserUid = (int)$context->getPropertyFromAspect('frontend.user', 'id', 0);
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
        $processedData['listMaxWidth'] = $listMaxWidth;
        $processedData['lightboxMaxWidth'] = $lightboxMaxWidth;
        $processedData['hasAccess'] = $hasAccess;
        $processedData['isLoggedInUser'] = $feUserUid;
        $processedData['contentUid'] = $contentUid;
        $processedData['markedRefUids'] = $markedRefUids;

        // Compute scaled dimensions for thumbnails and lightbox (used by template / PhotoSwipe)
        foreach ($images as $k => $img) {
            $ow = (int)($img['width'] ?? 0);
            $oh = (int)($img['height'] ?? 0);
            if ($ow > 0 && $oh > 0) {
                // Thumb/list
                $tw = $listMaxWidth > 0 ? min($ow, $listMaxWidth) : $ow;
                $th = (int)round($oh * ($tw / $ow));
                // Lightbox
                $lw = $lightboxMaxWidth > 0 ? min($ow, $lightboxMaxWidth) : $ow;
                $lh = (int)round($oh * ($lw / $ow));
                $images[$k]['thumbWidth'] = $tw;
                $images[$k]['thumbHeight'] = $th;
                $images[$k]['lbWidth'] = $lw;
                $images[$k]['lbHeight'] = $lh;
            } else {
                $images[$k]['thumbWidth'] = $img['width'] ?? 0;
                $images[$k]['thumbHeight'] = $img['height'] ?? 0;
                $images[$k]['lbWidth'] = $img['width'] ?? 0;
                $images[$k]['lbHeight'] = $img['height'] ?? 0;
            }
        }
        $processedData['images'] = $images;

        return $processedData;
    }
}
