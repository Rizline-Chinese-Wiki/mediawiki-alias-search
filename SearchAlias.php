<?php
/**
 * RizWiki 搜索别名增强 (Cargo + JSON 双数据源版本)
 * 1. 从 Cargo 表读取模板添加的 tag（用于 #tag# 语法过滤）
 * 2. 从 JSON 文件读取原有别名（用于关键词搜索）
 * 3. 搜索优先级：原名匹配 > 别名匹配 > tag匹配
 * 4. 使用 #tag# 语法搜索tag，多个tag为AND关系
 */

if ( !defined( 'MEDIAWIKI' ) ) {
    die( 'Not an entry point.' );
}

use MediaWiki\MediaWikiServices;

class RizSearchAlias {

    // Cargo 配置 - 存储 tag 数据
    private const CARGO_TABLE = 'pagetags';

    // JSON 配置 - 存储别名数据
    private const JSON_PAGE = 'Songdata.json';

    // 缓存配置
    private const CACHE_KEY_CARGO = 'riz_alias_cargo_v2';
    private const CACHE_KEY_JSON = 'riz_song_alias_v7';
    private const CACHE_TTL = 3600;

    /**
     * 从 Cargo 表获取 TAG 数据
     * 返回格式: [ 'PageName' => ['tag1', 'tag2', ...], ... ]
     */
    public static function getTagData(): array {
        $services = MediaWikiServices::getInstance();
        $cache = $services->getMainWANObjectCache();
        $cacheKey = $cache->makeKey( self::CACHE_KEY_CARGO );

        return $cache->getWithSetCallback( $cacheKey, self::CACHE_TTL, function () {
            return self::loadTagsFromCargo();
        });
    }

    /**
     * 从 JSON 文件获取别名数据
     * 返回格式: [ 'PageName' => ['alias1', 'alias2', ...], ... ]
     */
    public static function getAliasData(): array {
        $services = MediaWikiServices::getInstance();
        $cache = $services->getMainWANObjectCache();
        $cacheKey = $cache->makeKey( self::CACHE_KEY_JSON );

        return $cache->getWithSetCallback( $cacheKey, self::CACHE_TTL, function () use ( $services ) {
            return self::loadAliasesFromJson( $services );
        });
    }

    /**
     * 从 Cargo 表加载 TAG（按页面名索引）
     */
    private static function loadTagsFromCargo(): array {
        $data = [];

        try {
            $services = MediaWikiServices::getInstance();
            if ( method_exists( $services, 'getConnectionProvider' ) ) {
                $dbr = $services->getConnectionProvider()->getReplicaDatabase();
            } elseif ( method_exists( $services, 'getDBLoadBalancer' ) ) {
                $dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
            } else {
                $dbr = \wfGetDB( DB_REPLICA );
            }

            $tableName = 'cargo__' . self::CARGO_TABLE;

            $res = $dbr->select(
                $tableName,
                '*',
                [],
                __METHOD__
            );

            foreach ( $res as $row ) {
                $rowArray = (array)$row;
                $pageName = $rowArray['_pageName'] ?? $rowArray['_pageTitle'] ?? $rowArray['_pageID'] ?? '';
                $tagsStr = $rowArray['tags__full'] ?? $rowArray['tags'] ?? '';

                if ( !$pageName || !$tagsStr ) {
                    continue;
                }

                $tags = array_filter( array_map( 'trim', explode( ',', $tagsStr ) ) );

                if ( empty( $tags ) ) {
                    continue;
                }

                if ( !isset( $data[$pageName] ) ) {
                    $data[$pageName] = [];
                }
                $data[$pageName] = array_values( array_unique( array_merge( $data[$pageName], $tags ) ) );
            }
        } catch ( \Throwable $e ) {
            error_log( 'RizSearchAlias: Cargo error: ' . $e->getMessage() );
        }

        return $data;
    }

