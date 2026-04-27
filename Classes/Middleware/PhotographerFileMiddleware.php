<?php

declare(strict_types=1);

namespace Diw\Photographer\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileReference as CoreFileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Core\Core\Environment;

class PhotographerFileMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        if (!isset($queryParams['photographer_file'])) {
            return $handler->handle($request);
        }

        $response = $this->responseFactory->createResponse();

        // Parse params
        $contentUid = (int)($queryParams['contentUid'] ?? 0);
        $refUid = (int)($queryParams['refUid'] ?? 0);
        if ($contentUid <= 0 || $refUid <= 0) {
            return $this->json($response, 400, ['success' => false, 'error' => 'invalid_parameters']);
        }

        // Fetch content element
        $ttConn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        $row = $ttConn->select(['uid', 'CType', 'pi_flexform'], 'tt_content', ['uid' => $contentUid, 'deleted' => 0])->fetchAssociative();
        if (!$row || ($row['CType'] ?? '') !== 'photographer') {
            return $this->json($response, 404, ['success' => false, 'error' => 'content_not_found']);
        }

        // Read minimal FlexForm config
        $xml = (string)$row['pi_flexform'];
        [$allowedUser] = $this->readAllowedUser($xml);
        [$wmPosition, $wmOpacityPct, $wmScalePct] = $this->readWatermarkOptions($xml);

        // Optional: find watermark file for this CE (first active reference)
        $watermarkPath = null;
        $watermarkUid = 0;
        $wmConn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        $wmRow = $wmConn->select(['uid', 'uid_local'], 'sys_file_reference', [
            'tablenames' => 'tt_content',
            'fieldname' => 'tx_photographer_watermark',
            'uid_foreign' => $contentUid,
            'deleted' => 0,
            'hidden' => 0,
        ])->fetchAssociative();

        // Validate refUid belongs to this content element
        $sfrConn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        $refRow = $sfrConn->select(['uid', 'uid_local'], 'sys_file_reference', [
            'uid' => $refUid,
            'tablenames' => 'tt_content',
            'fieldname' => 'media',
            'uid_foreign' => $contentUid,
            'deleted' => 0,
            'hidden' => 0,
        ])->fetchAssociative();
        if (!$refRow) {
            return $this->json($response, 404, ['success' => false, 'error' => 'image_not_in_gallery']);
        }

        // Authorization
        $feUserUid = $this->resolveFrontendUserUid($request);
        $isAuthorized = ($allowedUser === 0) || ($feUserUid > 0 && $feUserUid === $allowedUser);
        if (!$isAuthorized) {
            // Do not leak file content
            return $this->json($response, 403, ['success' => false, 'error' => 'forbidden']);
        }

        // Stream the file
        try {
            /** @var ResourceFactory $rf */
            $rf = GeneralUtility::makeInstance(ResourceFactory::class);
            /** @var CoreFileReference $fileRef */
            $fileRef = $rf->getFileReferenceObject($refUid);
            $file = $fileRef->getOriginalFile();

            $path = $file->getForLocalProcessing(false);
            $mime = (string)($file->getMimeType() ?: 'application/octet-stream');
            $size = (int)($file->getSize() ?? 0);
            $mtime = (int)@filemtime($path);

            // Resolve watermark local path if configured
            if (isset($wmRow['uid']) && $wmRow) {
                try {
                    /** @var CoreFileReference $wmRef */
                    $wmRef = $rf->getFileReferenceObject((int)$wmRow['uid']);
                    $wmFile = $wmRef->getOriginalFile();
                    $watermarkPath = $wmFile->getForLocalProcessing(false);
                    $watermarkUid = (int)$wmFile->getUid();
                } catch (\Throwable) {
                    $watermarkPath = null;
                    $watermarkUid = 0;
                }
            }

            // If watermark is set, build or reuse a cached composite
            $compositedPath = null;
            $compositedMTime = $mtime;
            $compositedSize = $size;
            $compositedMime = $mime;
            if ($watermarkPath) {
                [$compositedPath, $compositedMime, $compositedSize, $compositedMTime] = $this->buildWatermarkedImage(
                    $path,
                    $mime,
                    $mtime,
                    $watermarkPath,
                    (int)@filemtime($watermarkPath),
                    $wmPosition,
                    $wmOpacityPct,
                    $wmScalePct
                );
            }

            $outPath = $compositedPath ?: $path;
            $outMime = $compositedMime;
            $outSize = $compositedSize;
            $outMTime = $compositedMTime;

            $etag = $this->buildEtag($file->getUid(), $outMTime, $watermarkUid);

            // Conditional requests
            $ifNoneMatch = (string)($request->getHeaderLine('If-None-Match'));
            $ifModifiedSince = (string)($request->getHeaderLine('If-Modified-Since'));
            if ($ifNoneMatch && trim($ifNoneMatch) === $etag) {
                return $response->withStatus(304)
                    ->withHeader('ETag', $etag)
                    ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $outMTime) . ' GMT')
                    ->withHeader('Cache-Control', $allowedUser === 0 ? 'public, max-age=31536000, immutable' : 'private, no-store');
            }
            if ($ifModifiedSince) {
                $since = strtotime($ifModifiedSince);
                if ($since !== false && $outMTime && $outMTime <= $since) {
                    return $response->withStatus(304)
                        ->withHeader('ETag', $etag)
                        ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $outMTime) . ' GMT')
                        ->withHeader('Cache-Control', $allowedUser === 0 ? 'public, max-age=31536000, immutable' : 'private, no-store');
                }
            }

            $stream = fopen($outPath, 'rb');
            if ($stream === false) {
                return $this->json($response, 500, ['success' => false, 'error' => 'file_open_failed']);
            }
            $body = $response->getBody();
            while (!feof($stream)) {
                $body->write(fread($stream, 8192) ?: '');
            }
            fclose($stream);

            $response = $response
                ->withHeader('Content-Type', $outMime)
                ->withHeader('Content-Length', (string)$outSize)
                ->withHeader('ETag', $etag)
                ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $outMTime) . ' GMT')
                ->withHeader('Cache-Control', $allowedUser === 0 ? 'public, max-age=31536000, immutable' : 'private, no-store');

            return $response->withStatus(200);
        } catch (\Throwable $e) {
            error_log('[photographer_file] ' . $e->getMessage());
            return $this->json($response, 500, ['success' => false, 'error' => 'exception']);
        }
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response = $response->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode($data));
        return $response;
    }

    private function readAllowedUser(string $xml): array
    {
        $allowedUser = 0;
        if ($xml === '') {
            return [$allowedUser];
        }
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            foreach ($dom->getElementsByTagName('field') as $field) {
                if ($field->getAttribute('index') === 'allowedUser') {
                    $v = $field->getElementsByTagName('value')->item(0)?->textContent ?? '';
                    $allowedUser = (int)$v;
                    break;
                }
            }
        } catch (\Throwable) {
        }
        return [$allowedUser];
    }

    /**
     * Reads watermark options from FlexForm XML.
     * Returns array [position, opacityPercent (0-100), scalePercent (1-500)]
     */
    private function readWatermarkOptions(string $xml): array
    {
        $position = 'br';
        $opacity = 50;
        $scale = 100;
        if ($xml === '') {
            return [$position, $opacity, $scale];
        }
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            foreach ($dom->getElementsByTagName('field') as $field) {
                $idx = $field->getAttribute('index');
                $val = $field->getElementsByTagName('value')->item(0)?->textContent ?? '';
                switch ($idx) {
                    case 'watermarkPosition':
                        $map = ['tl','tr','center','bl','br'];
                        $position = in_array($val, $map, true) ? $val : $position;
                        break;
                    case 'watermarkOpacity':
                        $n = (int)$val; if ($n < 0) $n = 0; if ($n > 100) $n = 100; $opacity = $n;
                        break;
                    case 'watermarkScalePercent':
                        $n = (int)$val; if ($n < 1) $n = 1; if ($n > 500) $n = 500; $scale = $n;
                        break;
                }
            }
        } catch (\Throwable) {
        }
        return [$position, $opacity, $scale];
    }

    private function resolveFrontendUserUid(ServerRequestInterface $request): int
    {
        $feUser = $request->getAttribute('frontend.user');
        if ($feUser instanceof FrontendUserAuthentication && !empty($feUser->user['uid'])) {
            return (int)$feUser->user['uid'];
        }
        try {
            /** @var FrontendUserAuthentication $alt */
            $alt = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
            $alt->checkPid = 0;
            $alt->start();
            $alt->checkAuthentication();
            if (!empty($alt->user['uid'])) {
                return (int)$alt->user['uid'];
            }
        } catch (\Throwable) {
        }
        return 0;
    }

    private function buildWatermarkedImage(string $srcPath, string $srcMime, int $srcMTime, string $wmPath, int $wmMTime, string $position, int $opacityPct, int $scalePct): array
    {
        // Cache file path based on source+watermark mtimes and names
        $hash = sha1($srcPath . '|' . $srcMTime . '|' . $wmPath . '|' . $wmMTime . '|' . $position . '|' . $opacityPct . '|' . $scalePct);
        $ext = $this->preferedExtensionForMime($srcMime);
        $cacheDir = Environment::getPublicPath() . '/typo3temp/assets/photographer';
        try { GeneralUtility::mkdir_deep($cacheDir); } catch (\Throwable) {}
        $cacheFile = $cacheDir . '/wm_' . $hash . '.' . $ext;

        if (is_file($cacheFile)) {
            return [$cacheFile, $this->mimeFromExtension($ext), (int)@filesize($cacheFile), (int)@filemtime($cacheFile)];
        }

        // Try Imagick first
        try {
            if (class_exists(\Imagick::class)) {
                $img = new \Imagick($srcPath);
                $wm = new \Imagick($wmPath);
                // Base: ~25% of image width, scaled by user percent
                $base = 0.25 * max(1, $scalePct) / 100.0;
                $targetW = max(1, (int)round($img->getImageWidth() * $base));
                $wm->resizeImage($targetW, 0, \Imagick::FILTER_LANCZOS, 1, true);
                $wm->setImageOpacity(max(0.0, min(1.0, $opacityPct / 100.0)));
                // Place by position with margin 16px
                $margin = 16;
                $imgW = $img->getImageWidth(); $imgH = $img->getImageHeight();
                $wmW = $wm->getImageWidth(); $wmH = $wm->getImageHeight();
                switch ($position) {
                    case 'tl': $x = $margin; $y = $margin; break;
                    case 'tr': $x = max(0, $imgW - $wmW - $margin); $y = $margin; break;
                    case 'center': $x = max(0, (int)floor(($imgW - $wmW) / 2)); $y = max(0, (int)floor(($imgH - $wmH) / 2)); break;
                    case 'bl': $x = $margin; $y = max(0, $imgH - $wmH - $margin); break;
                    case 'br':
                    default: $x = max(0, $imgW - $wmW - $margin); $y = max(0, $imgH - $wmH - $margin); break;
                }
                $img->compositeImage($wm, \Imagick::COMPOSITE_OVER, $x, $y);
                $img->setImageCompressionQuality(90);
                $img->writeImage($cacheFile);
                $mime = $this->mimeFromExtension($ext);
                return [$cacheFile, $mime, (int)@filesize($cacheFile), (int)@filemtime($cacheFile)];
            }
        } catch (\Throwable) {
            // fallthrough to GD
        }

        // Fallback: GD
        try {
            $src = $this->gdCreate($srcPath, $srcMime);
            $wmImg = $this->gdCreate($wmPath, null); // detect by file
            if ($src && $wmImg) {
                $srcW = imagesx($src); $srcH = imagesy($src);
                $wmW = imagesx($wmImg); $wmH = imagesy($wmImg);
                $base = 0.25 * max(1, $scalePct) / 100.0;
                $newW = max(1, (int)round($srcW * $base));
                $newH = (int)round($wmH * ($newW / $wmW));
                $wmResized = imagecreatetruecolor($newW, $newH);
                imagealphablending($wmResized, false); imagesavealpha($wmResized, true);
                imagecopyresampled($wmResized, $wmImg, 0, 0, 0, 0, $newW, $newH, $wmW, $wmH);
                // Position with margin
                $margin = 16;
                switch ($position) {
                    case 'tl': $x = $margin; $y = $margin; break;
                    case 'tr': $x = max(0, $srcW - $newW - $margin); $y = $margin; break;
                    case 'center': $x = max(0, (int)floor(($srcW - $newW) / 2)); $y = max(0, (int)floor(($srcH - $newH) / 2)); break;
                    case 'bl': $x = $margin; $y = max(0, $srcH - $newH - $margin); break;
                    case 'br':
                    default: $x = max(0, $srcW - $newW - $margin); $y = max(0, $srcH - $newH - $margin); break;
                }
                imagealphablending($src, true);
                $this->imagecopymergeAlpha($src, $wmResized, $x, $y, 0, 0, $newW, $newH, max(0, min(100, $opacityPct)));
                $this->gdSave($src, $cacheFile, $ext);
                imagedestroy($wmResized); imagedestroy($wmImg); imagedestroy($src);
                $mime = $this->mimeFromExtension($ext);
                return [$cacheFile, $mime, (int)@filesize($cacheFile), (int)@filemtime($cacheFile)];
            }
        } catch (\Throwable) {
        }

        // If processing failed, fall back to original
        return [$srcPath, $srcMime, (int)@filesize($srcPath), $srcMTime];
    }

    private function preferedExtensionForMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/webp' => 'webp',
            'image/png' => 'png',
            default => 'jpg',
        };
    }

    private function mimeFromExtension(string $ext): string
    {
        return match (strtolower($ext)) {
            'webp' => 'image/webp',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
    }

    private function gdCreate(string $path, ?string $mime)
    {
        $mime = $mime ?: (function ($p) { $i = @getimagesize($p); return $i ? ($i['mime'] ?? null) : null; })($path);
        if (!$mime) return null;
        switch (strtolower($mime)) {
            case 'image/png': return @imagecreatefrompng($path) ?: null;
            case 'image/webp': return function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null;
            case 'image/gif': return @imagecreatefromgif($path) ?: null;
            default: return @imagecreatefromjpeg($path) ?: null;
        }
    }

    private function gdSave($im, string $path, string $ext): void
    {
        switch (strtolower($ext)) {
            case 'png': @imagepng($im, $path, 6); break;
            case 'webp': if (function_exists('imagewebp')) { @imagewebp($im, $path, 90); break; }
            default: @imagejpeg($im, $path, 90); break;
        }
    }

    private function imagecopymergeAlpha($dst_im, $src_im, $dst_x, $dst_y, $src_x, $src_y, $src_w, $src_h, $pct): void
    {
        // Preserve alpha blending with opacity percent
        $cut = imagecreatetruecolor($src_w, $src_h);
        imagecopy($cut, $dst_im, 0, 0, $dst_x, $dst_y, $src_w, $src_h);
        imagecopy($cut, $src_im, 0, 0, $src_x, $src_y, $src_w, $src_h);
        imagecopymerge($dst_im, $cut, $dst_x, $dst_y, 0, 0, $src_w, $src_h, $pct);
        imagedestroy($cut);
    }

    private function buildEtag(int $fileUid, int $mtime, int $wmUid = 0): string
    {
        return sprintf('W/"pswp-%d-%d-%d"', $fileUid, $mtime, $wmUid);
    }
}
