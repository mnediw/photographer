<?php

declare(strict_types=1);

namespace Diw\Photographer\Middleware;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

class PhotographerMarkMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only handle requests that explicitly opt-in via query parameter
        $queryParams = $request->getQueryParams();
        if (!isset($queryParams['photographer_mark'])) {
            return $handler->handle($request);
        }

        // Prepare JSON response
        $response = $this->responseFactory->createResponse();
        $response = $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate');

        try {
            $feUserUid = $this->resolveFrontendUserUid($request);
            if ($feUserUid <= 0) {
                // 200 to allow client-side handling without hard error pages
                $response->getBody()->write(json_encode(['success' => false, 'error' => 'not_authenticated']));
                return $response->withStatus(200);
            }

            $parsedBody = $request->getParsedBody() ?: [];
            $contentUid = (int)($parsedBody['contentUid'] ?? $queryParams['contentUid'] ?? 0);
            $refUid = (int)($parsedBody['refUid'] ?? $queryParams['refUid'] ?? 0);
            $action = (string)($parsedBody['action'] ?? $queryParams['action'] ?? 'toggle');

            if ($contentUid <= 0 || $refUid <= 0) {
                $response->getBody()->write(json_encode(['success' => false, 'error' => 'invalid_parameters']));
                return $response->withStatus(400);
            }

            $connection = $this->connection('tt_content');
            $row = $connection->select(['uid', 'pi_flexform', 'CType'], 'tt_content', ['uid' => $contentUid, 'deleted' => 0])->fetchAssociative();
            if (!$row) {
                $response->getBody()->write(json_encode(['success' => false, 'error' => 'content_not_found']));
                return $response->withStatus(404);
            }

            if (($row['CType'] ?? '') !== 'photographer') {
                $response->getBody()->write(json_encode(['success' => false, 'error' => 'invalid_content_type']));
                return $response->withStatus(400);
            }

            [$allowedUser, $maxSelectable] = $this->readFlexConfig((string)$row['pi_flexform']);
            $imageRefUids = $this->getImageRefUidsForContent($contentUid);

            if ($allowedUser !== 0 && $allowedUser !== $feUserUid) {
                $response->getBody()->write(json_encode(['success' => false, 'error' => 'forbidden']));
                return $response->withStatus(403);
            }

            if (!in_array($refUid, $imageRefUids, true)) {
                $response->getBody()->write(json_encode(['success' => false, 'error' => 'image_not_in_gallery']));
                return $response->withStatus(400);
            }

            // Load and update marks
            $feConn = $this->connection('fe_users');
            $feRow = $feConn->select(['tx_photographer_marks'], 'fe_users', ['uid' => $feUserUid])->fetchAssociative();
            $json = (string)($feRow['tx_photographer_marks'] ?? '');
            $data = $json !== '' ? json_decode($json, true) : [];
            if (!is_array($data)) {
                $data = [];
            }
            $key = (string)$contentUid;
            $current = isset($data[$key]) && is_array($data[$key]) ? array_values(array_map('intval', $data[$key])) : [];

            $changed = false;
            if ($action === 'add') {
                if (!in_array($refUid, $current, true)) {
                    if ($maxSelectable > 0 && count($current) >= $maxSelectable) {
                        $response->getBody()->write(json_encode(['success' => false, 'error' => 'limit_reached', 'current' => $current, 'max' => $maxSelectable]));
                        return $response->withStatus(200);
                    }
                    $current[] = $refUid;
                    $changed = true;
                }
            } elseif ($action === 'remove') {
                if (in_array($refUid, $current, true)) {
                    $current = array_values(array_diff($current, [$refUid]));
                    $changed = true;
                }
            } else { // toggle
                if (in_array($refUid, $current, true)) {
                    $current = array_values(array_diff($current, [$refUid]));
                    $changed = true;
                } else {
                    if ($maxSelectable > 0 && count($current) >= $maxSelectable) {
                        $response->getBody()->write(json_encode(['success' => false, 'error' => 'limit_reached', 'current' => $current, 'max' => $maxSelectable]));
                        return $response->withStatus(200);
                    }
                    $current[] = $refUid;
                    $changed = true;
                }
            }

            if ($changed) {
                $data[$key] = array_values(array_unique(array_map('intval', $current)));
                $feConn->update('fe_users', ['tx_photographer_marks' => json_encode($data, JSON_UNESCAPED_SLASHES)], ['uid' => $feUserUid]);
            }

            $response->getBody()->write(json_encode(['success' => true, 'marked' => $current]));
            return $response->withStatus(200);
        } catch (\Throwable $e) {
            error_log('[photographer_mw] exception: ' . $e->getMessage());
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'exception']));
            return $response->withStatus(200);
        }
    }

    private function connection(string $table): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
    }

    private function readFlexConfig(string $xml): array
    {
        $allowedUser = 0;
        $maxSelectable = 0;
        if ($xml === '') {
            return [$allowedUser, $maxSelectable];
        }
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            foreach ($dom->getElementsByTagName('field') as $field) {
                $index = $field->getAttribute('index');
                $vdef = $field->getElementsByTagName('value')->item(0)?->textContent ?? '';
                if ($index === 'allowedUser') {
                    $allowedUser = (int)$vdef;
                } elseif ($index === 'maxSelectable') {
                    $maxSelectable = (int)$vdef;
                }
            }
        } catch (\Throwable) {
        }
        return [$allowedUser, $maxSelectable];
    }

    private function getImageRefUidsForContent(int $contentUid): array
    {
        try {
            $connection = $this->connection('sys_file_reference');
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
            return array_values(array_map(static fn($r) => (int)$r['uid'], $rows));
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolveFrontendUserUid(ServerRequestInterface $request): int
    {
        $feUser = $request->getAttribute('frontend.user');
        if ($feUser instanceof FrontendUserAuthentication && !empty($feUser->user['uid'])) {
            return (int)$feUser->user['uid'];
        }
        // Fallback for exotic contexts
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
}