    /**
     * 从 JSON 页面加载别名（按页面名索引）
     */
    private static function loadAliasesFromJson( $services ): array {
        if ( method_exists( $services, 'getTitleFactory' ) ) {
            $titleObj = $services->getTitleFactory()->newFromText( self::JSON_PAGE );
        } else {
            $titleObj = \Title::newFromText( self::JSON_PAGE );
        }

        if ( !$titleObj || !$titleObj->exists() ) {
            return [];
        }

        if ( method_exists( $services, 'getWikiPageFactory' ) ) {
            $wikiPage = $services->getWikiPageFactory()->newFromTitle( $titleObj );
        } else {
            $wikiPage = \WikiPage::factory( $titleObj );
        }

        $content = $wikiPage->getContent();
        if ( !$content ) {
            return [];
        }

        $rawData = [];
        if ( $content instanceof \JsonContent ) {
            $jsonData = $content->getData();
            if ( $jsonData && $jsonData->isGood() ) {
                $value = $jsonData->getValue();
                $rawData = json_decode( json_encode( $value ), true ) ?? [];
            } else {
                $text = $content->getText();
                $rawData = json_decode( $text, true ) ?? [];
            }
        } else {
            $text = self::extractText( $content );
            $rawData = json_decode( $text, true ) ?? [];
        }

        // 转换为按页面名索引的格式
        $data = [];
        foreach ( $rawData as $item ) {
            $title = $item['title'] ?? '';
            $aliases = $item['aliases'] ?? [];
            if ( $title && !empty( $aliases ) ) {
                $data[$title] = $aliases;
            }
        }

        return $data;
    }

    private static function extractText( $content ): string {
        if ( $content instanceof \TextContent ) {
            return $content->getText();
        }
        if ( method_exists( $content, 'getNativeData' ) ) {
            return $content->getNativeData();
        }
        return \ContentHandler::getContentText( $content ) ?? '';
    }

    /**
     * 清除所有缓存
     */
    public static function clearCache(): void {
        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        $cache->delete( $cache->makeKey( self::CACHE_KEY_CARGO ) );
        $cache->delete( $cache->makeKey( self::CACHE_KEY_JSON ) );
    }

    public static function clearCargoCache(): void {
        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        $cache->delete( $cache->makeKey( self::CACHE_KEY_CARGO ) );
    }

    public static function clearJsonCache(): void {
        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        $cache->delete( $cache->makeKey( self::CACHE_KEY_JSON ) );
    }

    /**
     * 解析搜索词，提取 #tag# 和关键词
     * 返回: ['tags' => ['tag1', 'tag2'], 'keyword' => '关键词']
     */
    public static function parseSearchTerm( string $term ): array {
        $tags = [];
        // 匹配所有 #xxx# 格式的tag
        if ( preg_match_all( '/#([^#]+)#/', $term, $matches ) ) {
            $tags = array_map( 'trim', $matches[1] );
        }
        // 移除 #xxx# 后剩余的就是关键词
        $keyword = trim( preg_replace( '/#[^#]+#/', '', $term ) );

        return [
            'tags' => $tags,
            'keyword' => $keyword
        ];
    }

    /**
     * 检查页面是否拥有所有指定的tag（AND关系）
     */
    public static function pageHasAllTags( string $pageName, array $requiredTags, array $tagData ): bool {
        if ( empty( $requiredTags ) ) {
            return true;
        }

        $pageTags = $tagData[$pageName] ?? [];
        if ( empty( $pageTags ) ) {
            return false;
        }

        // 转为小写比较
        $pageTagsLower = array_map( 'mb_strtolower', $pageTags );

        foreach ( $requiredTags as $reqTag ) {
            $reqTagLower = mb_strtolower( trim( $reqTag ) );
            $found = false;
            foreach ( $pageTagsLower as $pt ) {
                if ( mb_strpos( $pt, $reqTagLower ) !== false ) {
                    $found = true;
                    break;
                }
            }
            if ( !$found ) {
                return false;
            }
        }

        return true;
    }

