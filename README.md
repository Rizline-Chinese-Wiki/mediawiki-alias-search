# mediawiki-alias-search
一个轻量化的给页面增加别名以优化mediawiki搜索的方案
使用方法：
1. 将SearchAlias.php与rest.php放入mediawiki安装根目录
2. 在LocalSettings.php中添加<code>require_once "$IP/SearchAlias.php";</code>
3. 创建json格式文件或使用tag模板以达到添加别名的效果(均为可选，可同时启用)
4. json示例: https://rizwiki.cn/url/18o
5. tag模板示例: https://rizwiki.cn/url/190
