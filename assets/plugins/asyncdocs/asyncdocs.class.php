<?php

/**
 * AsyncDocs
 *
 * Main class of AsyncDocs plugin.
 *
 * @author Vladimir Vershinin
 * @version 1.0.0
 * @package AsyncDocs
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License
 * @copyright (c) 2013, Vladimir Vershinin
 */
class AsyncDocs {

    const PLG_NAME                 = 'AsyncDocs';
    const VERSION                  = '1.0.0-beta';
    const DOC_CACHE_SUFF           = 'doc_';
    // statuses
    const STATUS_OK                = 200;
    const STATUS_MOVED_PERMANENTLY = 301;
    const STATUS_UNAUTHORIZED      = 401;
    const STATUS_NOT_FOUND         = 404;

    /** @var DocumentParser $modx */
    public $modx;

    /** @var array $config Plugin cfg */
    protected $config = array();

    /** @var array $response Response array */
    protected $response = array();

    /** @var array $chunks Array of additional chunks */
    protected $chunks = array();

    /** @var array $snippets Array of additional snippets */
    protected $snippets = array();

    /** @var int $status Response status */
    protected $status;

    public function __construct(DocumentParser &$modx, array $config = array()) {
        $this->modx   = & $modx;
        $this->config = array_merge(array(
            'contentSelector' => '',
            'fields'          => '',
            'chunks'          => '',
            'snippets'        => '',
            'excludeChunks'   => '',
            'excludeSnippets' => '',
            'cacheDir'        => $this->modx->config['base_path'] . 'assets/cache/.asyncdocs/',
                ), $config);

        $this->_prepareConfig()
                ->_prepareCacheDirectories();
    }

    /**
     * @return \AsyncDocs
     */
    private function _prepareConfig() {
        $this->config['fields']          = explode('||', $this->config['fields']);
        $this->config['chunks']          = explode('||', $this->config['chunks']);
        $this->config['snippets']        = explode('||', $this->config['snippets']);
        $this->config['excludeChunks']   = explode('||', $this->config['excludeChunks']);
        $this->config['excludeSnippets'] = explode('||', $this->config['excludeSnippets']);

        array_walk($this->config['chunks'], "trim");
        array_walk($this->config['snippets'], "trim");
        array_walk($this->config['fields'], "trim");
        array_walk($this->config ['excludeChunks'], "trim");
        array_walk($this->config['excludeSnippets'], "trim");

        return $this;
    }

    /**
     * Returns config parameter by key
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getOption($key, $default = null) {
        $value = $default;

        if (isset($this->config[$key]))
            $value = $this->config[$key];

        return $value;
    }

    /**
     * Creates plugin cache directories
     *
     * @return \AsyncDocs
     */
    private function _prepareCacheDirectories() {
        $defaultDir = $this->modx->config['base_path'] . 'assets/cache/.asyncdocs/';
        $cacheDir   = rtrim($this->getOption('cacheDir', $defaultDir), '/');

        if (!is_dir($cacheDir)) {
            if (!@mkdir($cacheDir, octdec($this->modx->config['new_folder_permissions']), true)) {
                $this->modx->logEvent(0, 3, 'Can\'t create cache folder', self::PLG_NAME);
            }
        }
        return $this;
    }