    /**
     * 核心搜索方法 - 返回匹配结果（按优先级排序）
     * 优先级：原名匹配 > 别名匹配 > tag匹配
     */
    public static function searchMatches( string $term, int $limit = 10 ): array {
        $term = trim( $term );
        if ( mb_strlen( $term ) < 1 ) {
            return [];
        }

        // 解析搜索词
        $parsed = self::parseSearchTerm( $term );
        $requiredTags = $parsed['tags'];
        $keyword = mb_strtolower( $parsed['keyword'] );

        // 获取数据
        $tagData = self::getTagData();
        $aliasData = self::getAliasData();

        // 收集所有可能的页面名
        $allPages = array_unique( array_merge(
            array_keys( $tagData ),
            array_keys( $aliasData )
        ) );

        // 如果有tag过滤条件，先过滤
        if ( !empty( $requiredTags ) ) {
            $allPages = array_filter( $allPages, function( $page ) use ( $requiredTags, $tagData ) {
                return self::pageHasAllTags( $page, $requiredTags, $tagData );
            });
        }

        // 三个优先级的结果数组
        $titleMatches = [];  // 原名匹配
        $aliasMatches = [];  // 别名匹配
        $tagOnlyMatches = []; // 仅tag匹配（没有关键词时）

        foreach ( $allPages as $pageName ) {
            $pageAliases = $aliasData[$pageName] ?? [];
            $pageTags = $tagData[$pageName] ?? [];

            // 如果没有关键词，只做tag过滤
            if ( $keyword === '' ) {
                // 没有关键词但有tag条件，说明是纯tag搜索
                if ( !empty( $requiredTags ) ) {
                    $tagOnlyMatches[] = [
                        'title' => $pageName,
                        'matchedAlias' => implode( ', ', $requiredTags ),
                        'source' => 'tag'
                    ];
                }
                continue;
            }

            // 有关键词，按优先级匹配
            $matched = false;

            // 1. 原名匹配（最高优先级）
            if ( mb_strpos( mb_strtolower( $pageName ), $keyword ) !== false ) {
                $titleMatches[] = [
                    'title' => $pageName,
                    'matchedAlias' => $pageName,
                    'source' => 'title'
                ];
                $matched = true;
            }

            // 2. 别名匹配（次优先级）
            if ( !$matched && !empty( $pageAliases ) ) {
                foreach ( $pageAliases as $alias ) {
                    if ( mb_strpos( mb_strtolower( $alias ), $keyword ) !== false ) {
                        $aliasMatches[] = [
                            'title' => $pageName,
                            'matchedAlias' => $alias,
                            'source' => 'alias'
                        ];
                        $matched = true;
                        break;
                    }
                }
            }
        }

        // 合并结果：原名匹配 > 别名匹配 > tag匹配
        $results = array_merge( $titleMatches, $aliasMatches, $tagOnlyMatches );

        // 去重（同一页面只保留最高优先级的匹配）
        $seen = [];
        $dedupedResults = [];
        foreach ( $results as $match ) {
            if ( !isset( $seen[$match['title']] ) ) {
                $seen[$match['title']] = true;
                $dedupedResults[] = $match;
            }
        }

        return array_slice( $dedupedResults, 0, $limit );
    }

    /**
     * 简单搜索 - 只返回标题列表
     */
    public static function searchMatchTitles( string $term, int $limit = 10 ): array {
        $matches = self::searchMatches( $term, $limit );
        return array_map( function( $m ) { return $m['title']; }, $matches );
    }

    public static function makeTitle( string $text ) {
        $services = MediaWikiServices::getInstance();
        if ( method_exists( $services, 'getTitleFactory' ) ) {
            return $services->getTitleFactory()->newFromText( $text );
        }
        return \Title::newFromText( $text );
    }

    public static function onPrefixSearch( $namespaces, $search, $limit, &$results ): bool {
        try {
            if ( is_array( $namespaces ) && !empty( $namespaces ) && !in_array( NS_MAIN, $namespaces ) ) {
                return true;
            }
            $matches = self::searchMatchTitles( $search, $limit );
            foreach ( $matches as $title ) {
                if ( !in_array( $title, $results ) ) {
                    $results[] = $title;
                }
            }
        } catch ( \Throwable $e ) {}
        return true;
    }

    /**
     * 页面保存时清除相应缓存
     */
    public static function onPageSave( $wikiPage ): bool {
        try {
            $titleText = $wikiPage->getTitle()->getPrefixedText();

            if ( $titleText === self::JSON_PAGE ) {
                self::clearJsonCache();
            }

            self::clearCargoCache();
        } catch ( \Throwable $e ) {}
        return true;
    }

    // ============ 兼容旧接口 ============

    /**
     * @deprecated 使用 getTagData() 和 getAliasData() 代替
     */
    public static function getData(): array {
        // 兼容旧代码，返回合并格式
        $tagData = self::getTagData();
        $aliasData = self::getAliasData();

        $result = [];
        $allPages = array_unique( array_merge( array_keys( $tagData ), array_keys( $aliasData ) ) );

        foreach ( $allPages as $page ) {
            $result[] = [
                'title' => $page,
                'aliases' => array_merge( $aliasData[$page] ?? [], $tagData[$page] ?? [] )
            ];
        }

        return $result;
    }

