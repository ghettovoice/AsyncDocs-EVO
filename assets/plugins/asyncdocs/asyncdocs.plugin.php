<?php

/**
 * AsyncDocs Plugin
 *
 * Asynchronously loads the documents.
 *
 * @author Vladimir Vershinin
 * @version 1.0.0
 * @package AsyncDocs
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License
 * @copyright (c) 2013, Vladimir Vershinin
 */
/**
 * System event:
 *   OnWebPageInit - load & parse documents
 *   OnCacheUpdate - clear cache of documents
 *
 * Configuration:
  &Configuration:=ajaxPageLoader;; &contentSelector=XPath to the content DOM element;string; &fields=Document fields(list of doc fields to add to the response separated by "||");textarea;pagetitle||longtitle||description &chunks=Additional chunks(list of chunks to add to the response separated by "||") <b>not tested, currently disabled</b>;textarea; &snippets=Additional snippets(list of snippets to add to the response separated by "||") <b>not realized in this version</b>;textarea; &excludeChunks=Exclude chunks(list of chunks to exclude from document content separated by "||");textarea &excludeSnippets=Exclude snippets(list of snippets to exclude from document content separated by "||") <b>not realized in this version</b>;textarea;
 */
/* @var $modx DocumentParser */
/* @var $e SystemEvent */
include_once $modx->config['base_path'] . 'assets/plugins/asyncdocs/asyncdocs.class.php';

if (!class_exists('AsyncDocs'))
    $modx->logEvent(0, 3, 'Class AsyncDocs not found in ' . $modx->config['base_path'] . 'assets/plugins/asyncdocs/asyncdocs.class.php');

$e = & $modx->Event;

$params['fields']          = isset($fields) ? $fields : '';
$params['chunks']          = isset($chunks) ? $chunks : '';
$params['snippets']        = isset($snippets) ? $snippets : '';
$params['excludeChunks']   = isset($excludeChunks) ? $excludeChunks : '';
$params['excludeSnippets'] = isset($excludeSnippets) ? $excludeSnippets : '';

$asyncDocs = new AsyncDocs($modx, $params);

switch ($e->name) {
    case 'OnWebPageInit':
        $asyncDocs->run();
        break;
    case 'OnCacheUpdate':
        $asyncDocs->clearCache();
        break;
    default:
        break;
}