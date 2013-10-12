<?php

/**
 * AsyncDocs Plugin
 *
 * Asynchronously loads the documents.
 *
 * @author    Vladimir Vershinin
 * @version   1.1.2
 * @package   AsyncDocs
 * @license   http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License
 * @copyright (c) 2013, Vladimir Vershinin
 */
/**
 * System event:
 *   OnWebPageInit - load & parse documents
 *   OnCacheUpdate - clear cache of documents
 *   OnWebPagePrerender - minify document output
 *   OnPageNotFound - invoke onPageNotFound plugins for custom urls
 *
 *
 * Configuration:
&Configuration:=AsyncDocs;; &contentSelector=XPath to the content DOM element;string; &fields=Document fields;textarea;pagetitle||longtitle||description &chunks=List of additional chunks separated by "||";textarea; &excludeChunks=Exclude chunks list separated by "||";textarea; &setHTTPCode=Set HTTP response codes;list;false,true;true &urlScheme=Document URL scheme;list;,full; &contentOnly=Return only content field of document;list;false,true;false &cache=Use cache;list;false,true;true &minify=Minify output;list;false,true;true
 */
/* @var $modx DocumentParser */
/* @var $e SystemEvent */
include_once $modx->config['base_path'] . 'assets/plugins/asyncdocs/asyncdocs.class.php';

if (!class_exists('AsyncDocs'))
    $modx->logEvent(0, 3, 'Class AsyncDocs not found in ' . $modx->config['base_path'] . 'assets/plugins/asyncdocs/asyncdocs.class.php', 'AsyncDocs plugin');

$e = & $modx->event;

$params['fields']          = isset($fields) ? $fields : '';
$params['chunks']          = isset($chunks) ? $chunks : '';
$params['snippets']        = isset($snippets) ? $snippets : '';
$params['excludeChunks']   = isset($excludeChunks) ? $excludeChunks : '';
$params['excludeSnippets'] = isset($excludeSnippets) ? $excludeSnippets : '';
$params['urlScheme']       = isset($urlScheme) ? $urlScheme : '';
$params['setHTTPCode']     = isset($setHTTPCode) ? filter_var($setHTTPCode, FILTER_VALIDATE_BOOLEAN) : true;
$params['contentOnly']     = isset($contentOnly) ? filter_var($contentOnly, FILTER_VALIDATE_BOOLEAN) : false;
$params['cache']           = isset($cache) ? filter_var($cache, FILTER_VALIDATE_BOOLEAN) : true;
$params['minify']          = isset($minify) ? filter_var($minify, FILTER_VALIDATE_BOOLEAN) : true;

$asyncDocs = new AsyncDocs($modx, $params);

switch ($e->name) {
    case 'OnWebPageInit':
        $asyncDocs->run();
        break;
    case 'OnCacheUpdate':
        $asyncDocs->clearCache();
        break;
    case 'OnWebPagePrerender':
        $modx->documentOutput = $asyncDocs->processOutput($modx->documentOutput);
        break;
    case 'OnPageNotFound': // for catch control before firing OnWebPageInit
        $asyncDocs->onPageNotFound();
        break;
    default:
        break;
}