    /**
     * Check HTTP request on ajax & plugin mode
     *
     * @return boolean
     */
    public function isAjax() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && isset($_REQUEST[self::PLG_NAME]);
    }

    /**
     * Enter method
     */
    public function run() {
        if ($this->isAjax()) {
            header("Expires: 0");
            header("Cache-Control: no-cache, must-revalidate, post-check=0, pre-check=0");
            header("Pragma: no-cache");
            header('Content-type: application/json; charset=utf-8');

            // load document
            $loaded = $this->loadDocument();

            if ($loaded) {
                // preprare response
                $this->prepareDocumentOutput()
                        ->addDocFields()
                        ->addChunks()
                        ->addSnippets()
                        ->addServiceData();

                $this->saveDocumentCache();

                $this->modx->invokeEvent('OnWebPageComplete', array('asyncDocs' => $this));

                $this->_setSessionVars();

                echo json_encode($this->getResponse());
                exit;
            }
        } else {
            $this->_setSessionVars();
        }
    }

    /**
     * Sets specific plugin vars in session
     *
     * @return \AsyncDocs
     */
    private function _setSessionVars() {
        $_SESSION[self::PLG_NAME . '_prevId']  = $this->modx->documentIdentifier;
        $_SESSION[self::PLG_NAME . '_prevIdx'] = (int) $this->modx->documentObject['menuindex'];

        $parents                               = $this->modx->getParentIds($this->modx->documentIdentifier);
        $_SESSION[self::PLG_NAME . '_prevLvl'] = count($parents) + 1;
        return $this;
    }

    /**
     * Adds service data to response array
     *
     * @return \AsyncDocs
     */
    public function addServiceData() {
        $parents               = $this->modx->getParentIds($this->modx->documentIdentifier);
        $this->response['lvl'] = count($parents) + 1;

        $this->response['status']    = $this->status;
        $this->response['fromCache'] = !$this->modx->documentGenerated;
        $this->response['id']        = (int) $this->modx->documentIdentifier;
        $this->response['idx']       = (int) $this->modx->documentObject['menuindex'];
        $this->response['prevId']    = !empty($_SESSION[self::PLG_NAME . '_prevId']) && $_SESSION[self::PLG_NAME . '_prevId'] !== $this->modx->documentIdentifier ? (int) $_SESSION[self::PLG_NAME . '_prevId'] : 1;
        $this->response['prevLvl']   = !empty($_SESSION[self::PLG_NAME . '_prevLvl']) ? (int) $_SESSION[self::PLG_NAME . '_prevLvl'] : 1;
        $this->response['prevIdx']   = !empty($_SESSION[self::PLG_NAME . '_prevIdx']) ? (int) $_SESSION[self::PLG_NAME . '_prevIdx'] : 0;

        if ($this->response['prevId']) { // add tree direction and tree path
            $parents     = array_reverse(array_values($parents));
            $prevParents = array_reverse(array_values($this->modx->getParentIds($this->response['prevId'])));

            array_push($parents, $this->modx->documentIdentifier);
            array_unshift($parents, 0);

            array_push($prevParents, $this->response['prevId']);
            array_unshift($prevParents, 0);

            $pNum = count($prevParents);
            $cNum = count($parents);
            $n    = $pNum > $cNum ? $cNum : $pNum;

            $i = 0;
            do {
                $pId = $prevParents[$i];
                $cId = $parents[$i];
                ++$i;
            } while ($pId === $cId && $i < $n);

            if ($pId === $cId) {
                $dir = $pNum > $cNum ? 'up' : 'down';
            } else {
                $pMenuIdx = $this->modx->db->getValue("SELECT `menuindex` FROM " . $this->modx->getFullTableName('site_content') . " WHERE `id` = {$pId}");
                $cMenuIdx = $this->modx->db->getValue("SELECT `menuindex` FROM " . $this->modx->getFullTableName('site_content') . " WHERE `id` = {$cId}");

                $dir = $pMenuIdx > $cMenuIdx ? 'up' : 'down';
            }

            $this->response['dir']      = $dir;
            $this->response['treePath'] = $parents;
        }

        return $this;
    }

    /**
     * Add documents field to response
     *
     * @return \AsyncDocs
     */
    public function addDocFields() {
        include_once MODX_MANAGER_PATH . "includes/tmplvars.format.inc.php";
        include_once MODX_MANAGER_PATH . "includes/tmplvars.commands.inc.php";

        $this->response['fields'] = array();

        $fields = $this->getOption('fields', array());
        foreach ($fields as $field) {
            if (array_key_exists($field, $this->modx->documentObject)) {
                $value = $this->modx->documentObject[$field];
                if (is_array($value)) {
                    $value = getTVDisplayFormat($value[0], $value[1], $value[2], $value[3], $value[4]);
                }
                $this->response['fields'][$field] = $value;
            }
        }

        return $this;
    }

    /**
     * Add snippets to response
     *
     * @return \AsyncDocs
     * @todo add snippets to response
     */
    public function addSnippets() {
//        $this->response['snippets'] = array();

        return $this;
    }

    /**
     * Add chunks to response
     *
     * @return \AsyncDocs
     * @todo not tested
     */
    public function addChunks() {
//        $this->response['chunks'] = array();

//        $chunks = $this->getOption('chunks', array());
//        foreach ($chunks as $chunkStr) {
//            if (empty($chunkStr))
//                continue;
//
//            $parts     = explode(',', $chunkStr);
//            $chunkName = array_shift($parts);
//
//            $chunkParams = array();
//            $cache       = false;
//
//            foreach ($parts as $p) {
//                $propArr = explode('=', $p);
//
//                if ($propArr) {
//                    if ($propArr[0] === 'cache') {
//                        $cache = filter_var($propArr[1], FILTER_VALIDATE_BOOLEAN);
//                    } else {
//                        $chunkParams[$propArr[0]] = !empty($propArr[1]) ? $propArr[1] : '';
//                    }
//                }
//            }
//
//
//            if (empty($this->chunks[$chunkName]) || empty($this->chunks[$chunkName]['content']) || !$cache) {
//                $this->chunks[$chunkName] = array(
//                    'content' => $this->modx->parseChunk($chunkName, $chunkParams, '[+', '+]'),
//                    'cache'   => $cache,
//                    'params'  => $chunkParams,
//                );
//            }
//
//            $this->response['chunks'][$chunkName] = $this->chunks[$chunkName]['content'];
//        }

        return $this;
    }

    /**
     * Loads document object
     *
     * @return boolean
     */
    public function loadDocument($status = self::STATUS_OK) {
        if ($this->loadDocumentCache()) {
            $this->modx->invokeEvent("OnLoadWebPageCache");
        } elseif (!$this->modx->documentContent) {

            $this->modx->documentObject = $this->modx->getDocumentObject($this->modx->documentMethod, $this->modx->documentIdentifier);
            $this->modx->documentName   = $this->modx->documentObject['pagetitle'];

            if ($this->modx->documentObject['deleted'] == 1) {
                return $this->loadErrorPage();
            }

            if ($this->modx->documentObject['published'] == 0) {
                // Can't view unpublished pages
                if (!$this->modx->hasPermission('view_unpublished')) {
                    return $this->loadErrorPage();
                } else {
                    // Inculde the necessary files to check document permissions
                    include_once $this->modx->config['base_path'] . '/manager/processors/user_documents_permissions.class.php';
                    $udperms           = new udperms();
                    $udperms->user     = $this->modx->getLoginUserID();
                    $udperms->document = $this->modx->documentIdentifier;
                    $udperms->role     = $_SESSION['mgrRole'];

                    // Doesn't have access to this document
                    if (!$udperms->checkPermissions()) {
                        return $this->loadErrorPage();
                    }
                }
            }

            if ($this->modx->documentObject['type'] == "reference") {
                return $this->loadReferencedPage();
            }

            $this->parseDocumentContent();
        }

        $this->status = $status;

        return true;
    }

    /**
     * Loads document by reference
     *
     * @return boolean
     */
    public function loadReferencedPage() {
        $status = self::STATUS_MOVED_PERMANENTLY;
        if (is_numeric($this->modx->documentObject['content'])) {
            $docid = (int) $this->modx->documentObject['content'];
        } elseif (strpos($this->modx->documentObject['content'], '[~') !== false) {
            $docid = (int) preg_replace('/\D/', '', $this->modx->documentObject['content']);
        }

        if (!empty($docid)) {
            $this->modx->documentIdentifier = $docid;
            $this->modx->documentMethod     = 'id';
            return $this->loadDocument($status);
        } else {
            header('HTTP/1.0 500 Internal Server Error');
            exit;
        }
    }

    /**
     * Loads error page. Invoke event OnPageNotFound
     *
     * @return boolean
     */
    public function loadErrorPage() {
        $docid  = (int) $this->modx->invokeEvent('OnPageNotFound', array('asyncDocs' => $this));
        $status = self::STATUS_OK;

        if (!$docid) {
            $docid  = (int) $this->modx->config['error_page'] ? $this->modx->config['error_page'] : $this->modx->config['site_start'];
            $status = self::STATUS_NOT_FOUND;
        }

        return $this->loadForward($docid, $status);
    }

    /**
     * Loads unauthorized page. Invoke event OnPageUnauthorized
     *
     * @return boolean
     */
    public function loadUnauthorizedPage() {
        $_REQUEST['refurl'] = $this->modx->documentIdentifier;
        $status             = self::STATUS_OK;
        $docid              = (int) $this->modx->invokeEvent('OnPageUnauthorized', array('asyncDocs' => $this));

        if (!$docid) {
            $status = self::STATUS_UNAUTHORIZED;
            $docid  = (int) $this->modx->config['unauthorized_page'] ? $this->modx->config['unauthorized_page'] : ($this->modx->config['error_page'] ? $this->modx->config['error_page'] : $this->modx->config['site_start']);
        }

        return $this->loadForward($docid, $status);
    }

    /**
     * Loads document with $docid
     *
     * @param int $docid
     * @param int $status
     * @return boolean
     */
    public function loadForward($docid, $status = self::STATUS_OK) {
        if ($this->modx->forwards > 0) {
            --$this->modx->forwards;
            $this->modx->documentIdentifier = $docid;
            $this->modx->documentMethod     = 'id';
            return $this->loadDocument($status);
        } else {
            header('HTTP/1.0 500 Internal Server Error');
            exit;
        }
    }

    /**
     * Exclude chunks from document content
     *
     * @return \AsyncDocs
     */
    public function excludeChunks() {
        $chunks = $this->getOption('excludeChunks', array());

        if (!empty($chunks)) {
            $pattern                     = '/{{' . implode('}}|{{', $chunks) . '}}/';
            $this->modx->documentContent = preg_replace($pattern, '', $this->modx->documentContent);
        }
        return $this;
    }

    /**
     * Exclude snippets from document content
     *
     * @return \AsyncDocs
     * @todo exclude snippets form content
     */
    public function excludeSnippets() {
        $snippets = $this->getOption('excludeSnippets', array());

        if (!empty($snippets)) {

        }
        return $this;
    }

    /**
     * Sets value to cache by the key
     *
     * @param string $key
     * @param mixed $value
     * @param string $prefix Prefix for cache file
     * @return \AsyncDocs
     */
    public function setCache($key, $value, $prefix = '') {
        $file = $this->getOption('cacheDir') . $prefix . $key . '.cache.php';
        $cacheContent = serialize($value);
        $parts        = pathinfo($file);
        $canSave      = true;

        if (!is_dir($parts['dirname'])) {
            $canSave = false;
            if (!@mkdir(rtrim($parts['dirname'], '/'), octdec($this->modx->config['new_folder_permissions']), true))
                $this->modx->logEvent(0, 3, 'Can\'t make cache directory for instance. Dir: ' . $parts['dirname'], self::PLG_NAME);
            else
                $canSave = true;
        }

        if ($canSave) {
            file_put_contents($file, $cacheContent);
            chmod($file, octdec($this->modx->config['new_file_permissions']));
        }

        return $this;
    }

    /**
     * Returns value from cache by the key
     *
     * @param string $key
     * @param string $prefix Prefix for cache file
     * @return mixed
     */
    public function getCache($key, $prefix = '') {
        $value = null;
        $file  = $this->getOption('cacheDir') . $prefix . $key . '.cache.php';

        if (file_exists($file) && is_file($file) && is_readable($file)) {
            $value = unserialize(file_get_contents($file));
        }
        return $value;
    }

    /**
     * Clears plugin cache. Invoked on OnCacheUpdate event
     *
     * @return \AsyncDocs
     */
    public function clearCache() {
        $files = $this->_getFiles(realpath(rtrim($this->getOption('cacheDir'), '/')));

        while ($file = array_shift($files)) {
            $name = basename($file);
            if (preg_match('/\.cache/', $name)) {
                @unlink($file);
            }
        }
        return $this;
    }

    private function _getFiles($dir) {
        $files = array();

        if ($handle = opendir($dir)) {
            while (($file = readdir($handle)) !== false) {
                if ($file !== '.' && $file !== '..') {
                    if (is_dir($dir . '/' . $file)) {
                        $files = array_merge($files, $this->_getFiles($dir . '/' . $file));
                    } else {
                        $files[] = $dir . '/' . $file;
                    }
                }
            }
            closedir($handle);
        }

        return $files;
    }
     

    /**
     * @return array Response array
     */
    public function getResponse() {
        return $this->response;
    }

    /**
     * Load document template and parse document content
     *
     * @return \AsyncDocs
     */
    public function parseDocumentContent() {
        if (!$this->modx->documentObject['template'])
            $documentContent = "[*content*]"; // use blank template
        else {
            $sql = "SELECT `content` FROM " . $this->modx->getFullTableName("site_templates") . " WHERE "
                    . $this->modx->getFullTableName("site_templates") . ".`id` = '" . $this->modx->documentObject['template'] . "';";

            $result   = $this->modx->db->query($sql);
            $rowCount = $this->modx->db->getRecordCount($result);

            if ($rowCount > 1) {
                $this->modx->messageQuit("Incorrect number of templates returned from database", $sql);
            } elseif ($rowCount == 1) {
                $row             = $this->modx->db->getRow($result);
                $documentContent = $row['content'];
            }
        }
        $this->modx->documentContent = $documentContent;

        // exclude chunks and snippets
        $this->excludeChunks()->excludeSnippets();

        // invoke OnLoadWebDocument event
        $this->modx->invokeEvent("OnLoadWebDocument", array('asyncDocs' => $this));

        // parse document source
        $this->modx->documentContent = $this->modx->parseDocumentSource($this->modx->documentContent);

        return $this;
    }

    /**
     * Prepares document output
     *
     * @return \AsyncDocs
     */
    public function prepareDocumentOutput($noEvent = false) {
        $this->modx->documentOutput = $this->modx->documentContent;

        // check for non-cached snippet output
        if (strpos($this->modx->documentOutput, '[!') > -1) {
            $this->modx->documentOutput = str_replace('[!', '[[', $this->modx->documentOutput);
            $this->modx->documentOutput = str_replace('!]', ']]', $this->modx->documentOutput);

            // Parse document source
            $this->modx->documentOutput = $this->modx->parseDocumentSource($this->modx->documentOutput);
        }

        // remove all unused placeholders
        if (strpos($this->modx->documentOutput, '[+') > -1) {
            $matches                    = array();
            preg_match_all('~\[\+(.*?)\+\]~', $this->modx->documentOutput, $matches);
            if ($matches[0])
                $this->modx->documentOutput = str_replace($matches[0], '', $this->modx->documentOutput);
        }

        $this->modx->documentOutput = $this->modx->rewriteUrls($this->modx->documentOutput);

        $totalTime = ($this->modx->getMicroTime() - $this->modx->tstart);
        $queryTime = $this->modx->queryTime;
        $phpTime   = $totalTime - $queryTime;

        $queryTime = sprintf("%2.4f s", $queryTime);
        $totalTime = sprintf("%2.4f s", $totalTime);
        $phpTime   = sprintf("%2.4f s", $phpTime);
        $source    = $this->modx->documentGenerated == 1 ? "database" : "cache";
        $queries   = isset($this->modx->executedQueries) ? $this->modx->executedQueries : 0;

        if ($this->modx->dumpSQL) {
            $this->modx->documentOutput .= $this->modx->queryCode;
        }

        $this->modx->documentOutput = str_replace(array('[^q^]', '[^qt^]', '[^p^]', '[^t^]', '[^s^]'), array($queries, $queryTime, $phpTime, $totalTime, $source), $this->modx->documentOutput);

        // invoke OnWebPagePrerender event
        if (!$noEvent) {
            $this->modx->invokeEvent("OnWebPagePrerender");
        }

        $this->extractContent();
        $this->response['content'] = trim($this->modx->documentOutput, "\t\n\r\0\x0B ");

        return $this;
    }

    /**
     * Extract content element html from document output
     *
     * @return \AsyncDocs
     */
    public function extractContent() {
        $contentSelector = $this->getOption('contentSelector');

        if ($contentSelector) {
            libxml_use_internal_errors(false);
            $doc    = new DOMDocument('1.0', $this->modx->config['modx_charset']);
            $loaded = @$doc->loadHTML(mb_convert_encoding($this->modx->documentOutput, 'HTML-ENTITIES', $this->modx->config['modx_charset']));

            if ($loaded) {
                $xpath   = new DOMXPath($doc);
                $content = $xpath->query($contentSelector);

                if ($content && $content->length) {
                    $content                    = $content->item(0);
                    $this->modx->documentOutput = $this->outerHtml($content);
                }
            }
        }

        return $this;
    }

    /**
     * Returns outer html of dom node
     *
     * @param DOMNode $node
     * @return string
     */
    public function outerHtml(DOMNode $node) {
        $doc = new DOMDocument('1.0', $this->modx->config['modx_charset']);
        $doc->appendChild($doc->importNode($node, true));
        return $doc->saveHTML();
    }

    /**
     * Trying to load document object from cache
     *
     * @return boolean Return true if load succussed
     */
    public function loadDocumentCache() {
        if ($docObj = $this->getCache($this->modx->documentIdentifier, self::DOC_CACHE_SUFF)) {
            $this->modx->documentGenerated = 0;

            // check page security
            if ($docObj['privateweb'] && isset($docObj['__MODxDocGroups__'])) {
                $pass    = false;
                $usrGrps = $this->getUserDocGroups();
                $docGrps = explode(",", $docObj['__MODxDocGroups__']);
                // check is user has access to doc groups
                if (is_array($usrGrps)) {
                    foreach ($usrGrps as $k => $v)
                        if (in_array($v, $docGrps)) {
                            $pass = true;
                            break;
                        }
                }
                // diplay error pages if user has no access to cached doc
                if (!$pass) {
                    if ($this->modx->config['unauthorized_page']) {
                        // check if file is not public
                        $tbldg    = $this->modx->getFullTableName("document_groups");
                        $secrs    = $this->modx->db->query("SELECT id FROM $tbldg WHERE document = '" . $this->modx->documentIdentifier . "' LIMIT 1;");
                        if ($secrs)
                            $seclimit = mysql_num_rows($secrs);
                    }
                    if ($seclimit > 0) {
                        $this->loadUnauthorizedPage();
                        return false;
                    } else {
                        $this->loadErrorPage();
                        return false;
                    }
                }
            }

            $this->modx->documentContent = $docObj['__MODxParsedContent__'];

            // process cached chunks
            if (!empty($docObj['__MODxChunks__'])) {
                foreach ($docObj['__MODxChunks__'] as $chunkName => $params) {
                    $this->chunks[$chunkName] = $params;
                }

                unset($docObj['__MODxChunks__']);
            }

            // process cached snippets
            if (!empty($docObj['__MODxSnippets__'])) {

                foreach ($docObj['__MODxSnippets__'] as $snippetName => $params) {
                    $this->snippets[$snippetName] = $params;
                }

                unset($docObj['__MODxSnippets__']);
            }

            unset($docObj['__MODxParsedContent__'], $docObj['__MODxDocGroups__']);
            $this->modx->documentObject = $docObj;
        } else {
            $this->modx->documentGenerated = 1;
        }

        return !empty($this->modx->documentContent);
    }

    /**
     * Save document object to cache
     *
     * @return \AsyncDocs
     */
    public function saveDocumentCache() {
        if ($this->modx->documentObject['cacheable'] == 1 && $this->modx->documentGenerated === 1 && $this->modx->documentObject['type'] == 'document' && $this->modx->documentObject['published'] == 1) {
            $this->modx->documentObject['__MODxParsedContent__'] = $this->modx->documentContent;

            // get and store document groups inside document object. Document groups will be used to check security on cache pages
            $sql       = "SELECT document_group FROM " . $this->modx->getFullTableName("document_groups") . " WHERE document='" . $this->modx->documentIdentifier . "'";
            $docGroups = $this->modx->db->getColumn("document_group", $sql);

            if (is_array($docGroups))
                $this->modx->documentObject['__MODxDocGroups__'] = implode(",", $docGroups);


            // add chunks that must be cached
            $chunks = array();
            foreach ($this->chunks as $chunkName => $params) {
                if ($params['cache'])
                    $chunks[$chunkName] = $params;
            }

            if ($chunks)
                $this->modx->documentObject['__MODxChunks__'] = $chunks;


            $this->setCache($this->modx->documentIdentifier, $this->modx->documentObject, self::DOC_CACHE_SUFF);
        }
        return $this;
    }

}