    /**
     * @deprecated 使用 getTagData() 代替
     */
    public static function getCargoData(): array {
        $tagData = self::getTagData();
        $result = [];
        foreach ( $tagData as $page => $tags ) {
            $result[] = [ 'title' => $page, 'aliases' => $tags ];
        }
        return $result;
    }

    /**
     * @deprecated 使用 getAliasData() 代替
     */
    public static function getJsonData(): array {
        $aliasData = self::getAliasData();
        $result = [];
        foreach ( $aliasData as $page => $aliases ) {
            $result[] = [ 'title' => $page, 'aliases' => $aliases ];
        }
        return $result;
    }
}

// ============================================
// 自定义 SearchEngine - 支持完整原生搜索回退
// ============================================

$wgRizOriginalSearchType = $wgSearchType ?? null;
$wgSearchType = 'RizAliasSearchEngine';

class RizAliasSearchEngine extends \SearchEngine {

    private function getDbConnection() {
        $services = MediaWikiServices::getInstance();
        if ( method_exists( $services, 'getConnectionProvider' ) ) {
            return $services->getConnectionProvider()->getPrimaryDatabase();
        } elseif ( method_exists( $services, 'getDBLoadBalancer' ) ) {
            return $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
        }
        return wfGetDB( DB_REPLICA );
    }

    /**
     * 自动补全搜索 (REST API / 搜索框联想)
     */
    protected function completionSearchBackend( $search ) {
        $results = [];

        // 1. 别名匹配 (Cargo + JSON)
        try {
            $aliasMatches = RizSearchAlias::searchMatchTitles( $search, $this->limit );
            foreach ( $aliasMatches as $titleText ) {
                $titleObj = RizSearchAlias::makeTitle( $titleText );
                if ( $titleObj && $titleObj->exists() ) {
                    $results[] = $titleObj->getPrefixedText();
                }
            }
        } catch ( \Throwable $e ) {}

        // 2. 数据库标题搜索
        try {
            $db = $this->getDbConnection();
            $searchLower = mb_strtolower( trim( $search ) );

            if ( mb_strlen( $searchLower ) >= 1 ) {
                $like = $db->buildLike( $db->anyString(), $searchLower, $db->anyString() );

                $res = $db->select(
                    'page',
                    [ 'page_namespace', 'page_title' ],
                    [
                        'page_namespace' => NS_MAIN,
                        'LOWER(page_title) ' . $like
                    ],
                    __METHOD__,
                    [ 'LIMIT' => $this->limit ]
                );

                $services = MediaWikiServices::getInstance();
                foreach ( $res as $row ) {
                    if ( method_exists( $services, 'getTitleFactory' ) ) {
                        $title = $services->getTitleFactory()->makeTitle( $row->page_namespace, $row->page_title );
                    } else {
                        $title = \Title::makeTitle( $row->page_namespace, $row->page_title );
                    }

                    if ( $title ) {
                        $text = $title->getPrefixedText();
                        if ( !in_array( $text, $results ) ) {
                            $results[] = $text;
                        }
                    }
                }
            }
        } catch ( \Throwable $e ) {}

        $results = array_slice( $results, 0, $this->limit );
        return \SearchSuggestionSet::fromStrings( $results );
    }

    /**
     * 前缀搜索
     */
    public function defaultPrefixSearch( $search ) {
        $results = [];

        // 1. 别名匹配
        try {
            $aliasMatches = RizSearchAlias::searchMatchTitles( $search, $this->limit );
            foreach ( $aliasMatches as $titleText ) {
                $titleObj = RizSearchAlias::makeTitle( $titleText );
                if ( $titleObj && $titleObj->exists() ) {
                    $results[] = $titleObj;
                }
            }
        } catch ( \Throwable $e ) {}

        // 2. 数据库前缀搜索
        try {
            $db = $this->getDbConnection();
            $searchDb = str_replace( ' ', '_', $search );

            $res = $db->select(
                'page',
                [ 'page_namespace', 'page_title' ],
                [
                    'page_namespace' => NS_MAIN,
                    'page_title ' . $db->buildLike( $searchDb, $db->anyString() )
                ],
                __METHOD__,
                [ 'LIMIT' => $this->limit ]
            );

            $services = MediaWikiServices::getInstance();
            foreach ( $res as $row ) {
                if ( method_exists( $services, 'getTitleFactory' ) ) {
                    $title = $services->getTitleFactory()->makeTitle( $row->page_namespace, $row->page_title );
                } else {
                    $title = \Title::makeTitle( $row->page_namespace, $row->page_title );
                }

                if ( $title ) {
                    $exists = false;
                    foreach ( $results as $r ) {
                        if ( $r->equals( $title ) ) {
                            $exists = true;
                            break;
                        }
                    }
                    if ( !$exists ) {
                        $results[] = $title;
                    }
                }
            }
        } catch ( \Throwable $e ) {}

        return array_slice( $results, 0, $this->limit );
    }

