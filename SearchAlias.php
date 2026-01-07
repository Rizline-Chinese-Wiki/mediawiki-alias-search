<?php
/**
 * RizWiki 搜索别名增强 (Cargo + JSON 双数据源版本)
 * 1. 从 Cargo 表读取模板添加的 tag
 * 2. 从 JSON 文件读取原有别名
 * 3. 在搜索结果页显示别名匹配（不直接跳转）
 * 4. 无匹配时回退到完整原生搜索
 */

if ( !defined( 'MEDIAWIKI' ) ) {
    die( 'Not an entry point.' );
}

use MediaWiki\MediaWikiServices;

class RizSearchAlias {

    // Cargo 配置
    private const CARGO_TABLE = 'pagetags';  // Cargo 表名 (小写)

    // JSON 配置 (保留原有)
    private const JSON_PAGE = 'Songdata.json';

    // 缓存配置
    private const CACHE_KEY_CARGO = 'riz_alias_cargo_v1';
    private const CACHE_KEY_JSON = 'riz_song_alias_v6';
    private const CACHE_TTL = 3600;

    /**
     * 获取合并后的所有别名数据 (Cargo + JSON)
     */
    public static function getData(): array {
        $cargoData = self::getCargoData();
        $jsonData = self::getJsonData();

        // 合并两个数据源
        return array_merge( $cargoData, $jsonData );
    }

    /**
     * 从 Cargo 表获取数据
     */
    public static function getCargoData(): array {
        $services = MediaWikiServices::getInstance();
        $cache = $services->getMainWANObjectCache();
        $cacheKey = $cache->makeKey( self::CACHE_KEY_CARGO );

        return $cache->getWithSetCallback( $cacheKey, self::CACHE_TTL, function () {
            return self::loadFromCargo();
        });
    }

    /**
     * 从 JSON 文件获取数据
     */
    public static function getJsonData(): array {
        $services = MediaWikiServices::getInstance();
        $cache = $services->getMainWANObjectCache();
        $cacheKey = $cache->makeKey( self::CACHE_KEY_JSON );

        return $cache->getWithSetCallback( $cacheKey, self::CACHE_TTL, function () use ( $services ) {
            return self::loadFromJson( $services );
        });
    }

    /**
     * 从 Cargo 表加载（自动合并同一页面的多行数据）
     */
    private static function loadFromCargo(): array {
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

            // 直接查询，不检查表是否存在（因为 tableExists 有时不准）
            $res = $dbr->select(
                $tableName,
                '*',  // 选择所有字段
                [],
                __METHOD__
            );

            // 用于合并同一页面的多行数据
            $pageData = [];

            foreach ( $res as $row ) {
                $rowArray = (array)$row;

                // 尝试获取页面名（可能是 _pageName 或 _pageTitle 或其他）
                $pageName = $rowArray['_pageName'] ?? $rowArray['_pageTitle'] ?? $rowArray['_pageID'] ?? '';

                // 尝试获取 tags（可能是 tags 或 tags__full）
                $tagsStr = $rowArray['tags__full'] ?? $rowArray['tags'] ?? '';

                if ( !$pageName || !$tagsStr ) {
                    continue;
                }

                // Cargo List 类型用 , 分隔
                $tags = array_filter( array_map( 'trim', explode( ',', $tagsStr ) ) );

                if ( empty( $tags ) ) {
                    continue;
                }

                // 合并同一页面的 tags
                if ( !isset( $pageData[$pageName] ) ) {
                    $pageData[$pageName] = [];
                }
                $pageData[$pageName] = array_merge( $pageData[$pageName], $tags );
            }

            // 转换为输出格式，去重
            foreach ( $pageData as $pageName => $tags ) {
                $data[] = [
                    'title' => $pageName,
                    'aliases' => array_values( array_unique( $tags ) )
                ];
            }
        } catch ( \Throwable $e ) {
            error_log( 'RizSearchAlias: Cargo error: ' . $e->getMessage() );
        }

