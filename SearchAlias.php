<?php
/**
 * RizWiki 搜索别名增强 (Cargo + JSON 双数据源版本)
 * 1. 从 Cargo 表读取模板添加的 tag
 * 2. 从 JSON 文件读取原有别名（用于关键词搜索）
 * 3. 搜索优先级：原名匹配 > 别名匹配 > tag匹配
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
     * 解析搜索词
     * tags 现在通过 URL 参数 tags[] 传递，不再从搜索词解析
     */
    public static function parseSearchTerm( string $term ): array {
        return [
            'tags' => [],
            'keyword' => trim( $term )
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

        // 转为小写+标准化比较
        $pageTagsLower = array_map( function( $t ) {
            return mb_strtolower( self::normalizeWidth( $t ) );
        }, $pageTags );

        foreach ( $requiredTags as $reqTag ) {
            $reqTagLower = mb_strtolower( self::normalizeWidth( trim( $reqTag ) ) );
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
     * 全角半角统一化（用于模糊匹配）
     */
    private static function normalizeWidth( string $str ): string {
        // 全角字母数字符号 → 半角
        $str = mb_convert_kana( $str, 'a', 'UTF-8' );
        // 全角空格 → 半角空格
        $str = str_replace( "\u{3000}", ' ', $str );
        return $str;
    }

    /**
     * 模糊匹配（忽略全角半角差异）
     */
    private static function fuzzyContains( string $haystack, string $needle ): bool {
        $haystack = mb_strtolower( self::normalizeWidth( $haystack ) );
        $needle = mb_strtolower( self::normalizeWidth( $needle ) );
        return mb_strpos( $haystack, $needle ) !== false;
    }

    /**
     * 核心搜索方法 - 返回匹配结果（按优先级排序）
     * 优先级：原名匹配 > 别名匹配 > tag匹配
     * @param array|null $namespaces 限制命名空间，null 表示不限制
     */
    public static function searchMatches( string $term, int $limit = 10, ?array $namespaces = null ): array {
        $term = trim( $term );

        // 从 URL 参数读取 tags
        $requiredTags = [];
        if ( isset( $_GET['tags'] ) && is_array( $_GET['tags'] ) ) {
            $requiredTags = array_map( 'trim', $_GET['tags'] );
        }

        // 没有关键词也没有 tag，直接返回空
        if ( mb_strlen( $term ) < 1 && empty( $requiredTags ) ) {
            return [];
        }

        $keyword = mb_strtolower( self::normalizeWidth( $term ) );

        // 获取数据
        $tagData = self::getTagData();
        $aliasData = self::getAliasData();

        // 收集所有可能的页面名
        $allPages = array_unique( array_merge(
            array_keys( $tagData ),
            array_keys( $aliasData )
        ) );

        // 命名空间过滤
        if ( $namespaces !== null && !empty( $namespaces ) ) {
            $allPages = array_filter( $allPages, function( $page ) use ( $namespaces ) {
                $titleObj = self::makeTitle( $page );
                return $titleObj && in_array( $titleObj->getNamespace(), $namespaces );
            });
        }

        // 如果有tag过滤条件，先过滤
        if ( !empty( $requiredTags ) ) {
            $allPages = array_filter( $allPages, function( $page ) use ( $requiredTags, $tagData ) {
                return self::pageHasAllTags( $page, $requiredTags, $tagData );
            });
        }

        // 四个优先级的结果数组
        $titleMatches = [];      // 原名匹配
        $aliasMatches = [];      // 别名匹配
        $tagKeywordMatches = []; // tag关键词匹配（用户直接搜tag内容）
        $tagOnlyMatches = [];    // 仅tag过滤匹配（#tag#语法，没有关键词时）

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
            if ( self::fuzzyContains( $pageName, $keyword ) ) {
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
                    if ( self::fuzzyContains( $alias, $keyword ) ) {
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

            // 3. Tag关键词匹配（最低优先级，直接搜tag内容）
            if ( !$matched && !empty( $pageTags ) ) {
                foreach ( $pageTags as $tag ) {
                    if ( self::fuzzyContains( $tag, $keyword ) ) {
                        $tagKeywordMatches[] = [
                            'title' => $pageName,
                            'matchedAlias' => $tag,
                            'source' => 'tag'
                        ];
                        $matched = true;
                        break;
                    }
                }
            }
        }

        // 合并结果：原名匹配 > 别名匹配 > tag关键词匹配 > tag过滤匹配
        $results = array_merge( $titleMatches, $aliasMatches, $tagKeywordMatches, $tagOnlyMatches );

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
    public static function searchMatchTitles( string $term, int $limit = 10, ?array $namespaces = null ): array {
        $matches = self::searchMatches( $term, $limit, $namespaces );
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
            $matches = self::searchMatchTitles( $search, $limit, is_array( $namespaces ) ? $namespaces : null );
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

    /**
     * 获取 Tag 选择器 CSS
     */
    public static function getTagSelectorCSS(): string {
        return <<<'CSS'
.riz-tag-selector-wrapper {
    position: relative;
    margin: 1em 0;
    max-width: 100%;
}

.riz-selected-tags-container {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5em;
    margin-bottom: 0.5em;
}

.riz-tag-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4em;
    padding: 0.4em 0.7em;
    background: #3366cc;
    color: white;
    border-radius: 16px;
    font-size: 0.9em;
    cursor: pointer;
    transition: background 0.2s;
}

.riz-tag-pill:hover {
    background: #2855aa;
}

.riz-tag-remove {
    font-weight: bold;
    font-size: 1.2em;
    line-height: 1;
    opacity: 0.8;
}

.riz-tag-remove:hover {
    opacity: 1;
}

.riz-tag-input-row {
    display: flex;
    gap: 0.5em;
    position: relative;
}

#riz-tag-input {
    flex: 1;
    padding: 0.5em 0.8em;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 0.9em;
    box-sizing: border-box;
    background: white;
    color: #000;
}

#riz-tag-input:focus {
    outline: none;
    border-color: #3366cc;
    box-shadow: 0 0 0 2px rgba(51, 102, 204, 0.1);
}

.riz-tag-add-btn {
    padding: 0.5em 1em;
    background: #3366cc;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 0.9em;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.2s;
}

.riz-tag-add-btn:hover {
    background: #2855aa;
}

.riz-tag-add-btn:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.riz-tag-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 250px;
    overflow-y: auto;
    background: white;
    border: 1px solid #ddd;
    border-top: none;
    border-radius: 0 0 4px 4px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    z-index: 1000;
    display: none;
    margin-top: 0.5em;
}

.riz-tag-dropdown.show {
    display: block;
}

.riz-tag-dropdown-item {
    padding: 0.6em 0.8em;
    cursor: pointer;
    transition: background 0.2s;
    border-bottom: 1px solid #f0f0f0;
    color: #000;
}

.riz-tag-dropdown-item:hover {
    background: #f5f5f5;
}

.riz-tag-dropdown-item:last-child {
    border-bottom: none;
}

.riz-tag-dropdown-empty {
    padding: 0.8em;
    text-align: center;
    color: #999;
    font-size: 0.9em;
}

/* 暗色主题适配 */
@media (prefers-color-scheme: dark) {
    #riz-tag-input {
        background: #1a1a1a;
        color: #fff;
        border-color: #444;
    }

    .riz-tag-dropdown {
        background: #1a1a1a;
        border-color: #444;
    }

    .riz-tag-dropdown-item {
        color: #fff;
        border-bottom-color: #333;
    }

    .riz-tag-dropdown-item:hover {
        background: #2a2a2a;
    }

    .riz-tag-dropdown-empty {
        color: #888;
    }
}

/* Citizen 皮肤暗色模式 */
html.skin-theme-clientpref-night #riz-tag-input,
html.skin-theme-clientpref-night .riz-tag-dropdown {
    background: #1a1a1a;
    color: #fff;
    border-color: #444;
}

html.skin-theme-clientpref-night .riz-tag-dropdown-item {
    color: #fff;
    border-bottom-color: #333;
}

html.skin-theme-clientpref-night .riz-tag-dropdown-item:hover {
    background: #2a2a2a;
}

html.skin-theme-clientpref-night .riz-tag-dropdown-empty {
    color: #888;
}
CSS;
    }

    /**
     * 获取 Tag 选择器 JavaScript
     */
    public static function getTagSelectorJS(): string {
        return <<<'JS'
(function() {
    'use strict';

    let debounceTimer = null;
    let selectedTags = [];
    let allTagsCache = window.rizAllTags || []; // 直接从 window 读取

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        // 只在搜索结果页显示 tag 选择器
        const searchFormWrapper = document.querySelector('.mw-search-form-wrapper');
        if (!searchFormWrapper) {
            return; // 不是搜索结果页，不显示
        }

        // 查找搜索表单和输入框
        const searchForm = searchFormWrapper.querySelector('form#search');
        const searchInput = searchForm ? searchForm.querySelector('input[name="search"]') : null;

        if (!searchInput || !searchForm) {
            return;
        }

        // 从 URL 参数读取已选中的 tags
        const urlParams = new URLSearchParams(window.location.search);
        const tagsParam = urlParams.getAll('tags[]');
        selectedTags = tagsParam.filter(function(t) { return t.trim(); });

        // 创建 tag 选择器 UI
        createTagSelector(searchForm, searchInput);

        // 渲染已选中的 tags
        renderSelectedTags();

        // 拦截表单提交
        searchForm.addEventListener('submit', handleFormSubmit);
    }

    /**
     * 创建 tag 选择器 UI
     */
    function createTagSelector(searchForm, searchInput) {
        const wrapper = document.createElement('div');
        wrapper.className = 'riz-tag-selector-wrapper';

        wrapper.innerHTML =
            '<div class="riz-selected-tags-container"></div>' +
            '<div class="riz-tag-input-wrapper">' +
                '<div class="riz-tag-input-row">' +
                    '<input type="text" id="riz-tag-input" placeholder="输入标签名..." autocomplete="off">' +
                    '<button type="button" id="riz-tag-add-btn" class="riz-tag-add-btn" disabled>添加</button>' +
                '</div>' +
                '<div class="riz-tag-dropdown"></div>' +
            '</div>';

        // 插入到 #mw-search-top-table 下方
        const topTable = document.getElementById('mw-search-top-table');
        if (topTable && topTable.parentNode) {
            topTable.parentNode.insertBefore(wrapper, topTable.nextSibling);
        } else {
            // 备用：插入到表单内部顶部
            searchForm.insertBefore(wrapper, searchForm.firstChild);
        }

        // 绑定事件
        const tagInput = document.getElementById('riz-tag-input');
        const addBtn = document.getElementById('riz-tag-add-btn');
        const dropdown = wrapper.querySelector('.riz-tag-dropdown');

        tagInput.addEventListener('input', function() {
            // 启用/禁用添加按钮
            addBtn.disabled = !this.value.trim();

            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function() {
                searchTags(tagInput.value, dropdown);
            }, 300);
        });

        // 点击添加按钮
        addBtn.addEventListener('click', function() {
            const value = tagInput.value.trim();
            if (value) {
                addTag(value);
                tagInput.value = '';
                addBtn.disabled = true;
                dropdown.classList.remove('show');
            }
        });

        tagInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && this.value.trim()) {
                e.preventDefault();
                addTag(this.value.trim());
                this.value = '';
                addBtn.disabled = true;
                dropdown.classList.remove('show');
            }
        });

        // 点击外部关闭下拉
        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });
    }

    /**
     * 搜索匹配的 tags（使用预加载的数据）
     */
    function searchTags(keyword, dropdown) {
        if (!keyword.trim()) {
            dropdown.classList.remove('show');
            return;
        }

        // 过滤匹配的 tags
        const matched = allTagsCache.filter(function(tag) {
            if (selectedTags.indexOf(tag) !== -1) return false;
            return tag.toLowerCase().indexOf(keyword.toLowerCase()) !== -1;
        });

        renderDropdown(dropdown, matched, keyword);
    }

    /**
     * 从 API 数据中提取 tags
     */
    function extractTags(data) {
        const tagSet = new Set();
        if (data.rizsearchdebug && data.rizsearchdebug.tag_sample) {
            data.rizsearchdebug.tag_sample.forEach(function(item) {
                if (item.tags) {
                    item.tags.forEach(function(tag) {
                        tagSet.add(tag);
                    });
                }
            });
        }
        return Array.from(tagSet).sort();
    }

    /**
     * 渲染下拉列表
     */
    function renderDropdown(dropdown, tags, keyword) {
        dropdown.innerHTML = '';

        if (tags.length === 0) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'riz-tag-dropdown-empty';
            emptyDiv.textContent = '无匹配标签';
            dropdown.appendChild(emptyDiv);
            dropdown.classList.add('show');
            return;
        }

        tags.slice(0, 10).forEach(function(tag) {
            const item = document.createElement('div');
            item.className = 'riz-tag-dropdown-item';
            item.textContent = tag;
            item.addEventListener('click', function() {
                addTag(tag);
                const tagInput = document.getElementById('riz-tag-input');
                const addBtn = document.getElementById('riz-tag-add-btn');
                tagInput.value = '';
                addBtn.disabled = true;
                dropdown.classList.remove('show');
            });
            dropdown.appendChild(item);
        });

        dropdown.classList.add('show');
    }

    /**
     * 添加 tag
     */
    function addTag(tag) {
        if (selectedTags.indexOf(tag) !== -1) {
            return;
        }

        selectedTags.push(tag);
        renderSelectedTags();
    }

    /**
     * 删除 tag
     */
    function removeTag(tag) {
        const index = selectedTags.indexOf(tag);
        if (index !== -1) {
            selectedTags.splice(index, 1);
            renderSelectedTags();
        }
    }

    /**
     * 渲染已选中的 tags
     */
    function renderSelectedTags() {
        const container = document.querySelector('.riz-selected-tags-container');
        if (!container) return;

        container.innerHTML = '';

        selectedTags.forEach(function(tag) {
            const pill = document.createElement('span');
            pill.className = 'riz-tag-pill';
            pill.innerHTML =
                '<span class="riz-tag-text">' + escapeHtml(tag) + '</span>' +
                '<span class="riz-tag-remove">×</span>';

            pill.querySelector('.riz-tag-remove').addEventListener('click', function() {
                removeTag(tag);
            });

            container.appendChild(pill);
        });
    }

    /**
     * 处理表单提交
     */
    function handleFormSubmit(e) {
        e.preventDefault();

        var form = e.target;
        var searchInput = form.querySelector('input[name="search"]');

        // 移除旧的 tags[] 参数
        var oldTagInputs = form.querySelectorAll('input[name="tags[]"]');
        oldTagInputs.forEach(function(input) {
            input.remove();
        });

        // 添加新的 tags[] 参数
        selectedTags.forEach(function(tag) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'tags[]';
            input.value = tag;
            form.appendChild(input);
        });

        // 提交表单
        form.submit();
    }

    /**
     * HTML 转义
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
JS;
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
     * 获取原始搜索词（绕过 MediaWiki 的 # 截断）
     */
    private static function getRawSearchTerm( string $fallback ): string {
        $raw = $_GET['search'] ?? $_REQUEST['search'] ?? '';
        return trim( $raw ) !== '' ? trim( $raw ) : $fallback;
    }

    /**
     * 自动补全搜索 (REST API / 搜索框联想)
     */
    protected function completionSearchBackend( $search ) {
        $results = [];
        $search = self::getRawSearchTerm( $search );
        $cleanSearch = self::stripNamespacePrefix( $search );

        // 1. 别名匹配 (Cargo + JSON)
        try {
            $aliasMatches = RizSearchAlias::searchMatchTitles( $cleanSearch, $this->limit, $this->namespaces );
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
            $searchLower = mb_strtolower( trim( $cleanSearch ) );

            if ( mb_strlen( $searchLower ) >= 1 ) {
                $like = $db->buildLike( $db->anyString(), $searchLower, $db->anyString() );

                $nsCond = $this->namespaces ? [ 'page_namespace' => $this->namespaces ] : [ 'page_namespace' => NS_MAIN ];

                $res = $db->select(
                    'page',
                    [ 'page_namespace', 'page_title' ],
                    array_merge( $nsCond, [
                        'LOWER(page_title) ' . $like
                    ] ),
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

        // 3. 页面内容搜索 (searchindex LIKE)
        try {
            if ( count( $results ) < $this->limit ) {
                $db = $this->getDbConnection();
                $searchLower = mb_strtolower( trim( $cleanSearch ) );

                if ( mb_strlen( $searchLower ) >= 2 ) {
                    $like = $db->buildLike( $db->anyString(), $searchLower, $db->anyString() );

                    $nsCond = $this->namespaces ? [ 'page_namespace' => $this->namespaces ] : [ 'page_namespace' => NS_MAIN ];

                    $res = $db->select(
                        [ 'searchindex', 'page' ],
                        [ 'page_namespace', 'page_title' ],
                        array_merge( $nsCond, [
                            'LOWER(CONVERT(si_text USING utf8mb4)) ' . $like
                        ] ),
                        __METHOD__,
                        [ 'LIMIT' => $this->limit - count( $results ) ],
                        [ 'page' => [ 'JOIN', 'page_id = si_page' ] ]
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
        $search = self::getRawSearchTerm( $search );

        // 1. 别名匹配
        try {
            $aliasMatches = RizSearchAlias::searchMatchTitles( $search, $this->limit, $this->namespaces );
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

            $nsCond = $this->namespaces ? [ 'page_namespace' => $this->namespaces ] : [ 'page_namespace' => NS_MAIN ];

            $res = $db->select(
                'page',
                [ 'page_namespace', 'page_title' ],
                array_merge( $nsCond, [
                    'page_title ' . $db->buildLike( $searchDb, $db->anyString() )
                ] ),
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
     * 从搜索词中剥离命名空间前缀，返回纯关键词
     */
    private static function stripNamespacePrefix( string $term ): string {
        $titleObj = RizSearchAlias::makeTitle( $term );
        if ( $titleObj && $titleObj->getNamespace() !== NS_MAIN ) {
            return $titleObj->getText();
        }
        return $term;
    }

    /**
     * 全文搜索 - 别名/tag/标题/内容匹配，PHP 层分页
     */
    public function searchText( $term ) {
        $allResults = [];

        // 使用原始搜索词，绕过 MediaWiki 的 # 截断
        $term = self::getRawSearchTerm( $term );

        // 从 URL 参数读取 tags
        $requiredTags = [];
        if ( isset( $_GET['tags'] ) && is_array( $_GET['tags'] ) ) {
            $requiredTags = array_map( 'trim', $_GET['tags'] );
        }

        // 没有关键词也没有 tag，直接返回空
        if ( trim( $term ) === '' && empty( $requiredTags ) ) {
            return new RizSearchResultSet( [] );
        }

        // 解析命名空间前缀（如 "template:songinfo" → "songinfo" + 锁定 Template 命名空间）
        $cleanTerm = $term;
        $namespaces = $this->namespaces;
        if ( trim( $term ) !== '' ) {
            $titleObj = RizSearchAlias::makeTitle( $term );
            if ( $titleObj && $titleObj->getNamespace() !== NS_MAIN ) {
                $cleanTerm = $titleObj->getText();
                $namespaces = [ $titleObj->getNamespace() ];
            }
        }

        $keyword = mb_strtolower( $cleanTerm );

        // 1. 别名和tag匹配
        try {
            $aliasMatches = RizSearchAlias::searchMatches( $cleanTerm, 200, $namespaces );
            foreach ( $aliasMatches as $match ) {
                $titleObj = RizSearchAlias::makeTitle( $match['title'] );
                if ( $titleObj && $titleObj->exists() ) {
                    $allResults[$match['title']] = [
                        'title' => $titleObj,
                        'source' => $match['source'],
                        'matchedAlias' => $match['matchedAlias']
                    ];
                }
            }
        } catch ( \Throwable $e ) {}

        // 2. 数据库标题搜索
        try {
            if ( $keyword !== '' && mb_strlen( $keyword ) >= 1 ) {
                $db = $this->getDbConnection();
                $like = $db->buildLike( $db->anyString(), mb_strtolower( $keyword ), $db->anyString() );
                $nsCond = $namespaces ? [ 'page_namespace' => $namespaces ] : [];

                $res = $db->select(
                    'page',
                    [ 'page_namespace', 'page_title' ],
                    array_merge( $nsCond, [ 'LOWER(page_title) ' . $like ] ),
                    __METHOD__,
                    [ 'LIMIT' => 500 ]
                );

                $services = MediaWikiServices::getInstance();
                foreach ( $res as $row ) {
                    if ( method_exists( $services, 'getTitleFactory' ) ) {
                        $title = $services->getTitleFactory()->makeTitle( $row->page_namespace, $row->page_title );
                    } else {
                        $title = \Title::makeTitle( $row->page_namespace, $row->page_title );
                    }
                    if ( $title ) {
                        $titleText = $title->getPrefixedText();
                        if ( !isset( $allResults[$titleText] ) ) {
                            $allResults[$titleText] = [
                                'title' => $title,
                                'source' => 'title',
                                'matchedAlias' => $titleText
                            ];
                        }
                    }
                }
            }
        } catch ( \Throwable $e ) {}

        // 3. searchindex 内容搜索
        try {
            if ( $keyword !== '' && mb_strlen( $keyword ) >= 2 ) {
                $db = $this->getDbConnection();
                $like = $db->buildLike( $db->anyString(), mb_strtolower( $keyword ), $db->anyString() );
                $nsCond = $namespaces ? [ 'page_namespace' => $namespaces ] : [];

                $res = $db->select(
                    [ 'searchindex', 'page' ],
                    [ 'page_namespace', 'page_title' ],
                    array_merge( $nsCond, [
                        'LOWER(CONVERT(si_text USING utf8mb4)) ' . $like
                    ] ),
                    __METHOD__,
                    [ 'LIMIT' => 500 ],
                    [ 'page' => [ 'JOIN', 'page_id = si_page' ] ]
                );

                $services = MediaWikiServices::getInstance();
                foreach ( $res as $row ) {
                    if ( method_exists( $services, 'getTitleFactory' ) ) {
                        $title = $services->getTitleFactory()->makeTitle( $row->page_namespace, $row->page_title );
                    } else {
                        $title = \Title::makeTitle( $row->page_namespace, $row->page_title );
                    }
                    if ( $title ) {
                        $titleText = $title->getPrefixedText();
                        if ( !isset( $allResults[$titleText] ) ) {
                            $allResults[$titleText] = [
                                'title' => $title,
                                'source' => 'content',
                                'matchedAlias' => $keyword
                            ];
                        }
                    }
                }
            }
        } catch ( \Throwable $e ) {}

        // PHP 层分页：按 offset 和 limit 切片
        $total = count( $allResults );
        $paged = array_slice( $allResults, $this->offset, $this->limit, true );
        $hasMore = ( $this->offset + $this->limit ) < $total;

        return new RizSearchResultSet( $paged, null, $hasMore );
    }

    /**
     * 写入搜索索引 - 委托给原生 MySQL 引擎
     */
    public function update( $id, $title, $text ) {
        $engine = $this->getFallbackEngine();
        if ( $engine ) {
            $engine->update( $id, $title, $text );
        }
    }

    public function updateTitle( $id, $title ) {
        $engine = $this->getFallbackEngine();
        if ( $engine ) {
            $engine->updateTitle( $id, $title );
        }
    }

    public function delete( $id, $title ) {
        $engine = $this->getFallbackEngine();
        if ( $engine ) {
            $engine->delete( $id, $title );
        }
    }

    /**
     * 标题搜索 - 统一由 searchText 处理，避免重复
     */
    public function searchTitle( $term ) {
        return new RizSearchResultSet( [] );
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
    private $hasMore = false;

    public function __construct( array $aliasResults, $nativeResults = null, bool $hasMore = false ) {
        $this->aliasResults = $aliasResults;
        $this->nativeResults = $nativeResults;
        $this->hasMore = $hasMore;

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

    public function getTotalHits() {
        if ( $this->hasMore ) {
            // 返回一个大于 offset+limit 的数，让 MediaWiki 显示翻页
            return $this->numRows() + 1000;
        }
        return $this->numRows();
    }

    public function hasMoreResults() {
        return $this->hasMore;
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
    protected $byteSize = 0;
    protected $wordCount = 0;
    protected $timestamp = '';

    public function __construct( $title, $source = 'title', $matchedAlias = '' ) {
        $this->mTitle = $title;
        $this->source = $source;
        $this->matchedAlias = $matchedAlias;

        // 从页面读取实际数据
        if ( $title && $title->exists() ) {
            try {
                $services = MediaWikiServices::getInstance();
                if ( method_exists( $services, 'getWikiPageFactory' ) ) {
                    $wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
                } else {
                    $wikiPage = \WikiPage::factory( $title );
                }
                $content = $wikiPage->getContent();
                if ( $content ) {
                    $text = $content->serialize();
                    $this->byteSize = strlen( $text );
                    $this->wordCount = mb_strlen( strip_tags( $text ) );
                }
                $rev = $wikiPage->getRevisionRecord();
                if ( $rev ) {
                    $this->timestamp = $rev->getTimestamp();
                }
            } catch ( \Throwable $e ) {}
        }
    }

    public function getTitle() {
        return $this->mTitle;
    }

    public function isMissingRevision() {
        return false;
    }

    public function getByteSize() {
        return $this->byteSize;
    }

    public function getWordCount() {
        return $this->wordCount;
    }

    public function getTimestamp() {
        return $this->timestamp;
    }

    public function getTextSnippet( $terms = [] ) {
        if ( $this->source === 'alias' && $this->matchedAlias ) {
            return '别名匹配: ' . htmlspecialchars( $this->matchedAlias );
        } elseif ( $this->source === 'tag' ) {
            return 'Tag匹配: ' . htmlspecialchars( $this->matchedAlias );
        } elseif ( $this->source === 'content' ) {
            return '内容匹配: ' . htmlspecialchars( $this->matchedAlias );
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
// 在搜索框下方注入 Tag 选择器
// ============================================
$wgHooks['BeforePageDisplay'][] = function( $output, $skin ) {
    // 只在搜索结果页注入
    if ( !$output->getTitle() || $output->getTitle()->isSpecial( 'Search' ) === false ) {
        return true;
    }

    // 获取所有 tags 并输出到 JS 变量
    $tagData = RizSearchAlias::getTagData();
    $allTags = [];
    foreach ( $tagData as $pageTags ) {
        $allTags = array_merge( $allTags, $pageTags );
    }
    $allTags = array_values( array_unique( $allTags ) );
    sort( $allTags );

    // 输出 tags 到 JS 变量
    $output->addInlineScript( 'window.rizAllTags = ' . json_encode( $allTags ) . ';' );

    // 注入 CSS
    $output->addInlineStyle( RizSearchAlias::getTagSelectorCSS() );

    // 注入 JS
    $output->addInlineScript( RizSearchAlias::getTagSelectorJS() );

    return true;
};

// ============================================
// 搜索结果页显示别名匹配（不直接跳转）
// ============================================
$wgHooks['SpecialSearchResultsPrepend'][] = function ( $specialSearch, $output, $term ) {
    try {
        $matches = RizSearchAlias::searchMatches( $term, 10 );

        // 从 URL 参数获取 tags
        $selectedTags = [];
        if ( isset( $_GET['tags'] ) && is_array( $_GET['tags'] ) ) {
            $selectedTags = array_map( 'trim', $_GET['tags'] );
        }
        $hasTags = !empty( $selectedTags );

        if ( !empty( $matches ) ) {
            $html = '<div class="riz-alias-results" style="margin-bottom: 1.5em; padding: 1em; background: rgba(51, 102, 204, 0.1); border-left: 4px solid #3366cc; border-radius: 4px;">';

            // 根据搜索类型显示不同的标题
            if ( $hasTags && trim( $term ) === '' ) {
                $html .= '<strong style="display: block; margin-bottom: 0.5em;">Tag 筛选结果（' . htmlspecialchars( implode( ' + ', $selectedTags ) ) . '）：</strong>';
            } elseif ( $hasTags ) {
                $html .= '<strong style="display: block; margin-bottom: 0.5em;">筛选结果（Tag: ' . htmlspecialchars( implode( ' + ', $selectedTags ) ) . '）：</strong>';
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