    /**
     * 获取原生搜索引擎实例 (工厂方法)
     * 自动判断数据库类型 (MySQL/SQLite/Postgres) 并传递正确参数
     */
    private function getFallbackEngine() {
        $services = MediaWikiServices::getInstance();

        // MediaWiki 1.41+ 需要 IConnectionProvider
        if ( method_exists( $services, 'getConnectionProvider' ) ) {
            $dbProvider = $services->getConnectionProvider();
        } else {
            // 旧版本回退
            $dbProvider = $services->getDBLoadBalancer();
        }

        $config = $services->getSearchEngineConfig();

        // 获取当前数据库类型
        $dbType = $this->getDbConnection()->getType();

        $engine = null;

        // 根据数据库类型选择回退引擎
        switch ( $dbType ) {
            case 'sqlite':
                if ( class_exists( 'SearchSqlite' ) ) {
                    $engine = new \SearchSqlite( $dbProvider, $config );
                }
                break;
            case 'postgres':
                if ( class_exists( 'SearchPostgres' ) ) {
                    $engine = new \SearchPostgres( $dbProvider, $config );
                }
                break;
            case 'mysql':
            default:
                if ( class_exists( 'SearchMySQL' ) ) {
                    $engine = new \SearchMySQL( $dbProvider, $config );
                }
                break;
        }

        if ( $engine ) {
            $engine->setLimitOffset( $this->limit, $this->offset );
            $engine->setNamespaces( $this->namespaces );
        }

        return $engine;
    }

    /**
     * 全文搜索 - 三路合并：别名匹配 + tag匹配 + 原生全文搜索
     */
    public function searchText( $term ) {
        $services = MediaWikiServices::getInstance();
        $results = [];

        // 1. 别名和tag匹配
        try {
            $aliasMatches = RizSearchAlias::searchMatches( $term, $this->limit );
            foreach ( $aliasMatches as $match ) {
                $titleObj = RizSearchAlias::makeTitle( $match['title'] );
                if ( $titleObj && $titleObj->exists() ) {
                    $results[$match['title']] = [
                        'title' => $titleObj,
                        'source' => $match['source'],
                        'matchedAlias' => $match['matchedAlias']
                    ];
                }
            }
        } catch ( \Throwable $e ) {}

        // 2. 原生全文搜索
        $fallbackEngine = $this->getFallbackEngine();
        $nativeResults = null;

        if ( $fallbackEngine ) {
            try {
                // 解析搜索词，只用关键词部分做全文搜索（去掉 #tag# 部分）
                $parsed = RizSearchAlias::parseSearchTerm( $term );
                $keyword = $parsed['keyword'];

                if ( $keyword !== '' ) {
                    $nativeResults = $fallbackEngine->searchText( $keyword );
                }
            } catch ( \Throwable $e ) {}
        }

        // 3. 合并结果，创建自定义 SearchResultSet
        return new RizSearchResultSet( $results, $nativeResults );
    }

    /**
     * 标题搜索 - 三路合并
     */
    public function searchTitle( $term ) {
        $services = MediaWikiServices::getInstance();
        $results = [];

        // 1. 别名和tag匹配
        try {
            $aliasMatches = RizSearchAlias::searchMatches( $term, $this->limit );
            foreach ( $aliasMatches as $match ) {
                $titleObj = RizSearchAlias::makeTitle( $match['title'] );
                if ( $titleObj && $titleObj->exists() ) {
                    $results[$match['title']] = [
                        'title' => $titleObj,
                        'source' => $match['source'],
                        'matchedAlias' => $match['matchedAlias']
                    ];
                }
            }
        } catch ( \Throwable $e ) {}

        // 2. 原生标题搜索
        $fallbackEngine = $this->getFallbackEngine();
        $nativeResults = null;

        if ( $fallbackEngine ) {
            try {
                $parsed = RizSearchAlias::parseSearchTerm( $term );
                $keyword = $parsed['keyword'];

                if ( $keyword !== '' ) {
                    $nativeResults = $fallbackEngine->searchTitle( $keyword );
                }
            } catch ( \Throwable $e ) {}
        }

        return new RizSearchResultSet( $results, $nativeResults );
    }
}