        return $data;
    }

    /**
     * 从 JSON 页面加载 (保留原有逻辑)
     */
    private static function loadFromJson( $services ): array {
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

        if ( $content instanceof \JsonContent ) {
            $jsonData = $content->getData();
            if ( $jsonData && $jsonData->isGood() ) {
                $value = $jsonData->getValue();
                return json_decode( json_encode( $value ), true ) ?? [];
            }
            $text = $content->getText();
        } else {
            $text = self::extractText( $content );
        }

        if ( empty( $text ) ) {
            return [];
        }

        $data = json_decode( $text, true );
        return is_array( $data ) ? $data : [];
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
     * 模糊别名搜索 - 返回匹配的别名信息
     */
    public static function searchMatches( string $term, int $limit = 10 ): array {
        $term = mb_strtolower( trim( $term ) );
        if ( mb_strlen( $term ) < 1 ) {
            return [];
        }

        $data = self::getData();
        if ( empty( $data ) ) {
            return [];
        }

        $matches = [];
        foreach ( $data as $item ) {
            $title = $item['title'] ?? '';
            $aliases = $item['aliases'] ?? [];

            if ( !$title ) {
                continue;
            }

            $matchedAlias = null;
            $matchSource = null;

            // 匹配标题
            if ( mb_strpos( mb_strtolower( $title ), $term ) !== false ) {
                $matchedAlias = $title;
                $matchSource = 'title';
            }

            // 匹配别名
            if ( !$matchedAlias && is_array( $aliases ) ) {
                foreach ( $aliases as $alias ) {
                    if ( mb_strpos( mb_strtolower( $alias ), $term ) !== false ) {
                        $matchedAlias = $alias;
                        $matchSource = 'alias';
                        break;
                    }
                }
            }

            if ( $matchedAlias ) {
                // 检查是否已经添加过这个标题
                $exists = false;
                foreach ( $matches as $m ) {
                    if ( $m['title'] === $title ) {
                        $exists = true;
                        break;
                    }
                }
                if ( !$exists ) {
                    $matches[] = [
                        'title' => $title,
                        'matchedAlias' => $matchedAlias,
                        'source' => $matchSource
                    ];
                    if ( count( $matches ) >= $limit ) {
                        break;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * 简单搜索 - 只返回标题列表（用于其他地方）
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

            // JSON 文件更新时清除 JSON 缓存
            if ( $titleText === self::JSON_PAGE ) {
                self::clearJsonCache();
            }

            // 其他页面保存时清除 Cargo 缓存 (因为可能添加/修改了 Tag 模板)
            self::clearCargoCache();
        } catch ( \Throwable $e ) {}
        return true;
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
     * 全文搜索 - 使用 MediaWiki 原生数据库全文搜索
     */
    public function searchText( $term ) {
        $services = MediaWikiServices::getInstance();

        // 直接实例化 SearchMySQL，避免通过工厂导致递归
        if ( method_exists( $services, 'getConnectionProvider' ) ) {
            $dbProvider = $services->getConnectionProvider();
        } else {
            $dbProvider = $services->getDBLoadBalancerFactory();
        }
        $config = $services->getSearchEngineConfig();

        $dbSearch = new \SearchMySQL( $dbProvider, $config );
        $dbSearch->setLimitOffset( $this->limit, $this->offset );
        $dbSearch->setNamespaces( $this->namespaces );

        return $dbSearch->searchText( $term );
    }

    /**
     * 标题搜索
     */
    public function searchTitle( $term ) {
        $services = MediaWikiServices::getInstance();

        if ( method_exists( $services, 'getConnectionProvider' ) ) {
            $dbProvider = $services->getConnectionProvider();
        } else {
            $dbProvider = $services->getDBLoadBalancerFactory();
        }
        $config = $services->getSearchEngineConfig();

        $dbSearch = new \SearchMySQL( $dbProvider, $config );
        $dbSearch->setLimitOffset( $this->limit, $this->offset );
        $dbSearch->setNamespaces( $this->namespaces );

        return $dbSearch->searchTitle( $term );
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

        if ( !empty( $matches ) ) {
            $html = '<div class="riz-alias-results" style="margin-bottom: 1.5em; padding: 1em; background: rgba(51, 102, 204, 0.1); border-left: 4px solid #3366cc; border-radius: 4px;">';
            $html .= '<strong style="display: block; margin-bottom: 0.5em;">别名匹配结果：</strong>';
            $html .= '<ul style="margin: 0; padding-left: 1.5em;">';

            foreach ( $matches as $match ) {
                $titleText = $match['title'];
                $matchedAlias = $match['matchedAlias'];
                $source = $match['source'];

                $titleObj = RizSearchAlias::makeTitle( $titleText );
                if ( $titleObj && $titleObj->exists() ) {
                    $url = $titleObj->getLocalURL();

                    // 如果是通过别名匹配的，显示匹配的别名
                    if ( $source === 'alias' && $matchedAlias !== $titleText ) {
                        $html .= '<li><a href="' . htmlspecialchars( $url ) . '">' . htmlspecialchars( $titleText ) . '</a>';
                        $html .= ' <span style="color: #888; font-size: 0.9em;">(匹配: ' . htmlspecialchars( $matchedAlias ) . ')</span></li>';
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

            $cargoData = RizSearchAlias::getCargoData();
            $jsonData = RizSearchAlias::getJsonData();

            $result->addValue( null, 'cargo_count', count( $cargoData ) );
            $result->addValue( null, 'json_count', count( $jsonData ) );
            $result->addValue( null, 'total_count', count( $cargoData ) + count( $jsonData ) );

            // 调试 Cargo 表信息
            $services = MediaWikiServices::getInstance();
            if ( method_exists( $services, 'getConnectionProvider' ) ) {
                $dbr = $services->getConnectionProvider()->getReplicaDatabase();
            } elseif ( method_exists( $services, 'getDBLoadBalancer' ) ) {
                $dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
            } else {
                $dbr = \wfGetDB( DB_REPLICA );
            }
            $cargoTableName = 'cargo__pagetags';
            $result->addValue( null, 'cargo_table_name', $cargoTableName );
            $result->addValue( null, 'cargo_table_exists', $dbr->tableExists( $cargoTableName ) );

            // 直接查询测试
            try {
                $testRes = $dbr->select( $cargoTableName, '*', [], __METHOD__, [ 'LIMIT' => 5 ] );
                $testRows = [];
                foreach ( $testRes as $row ) {
                    $testRows[] = (array)$row;
                }
                $result->addValue( null, 'cargo_direct_query', $testRows );
            } catch ( \Throwable $e ) {
                $result->addValue( null, 'cargo_query_error', $e->getMessage() );
            }

            if ( count( $cargoData ) > 0 ) {
                $result->addValue( null, 'cargo_sample', array_slice( $cargoData, 0, 3 ) );
            }
            if ( count( $jsonData ) > 0 ) {
                $result->addValue( null, 'json_sample', array_slice( $jsonData, 0, 3 ) );
            }

            if ( $search ) {
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
