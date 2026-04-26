<?php

declare(strict_types=1);

namespace OCA\WebTrack\Service;

use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Adapter for the Nextcloud Tables app.
 *
 * Preferred path: call the Tables PHP services directly via \OCP\Server::get().
 * This works in both web (controller) and CLI contexts without any HTTP auth.
 *
 * Fallback path: HTTP to the Tables REST API v1.  This only works when the
 * caller already has a valid session (never works from background jobs or CLI).
 *
 * All public methods throw \RuntimeException on failure and return decoded PHP
 * arrays on success.
 */
class TablesService {
    /** @var string Cached Tables API base URL (HTTP fallback only) */
    private string $apiBase = '';

    public function __construct(
        private IClientService  $clientService,
        private IURLGenerator   $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns all Tables the given user can access.
     *
     * Tries the Tables PHP service first (works in all contexts); falls back to
     * the HTTP REST API if the PHP class is unavailable.
     *
     * @return array<array{id:int,title:string,emoji:string}>
     * @throws \RuntimeException
     */
    public function listTablesForUser(string $userId): array {
        if (class_exists('\OCA\Tables\Service\TableService')) {
            try {
                /** @var \OCA\Tables\Service\TableService $svc */
                $svc = \OCP\Server::get(\OCA\Tables\Service\TableService::class);
                $tables = $svc->findAll($userId);
                return array_map(static fn($t) => [
                    'id'    => $t->getId(),
                    'title' => $t->getTitle(),
                    'emoji' => $t->getEmoji() ?? '',
                ], $tables);
            } catch (\Throwable $e) {
                $this->logger->warning('[webtrack] Tables PHP API (list) failed, trying HTTP: ' . $e->getMessage());
            }
        }
        return $this->listTables();
    }

    /**
     * Returns columns for the given table, accessible by $userId.
     *
     * Tries the Tables PHP service first; falls back to HTTP.
     * Includes selectionOptions so TablesRowBuilder can resolve option IDs.
     *
     * @return array<array{id:int,title:string,type:string,subtype:string,selectionOptions:array<array{id:int,label:string}>}>
     * @throws \RuntimeException
     */
    public function getColumnsForUser(int $tableId, string $userId): array {
        if (class_exists('\OCA\Tables\Service\ColumnService')) {
            try {
                /** @var \OCA\Tables\Service\ColumnService $svc */
                $svc = \OCP\Server::get(\OCA\Tables\Service\ColumnService::class);
                $columns = $svc->findAllByTable($tableId, $userId);
                return array_map(static fn($c) => [
                    'id'               => $c->getId(),
                    'title'            => $c->getTitle(),
                    'type'             => $c->getType(),
                    'subtype'          => $c->getSubtype() ?? '',
                    'selectionOptions' => json_decode($c->getSelectionOptions() ?? '[]', true) ?? [],
                ], $columns);
            } catch (\Throwable $e) {
                $this->logger->warning('[webtrack] Tables PHP API (columns) failed, trying HTTP: ' . $e->getMessage());
            }
        }
        return $this->getColumns($tableId);
    }

    /**
     * Returns all Tables the authenticated user can access.
     *
     * @return array<array{id:int,title:string,emoji:string}>
     * @throws \RuntimeException
     */
    public function listTables(): array {
        return $this->get('/tables');
    }

    /**
     * Returns full schema (columns + views) for a single table.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    public function getTableSchema(int $tableId): array {
        return $this->get("/tables/{$tableId}");
    }

    /**
     * Returns the columns defined for a table.
     *
     * @return array<array{id:int,title:string,type:string,subtype:string}>
     * @throws \RuntimeException
     */
    public function getColumns(int $tableId): array {
        return $this->get("/tables/{$tableId}/columns");
    }

    /**
     * Inserts a new row into a table.
     *
     * $data must be an array of column-value pairs:
     *   [['columnId' => 77, 'value' => '2024-10-29'], ...]
     *
     * @param array<array{columnId:int,value:mixed}> $data
     * @return array<string, mixed> Created row
     * @throws \RuntimeException
     */
    public function insertRow(int $tableId, array $data): array {
        return $this->post("/tables/{$tableId}/rows", ['data' => $data]);
    }

    /**
     * Searches rows in a table using a simple filter.
     *
     * $filter elements follow the Tables API format:
     *   [['columnId' => 77, 'operator' => 'is-equal', 'value' => '...']]
     *
     * @param array<array{columnId:int,operator:string,value:mixed}> $filter
     * @return array<array<string, mixed>>
     * @throws \RuntimeException
     */
    public function searchRows(int $tableId, array $filter, int $limit = 50, int $offset = 0): array {
        return $this->post("/tables/{$tableId}/rows/search", [
            'limit'   => $limit,
            'offset'  => $offset,
            'filter'  => $filter,
        ]);
    }

    /**
     * Checks whether the given URL already appears in the Headline column of
     * the target table.  Uses a `contains` filter on the column identified by
     * $headlineColumnId; returns true if at least one row is found.
     *
     * @param int    $tableId         Table to search
     * @param int    $headlineColumnId Column ID of the "Headline" column
     * @param string $url             Article URL to look for
     * @throws \RuntimeException
     */
    public function rowExistsForUrl(int $tableId, int $headlineColumnId, string $url): bool {
        $rows = $this->searchRows(
            tableId: $tableId,
            filter: [[
                'columnId' => $headlineColumnId,
                'operator' => 'contains',
                'value'    => $url,
            ]],
            limit: 1,
        );
        return count($rows) > 0;
    }

    /**
     * Creates a single column in the given table via the Tables PHP service
     * (preferred) or the HTTP REST API (fallback).
     *
     * $colDef keys: type, subtype, title, description, mandatory,
     *   plus type-specific keys (selectionOptions, textDefault, numberDefault, etc.).
     *
     * @param array<string, mixed> $colDef
     * @return array<string, mixed> Created column definition
     * @throws \RuntimeException
     */
    public function createColumn(int $tableId, array $colDef, string $userId): array {
        // Try the Tables PHP service first (available in all contexts).
        if (class_exists('\OCA\Tables\Service\ColumnService')) {
            try {
                /** @var \OCA\Tables\Service\ColumnService $svc */
                $svc = \OCP\Server::get(\OCA\Tables\Service\ColumnService::class);
                $col = $svc->create(
                    userId:           $userId,
                    tableId:          $tableId,
                    viewId:           null,
                    type:             $colDef['type'],
                    subtype:          $colDef['subtype'] ?? '',
                    title:            $colDef['title'],
                    mandatory:        $colDef['mandatory'] ?? false,
                    description:      $colDef['description'] ?? '',
                    textDefault:      $colDef['textDefault'] ?? '',
                    textAllowedPattern: $colDef['textAllowedPattern'] ?? '',
                    textMaxLength:    $colDef['textMaxLength'] ?? null,
                    numberDefault:    isset($colDef['numberDefault']) ? (float) $colDef['numberDefault'] : null,
                    numberMin:        $colDef['numberMin'] ?? null,
                    numberMax:        $colDef['numberMax'] ?? null,
                    numberDecimals:   $colDef['numberDecimals'] ?? 0,
                    numberPrefix:     $colDef['numberPrefix'] ?? '',
                    numberSuffix:     $colDef['numberSuffix'] ?? '',
                    selectionOptions: $colDef['selectionOptions'] ?? '',
                    selectionDefault: $colDef['selectionDefault'] ?? '',
                    datetimeDefault:  $colDef['datetimeDefault'] ?? '',
                    usergroupDefault:           $colDef['usergroupDefault'] ?? [],
                    usergroupAllowUsernames:    $colDef['usergroupAllowUsernames'] ?? false,
                    usergroupAllowGroups:       $colDef['usergroupAllowGroups'] ?? false,
                    usergroupAllowTeams:        $colDef['usergroupAllowTeams'] ?? false,
                    showUserStatus:             $colDef['showUserStatus'] ?? false,
                );
                return [
                    'id'    => $col->getId(),
                    'title' => $col->getTitle(),
                    'type'  => $col->getType(),
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('[webtrack] Tables PHP column create failed, trying HTTP: ' . $e->getMessage());
            }
        }
        return $this->post("/tables/{$tableId}/columns", $colDef);
    }

    /**
     * Ensures the given table has the standard PR Coverage column schema.
     * Does nothing (and does not fail) if the table already has columns.
     *
     * Column IDs in the selection options are chosen to match the constants in
     * DomainLookupService so that row insertion works without re-mapping.
     *
     * @throws \RuntimeException on HTTP/PHP service failure
     */
    public function ensurePrCoverageColumns(int $tableId, string $userId): void {
        $existing = $this->getColumns($tableId);
        if ($existing !== []) {
            return;   // already set up
        }

        $columns = [
            ['type' => 'datetime', 'subtype' => 'date',  'title' => 'Date',      'mandatory' => true],
            ['type' => 'text',     'subtype' => 'line',   'title' => 'Publication'],
            [
                'type'             => 'selection',
                'subtype'          => '',
                'title'            => 'Country',
                'selectionOptions' => json_encode([
                    ['id' =>  0, 'label' => 'Germany'],
                    ['id' =>  1, 'label' => 'Austria'],
                    ['id' =>  2, 'label' => 'Switzerland'],
                    ['id' =>  3, 'label' => 'France'],
                    ['id' =>  4, 'label' => 'Spain'],
                    ['id' =>  5, 'label' => 'Netherlands'],
                    ['id' =>  6, 'label' => 'Belgium'],
                    ['id' =>  7, 'label' => 'UK'],
                    ['id' =>  8, 'label' => 'US'],
                    ['id' =>  9, 'label' => 'Denmark'],
                    ['id' => 10, 'label' => 'Sweden'],
                    ['id' => 11, 'label' => 'Finland'],
                    ['id' => 12, 'label' => 'Norway'],
                    ['id' => 13, 'label' => 'Japan'],
                    ['id' => 14, 'label' => 'Middle East'],
                    ['id' => 15, 'label' => 'Italy'],
                    ['id' => 16, 'label' => 'EU'],
                    ['id' => 17, 'label' => 'Other'],
                ]),
            ],
            [
                'type'             => 'selection',
                'subtype'          => '',
                'title'            => 'Tier',
                'selectionOptions' => json_encode([
                    ['id' => 0, 'label' => 'major business press & newspapers'],
                    ['id' => 1, 'label' => 'major tech or industry trade press'],
                    ['id' => 2, 'label' => 'YouTube / podcasts / other channels'],
                    ['id' => 3, 'label' => 'local tech press'],
                    ['id' => 4, 'label' => 'other'],
                ]),
            ],
            [
                'type'             => 'selection',
                'subtype'          => '',
                'title'            => 'Category',
                'selectionOptions' => json_encode([
                    ['id' => 0, 'label' => 'Media Article'],
                    ['id' => 1, 'label' => 'YouTube'],
                    ['id' => 2, 'label' => 'Blog'],
                    ['id' => 3, 'label' => 'TV'],
                    ['id' => 4, 'label' => 'Radio'],
                    ['id' => 5, 'label' => 'other'],
                    ['id' => 6, 'label' => 'Podcast'],
                ]),
            ],
            ['type' => 'text', 'subtype' => 'rich', 'title' => 'Headline'],
            [
                'type'             => 'selection',
                'subtype'          => '',
                'title'            => 'Source',
                'selectionOptions' => json_encode([
                    ['id' => 0, 'label' => 'Organic'],
                    ['id' => 1, 'label' => 'Press release'],
                    ['id' => 2, 'label' => 'Interview'],
                    ['id' => 3, 'label' => 'Written statement'],
                    ['id' => 4, 'label' => 'Blog'],
                ]),
            ],
            [
                'type'             => 'selection',
                'subtype'          => '',
                'title'            => 'Volume',
                'selectionOptions' => json_encode([
                    ['id' => 0, 'label' => 'Exclusive'],
                    ['id' => 1, 'label' => 'Major mention'],
                    ['id' => 2, 'label' => 'Minor mention'],
                ]),
            ],
            ['type' => 'text',   'subtype' => 'line', 'title' => 'Journalist'],
            ['type' => 'number', 'subtype' => '',     'title' => 'Counter',       'numberDefault' => 1],
            ['type' => 'text',   'subtype' => 'line', 'title' => 'Primary Topic'],
            ['type' => 'text',   'subtype' => 'rich', 'title' => 'Comment'],
        ];

        foreach ($columns as $colDef) {
            $this->createColumn($tableId, $colDef, $userId);
        }

        $this->logger->info('[webtrack] Created PR Coverage schema ({n} columns) in table {id}', [
            'n'  => count($columns),
            'id' => $tableId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Performs a GET request to the Tables API.
     *
     * @return array<mixed>
     * @throws \RuntimeException
     */
    private function get(string $path): array {
        $url = $this->buildUrl($path);
        $this->logger->debug('[webtrack/tables] GET {url}', ['url' => $url]);
        try {
            $response = $this->clientService->newClient()->get($url, $this->defaultOptions());
            return $this->decode($response->getBody());
        } catch (\Throwable $e) {
            throw new \RuntimeException("Tables GET {$path} failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Performs a POST request to the Tables API.
     *
     * @param array<mixed> $body
     * @return array<mixed>
     * @throws \RuntimeException
     */
    private function post(string $path, array $body): array {
        $url = $this->buildUrl($path);
        $this->logger->debug('[webtrack/tables] POST {url}', ['url' => $url]);
        try {
            $response = $this->clientService->newClient()->post($url, array_merge(
                $this->defaultOptions(),
                ['json' => $body],
            ));
            return $this->decode($response->getBody());
        } catch (\Throwable $e) {
            throw new \RuntimeException("Tables POST {$path} failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Builds the full API URL for a given path.
     */
    private function buildUrl(string $path): string {
        if ($this->apiBase === '') {
            // Generate an internal URL to any app route; strip the path component
            // so we are left with just https://hostname (no trailing slash).
            $sample   = $this->urlGenerator->getAbsoluteURL('/');
            $this->apiBase = rtrim($sample, '/') . '/apps/tables/api/1';
        }
        return $this->apiBase . $path;
    }

    /**
     * Default HTTP options shared by all requests.
     *
     * @return array<string, mixed>
     */
    private function defaultOptions(): array {
        return [
            'timeout'         => 15,
            'connect_timeout' => 5,
            'headers'         => [
                'Accept'       => 'application/json',
                'OCS-APIREQUEST' => 'true',
            ],
        ];
    }

    /**
     * JSON-decodes a response body; throws on failure.
     *
     * @return array<mixed>
     * @throws \RuntimeException
     */
    private function decode(mixed $body): array {
        $raw = is_string($body) ? $body : (string) $body;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Tables API returned non-JSON response: ' . substr($raw, 0, 200));
        }
        return $decoded;
    }
}