/**
 * 自定义搜索结果集 - 合并别名匹配和原生搜索结果
 */
class RizSearchResultSet extends \SearchResultSet {
    private $aliasResults;
    private $nativeResults;
    private $allResults = [];
    private $position = 0;

    public function __construct( array $aliasResults, $nativeResults = null ) {
        $this->aliasResults = $aliasResults;
        $this->nativeResults = $nativeResults;

        // 先添加别名匹配结果
        foreach ( $aliasResults as $key => $data ) {
            $this->allResults[] = new RizSearchResult( $data['title'], $data['source'], $data['matchedAlias'] );
        }

        // 再添加原生搜索结果（去重）
        if ( $nativeResults ) {
            $seen = array_keys( $aliasResults );
            foreach ( $nativeResults as $result ) {
                $title = $result->getTitle();
                if ( $title ) {
                    $titleText = $title->getPrefixedText();
                    if ( !in_array( $titleText, $seen ) ) {
                        $seen[] = $titleText;
                        $this->allResults[] = $result;
                    }
                }
            }
        }
    }

    public function numRows() {
        return count( $this->allResults );
    }

    public function hasMoreResults() {
        return false;
    }

    public function next() {
        if ( $this->position < count( $this->allResults ) ) {
            return $this->allResults[$this->position++];
        }
        return false;
    }

    public function rewind() {
        $this->position = 0;
    }

    public function free() {
        $this->allResults = [];
    }

    public function extractResults() {
        return $this->allResults;
    }

    public function extractTitles() {
        $titles = [];
        foreach ( $this->allResults as $result ) {
            $title = $result->getTitle();
            if ( $title ) {
                $titles[] = $title;
            }
        }
        return $titles;
    }
}

/**
 * 自定义搜索结果项
 */
class RizSearchResult extends \SearchResult {
    protected $mTitle;
    protected $source;
    protected $matchedAlias;

    public function __construct( $title, $source = 'title', $matchedAlias = '' ) {
        $this->mTitle = $title;
        $this->source = $source;
        $this->matchedAlias = $matchedAlias;
    }

    public function getTitle() {
        return $this->mTitle;
    }

    public function isMissingRevision() {
        return false;
    }

    public function getTextSnippet( $terms = [] ) {
        if ( $this->source === 'alias' && $this->matchedAlias ) {
            return '别名匹配: ' . htmlspecialchars( $this->matchedAlias );
        } elseif ( $this->source === 'tag' ) {
            return 'Tag匹配: ' . htmlspecialchars( $this->matchedAlias );
        }
        return '';
    }
}

// ============================================
// 注册 Hooks
// ============================================

$wgHooks['PrefixSearchBackend'][] = 'RizSearchAlias::onPrefixSearch';
$wgHooks['PageContentSaveComplete'][] = 'RizSearchAlias::onPageSave';

// Cargo 表重建时清除缓存
$wgHooks['CargoTablesActionAfterRecreateTable'][] = function ( $tableName ) {
    if ( strtolower( $tableName ) === 'pagetags' ) {
        RizSearchAlias::clearCargoCache();
    }
    return true;
};

// ============================================
// 搜索结果页显示别名匹配（不直接跳转）
// ============================================
$wgHooks['SpecialSearchResultsPrepend'][] = function ( $specialSearch, $output, $term ) {
    try {
        $matches = RizSearchAlias::searchMatches( $term, 10 );

        // 解析搜索词，获取tag信息用于显示
        $parsed = RizSearchAlias::parseSearchTerm( $term );
        $hasTags = !empty( $parsed['tags'] );

        if ( !empty( $matches ) ) {
            $html = '<div class="riz-alias-results" style="margin-bottom: 1.5em; padding: 1em; background: rgba(51, 102, 204, 0.1); border-left: 4px solid #3366cc; border-radius: 4px;">';

            // 根据搜索类型显示不同的标题
            if ( $hasTags && $parsed['keyword'] === '' ) {
                $html .= '<strong style="display: block; margin-bottom: 0.5em;">Tag 筛选结果（' . htmlspecialchars( implode( ' + ', $parsed['tags'] ) ) . '）：</strong>';
            } elseif ( $hasTags ) {
                $html .= '<strong style="display: block; margin-bottom: 0.5em;">筛选结果（Tag: ' . htmlspecialchars( implode( ' + ', $parsed['tags'] ) ) . '）：</strong>';
            } else {
                $html .= '<strong style="display: block; margin-bottom: 0.5em;">别名匹配结果：</strong>';
            }

            $html .= '<ul style="margin: 0; padding-left: 1.5em;">';

            foreach ( $matches as $match ) {
                $titleText = $match['title'];
                $matchedAlias = $match['matchedAlias'];
                $source = $match['source'];

                $titleObj = RizSearchAlias::makeTitle( $titleText );
                if ( $titleObj && $titleObj->exists() ) {
                    $url = $titleObj->getLocalURL();

                    // 根据匹配来源显示不同的提示
                    if ( $source === 'alias' && $matchedAlias !== $titleText ) {
                        $html .= '<li><a href="' . htmlspecialchars( $url ) . '">' . htmlspecialchars( $titleText ) . '</a>';
                        $html .= ' <span style="color: #888; font-size: 0.9em;">(别名: ' . htmlspecialchars( $matchedAlias ) . ')</span></li>';
                    } elseif ( $source === 'tag' ) {
                        $html .= '<li><a href="' . htmlspecialchars( $url ) . '">' . htmlspecialchars( $titleText ) . '</a>';
                        $html .= ' <span style="color: #888; font-size: 0.9em;">(Tag匹配)</span></li>';
                    } else {
                        $html .= '<li><a href="' . htmlspecialchars( $url ) . '">' . htmlspecialchars( $titleText ) . '</a></li>';
                    }
                }
            }

            $html .= '</ul></div>';
            $output->addHTML( $html );
        }
    } catch ( \Throwable $e ) {}

    return true;
};

// 注意：移除了 SearchGetNearMatch 钩子，不再直接跳转到页面
// 所有搜索都会跳转到 Special:Search 页面并显示别名匹配框

// ============================================
// 调试 API
// ============================================
$wgAPIModules['rizsearchdebug'] = 'ApiRizSearchDebug';

class ApiRizSearchDebug extends \ApiBase {
    public function execute() {
        $search = $this->getParameter( 'search' ) ?? '';
        $result = $this->getResult();

        try {
            // 强制清除缓存以便调试
            RizSearchAlias::clearCache();

            $tagData = RizSearchAlias::getTagData();
            $aliasData = RizSearchAlias::getAliasData();

            $result->addValue( null, 'tag_pages_count', count( $tagData ) );
            $result->addValue( null, 'alias_pages_count', count( $aliasData ) );

            // 显示示例数据
            if ( count( $tagData ) > 0 ) {
                $sample = [];
                $i = 0;
                foreach ( $tagData as $page => $tags ) {
                    $sample[] = [ 'page' => $page, 'tags' => $tags ];
                    if ( ++$i >= 3 ) break;
                }
                $result->addValue( null, 'tag_sample', $sample );
            }

            if ( count( $aliasData ) > 0 ) {
                $sample = [];
                $i = 0;
                foreach ( $aliasData as $page => $aliases ) {
                    $sample[] = [ 'page' => $page, 'aliases' => $aliases ];
                    if ( ++$i >= 3 ) break;
                }
                $result->addValue( null, 'alias_sample', $sample );
            }

            if ( $search ) {
                // 解析搜索词
                $parsed = RizSearchAlias::parseSearchTerm( $search );
                $result->addValue( null, 'parsed_term', $parsed );

                // 执行搜索
                $matches = RizSearchAlias::searchMatches( $search, 10 );
                $result->addValue( null, 'search_term', $search );
                $result->addValue( null, 'matches', $matches );
                $result->addValue( null, 'match_titles', array_map( function($m) { return $m['title']; }, $matches ) );
            }

            global $wgSearchType;
            $result->addValue( null, 'search_type', $wgSearchType );

        } catch ( \Throwable $e ) {
            $result->addValue( null, 'error_message', $e->getMessage() );
        }
    }

    public function getAllowedParams() {
        return [
            'search' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => false,
            ]
        ];
    }

    public function isReadMode() {
        return true;
    }
}
