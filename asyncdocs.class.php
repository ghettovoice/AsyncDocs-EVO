<?php

/**
 * AsyncDocs
 *
 * Main class of AsyncDocs plugin.
 *
 * @author    Vladimir Vershinin
 * @version   1.1.1
 * @package   AsyncDocs
 * @license   http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License
 * @copyright (c) 2013, Vladimir Vershinin
 */
class AsyncDocs
{

    const PLG_NAME           = 'AsyncDocs';
    const VERSION            = '1.0.0-beta';
    const DOC_CACHE_PREFIX   = 'doc_';
    const CHUNK_CACHE_PREFIX = 'chunk_';
    // statuses
    const STATUS_OK                = 200;
    const STATUS_MOVED_PERMANENTLY = 301;
    const STATUS_UNAUTHORIZED      = 401;
    const STATUS_NOT_FOUND         = 404;
    const STATUS_INTERNAL_ERROR    = 500;

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
    protected $statuses = array();

    /** @var int $status Response status */
    protected $status;
    protected $namespace;

    public function __construct(DocumentParser &$modx, array $config = array()) {
        $this->modx      = & $modx;
        $this->namespace = strtolower(self::PLG_NAME);
        $this->config    = array_merge(array(
            'contentSelector' => '',
            'fields'          => '',
            'chunks'          => '',
            'snippets'        => '',
            'excludeChunks'   => '',
            'excludeSnippets' => '',
            'urlScheme'       => '',
            'setHeader'       => true,
            'contentOnly'     => false,
            'cache'           => true,
            'minify'          => true,
            'cacheDir'        => $this->modx->config['base_path'] . 'assets/cache/.asyncdocs/',
        ), $config);

        $this->_prepareConfig()
            ->_prepareCacheDirectories()
            ->_prepareStatusCodes();
    }

    /**
     * Sets status codes array
     *
     * @return \AsyncDocs
     */
    private function _prepareStatusCodes() {
        $this->statuses = array(
            self::STATUS_OK                => 'HTTP/1.1 ' . self::STATUS_OK . ' OK',
            self::STATUS_MOVED_PERMANENTLY => 'HTTP/1.1 ' . self::STATUS_MOVED_PERMANENTLY . ' Moved Permanently',
            self::STATUS_UNAUTHORIZED      => 'HTTP/1.1 ' . self::STATUS_UNAUTHORIZED . ' Unauthorized',
            self::STATUS_NOT_FOUND         => 'HTTP/1.1 ' . self::STATUS_NOT_FOUND . ' Not Found',
            self::STATUS_INTERNAL_ERROR    => 'HTTP/1.1 ' . self::STATUS_INTERNAL_ERROR . ' Internal Server Error',
        );

        return $this;
    }

    /**
     * @return \AsyncDocs
     */
    private function _prepareConfig() {
        $this->config['fields']          = array_map('trim', explode('||', $this->config['fields']));
        $this->config['chunks']          = array_map('trim', explode('||', $this->config['chunks']));
        $this->config['snippets']        = array_map('trim', explode('||', $this->config['snippets']));
        $this->config['excludeChunks']   = array_map('trim', explode('||', $this->config['excludeChunks']));
        $this->config['excludeSnippets'] = array_map('trim', explode('||', $this->config['excludeSnippets']));

        // options from  request
        $this->config['contentOnly'] = isset($_REQUEST[$this->namespace . '_contentonly']) ? filter_var($_REQUEST[$this->namespace . '_contentonly'], FILTER_VALIDATE_BOOLEAN) : $this->config['contentOnly'];
        $this->config['cache']       = isset($_REQUEST[$this->namespace . '_cache']) ? filter_var($_REQUEST[$this->namespace . '_cache'], FILTER_VALIDATE_BOOLEAN) : $this->config['cache'];

        return $this;
    }

    /**
     * Returns config parameter by key
     *
     * @param string $key
     * @param mixed  $default
     *
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
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && (isset($_REQUEST[self::PLG_NAME]) || isset($_REQUEST[$this->namespace]) || (!empty($_SERVER['HTTP_X_' . strtoupper(self::PLG_NAME)]) && strtolower($_SERVER['HTTP_X_' . strtoupper(self::PLG_NAME)]) === $this->namespace));
    }

    /**
     * Main enter method
     */
    public function run() {
        if (!$this->isAjax()) {
            $this->_setSessionVars();

            return false;
        }

        // load document
        $this->loadDocument();
        // preprare response
        $this->prepareDocumentOutput()
            ->addDocFields()
            ->addChunks()
            ->addSnippets()
            ->addServiceData();

        $this->saveDocumentCache();

        $this->modx->invokeEvent('OnWebPageComplete', array('asyncDocs' => $this));

        $this->_setSessionVars()
            ->echoResponse();
    }

    /**
     * Echo response to output
     */
    public function echoResponse() {
        header("Expires: 0");
        header("Cache-Control: no-cache, must-revalidate, post-check=0, pre-check=0");
        header("Pragma: no-cache");
        header('Content-type: application/json; charset=utf-8');

        $this->_setResponseHeader();

        echo json_encode($this->getResponse());
        exit;
    }

    /**
     * Sets HTTP header status code
     *
     * @return \AsyncDocs
     */
    private function _setResponseHeader() {
        header($this->statuses[$this->status]);

        return $this;
    }

    /**
     * Sets specific plugin vars in session
     *
     * @return \AsyncDocs
     */
    private function _setSessionVars() {
        $_SESSION[$this->namespace . '_prevId']  = $this->modx->documentIdentifier;
        $_SESSION[$this->namespace . '_prevIdx'] = (int)$this->modx->documentObject['menuindex'];

        $parents                                 = $this->modx->getParentIds($this->modx->documentIdentifier);
        $_SESSION[$this->namespace . '_prevLvl'] = count($parents) + 1;
        $_SESSION[$this->namespace . '_prevUrl'] = $this->getDocUrl($this->modx->documentIdentifier);

        return $this;
    }

    public function getDocUrl($id) {
        $id     = (int)$id;
        $scheme = $this->getOption('urlScheme', '');
        $args   = http_build_query(array_diff_key($_GET, array_flip(array(self::PLG_NAME, $this->namespace, 'id', 'q'))));

        return $id === (int)$this->modx->config['site_start'] ? ($scheme ? $this->modx->config['site_url'] : $this->modx->config['base_url']) : $this->modx->makeUrl($id, '', $args, $scheme);
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
        $this->response['id']        = (int)$this->modx->documentIdentifier;
        $this->response['idx']       = (int)$this->modx->documentObject['menuindex'];
        $this->response['url']       = $this->getDocUrl($this->modx->documentIdentifier);
        $this->response['prevId']    = !empty($_SESSION[$this->namespace . '_prevId']) && $_SESSION[$this->namespace . '_prevId'] !== $this->modx->documentIdentifier ? (int)$_SESSION[$this->namespace . '_prevId'] : 1;
        $this->response['prevLvl']   = !empty($_SESSION[$this->namespace . '_prevLvl']) ? (int)$_SESSION[$this->namespace . '_prevLvl'] : 1;
        $this->response['prevIdx']   = !empty($_SESSION[$this->namespace . '_prevIdx']) ? (int)$_SESSION[$this->namespace . '_prevIdx'] : 0;
        $this->response['prevUrl']   = !empty($_SESSION[$this->namespace . '_prevUrl']) ? $_SESSION[$this->namespace . '_prevUrl'] : '';

        if ($this->response['prevId']) { // add tree direction and tree path
            $parents     = array_map('intval', array_reverse(array_values($parents)));
            $prevParents = array_map('intval', array_reverse(array_values($this->modx->getParentIds($this->response['prevId']))));

            array_push($parents, (int)$this->modx->documentIdentifier);
            array_unshift($parents, 0);

            array_push($prevParents, (int)$this->response['prevId']);
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
     */
    public function addChunks() {
        $this->response['chunks'] = array();
        $chunks                   = $this->getOption('chunks', array());
        foreach ($chunks as $chunkStr) {
            if (empty($chunkStr))
                continue;

            $parts = explode(':', $chunkStr);
            array_walk($parts, "trim");
            $chunkName   = array_shift($parts);
            $chunkParams = array();
            $cache       = true;

            $chunkId = (int)$this->modx->db->getValue("SELECT `id` FROM {$this->modx->getFullTableName('site_htmlsnippets')} WHERE `name` LIKE '$chunkName'");
            if (!$chunkId)
                continue;

            foreach ($parts as $p) {
                $propArr = explode('~', $p);
                if ($propArr) {
                    $prop = trim($propArr[0]);
                    $val  = isset($propArr[1]) ? trim($propArr[1]) : '';

                    if ($prop === 'cache') {
                        $cache = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                    } else {
                        $chunkParams[$prop] = $val;
                    }
                }
            }

            if ($cache)
                $this->chunks[$chunkId] = $this->getCache($chunkId, self::CHUNK_CACHE_PREFIX);

            if (empty($this->chunks[$chunkId]) || empty($this->chunks[$chunkId]['content'])) {
                $this->chunks[$chunkId] = array(
                    'id'      => $chunkId,
                    'content' => $this->processOutput($this->modx->parseChunk($chunkName, $chunkParams, '[+', '+]')),
                    'cache'   => $cache,
                    'params'  => $chunkParams,
                );
            }

            $this->response['chunks'][$chunkName] = $this->chunks[$chunkId]['content'];

            if ($cache && $this->chunks[$chunkId]['content'])
                $this->setCache($chunkId, $this->chunks[$chunkName], self::CHUNK_CACHE_PREFIX);
        }

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
     * Loads document by reference.
     * Sets http status 500 on error.
     *
     * @return boolean
     */
    public function loadReferencedPage() {
        if (is_numeric($this->modx->documentObject['content'])) {
            $docid = (int)$this->modx->documentObject['content'];
        } elseif (strpos($this->modx->documentObject['content'], '[~') !== false) {
            $docid = (int)preg_replace('/\D/', '', $this->modx->documentObject['content']);
        } else { // external resource
            $this->modx->sendRedirect($this->modx->documentObject['content'], 0, '', self::STATUS_MOVED_PERMANENTLY);
        }

        if (!empty($docid)) {
            $this->modx->documentIdentifier = $docid;
            $this->modx->documentMethod     = 'id';

            return $this->loadDocument(self::STATUS_MOVED_PERMANENTLY);
        } else {
            $this->status = self::STATUS_INTERNAL_ERROR;
            $this->_setResponseHeader();
            exit;
        }
    }

    /**
     * Loads error page. Invoke event OnPageNotFound
     *
     * @return boolean
     */
    public function loadErrorPage() {
        $res    = $this->invokeEvent('OnPageNotFound');
        $docid  = array_pop($res);
        $status = self::STATUS_OK;

        if (!$docid || !is_numeric($docid)) {
            $docid  = (int)$this->modx->config['error_page'] ? $this->modx->config['error_page'] : $this->modx->config['site_start'];
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
        $res                = $this->invokeEvent('OnPageUnauthorized');
        $docid              = array_pop($res);

        if (!$docid || !is_numeric($docid)) {
            $status = self::STATUS_UNAUTHORIZED;
            $docid  = (int)$this->modx->config['unauthorized_page'] ? $this->modx->config['unauthorized_page'] : ($this->modx->config['error_page'] ? $this->modx->config['error_page'] : $this->modx->config['site_start']);
        }

        return $this->loadForward($docid, $status);
    }

    /**
     * Loads document with $docid
     *
     * @param int $docid
     * @param int $status
     *
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
            array_walk($chunks, "preg_quote");
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
     * @param mixed  $value
     * @param string $prefix Prefix for cache file
     *
     * @return \AsyncDocs
     */
    public function setCache($key, $value, $prefix = '') {
        $file         = $this->getOption('cacheDir') . $prefix . $key . '.cache.php';
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
     *
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

    /**
     * Returns files list in directory $dir
     *
     * @param string $dir
     *
     * @return array
     */
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
        if (!$this->modx->documentObject['template'] || $this->getOption('contentOnly', false))
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
            $matches = array();
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

        $this->modx->documentOutput = $this->processOutput($this->modx->documentOutput);
        $this->response['content']  = $this->modx->documentOutput;

        return $this;
    }

    /**
     * @param string $output
     *
     * @return string
     */
    public function processOutput($output) {
        $output = trim($output, "\t\n\r\0\x0B ");
        if ($this->getOption('minify', true))
            $output = $this->minifyOutput($output);

        return $output;
    }

    /**
     * Minifies output html
     *
     * @param string $output
     *
     * @return string
     */
    public function minifyOutput($output) {
        include_once dirname(__FILE__) . '/minify/min/lib/Minify/HTML.php';

        return Minify_HTML::minify($output);
    }

    /**
     * Enter method for onPageNotFound event. Used for catch control before firing OnWebPageInit event to process plugins for custom urls
     */
    public function onPageNotFound() {
        if (!$this->isAjax())
            return false;

        $this->loadErrorPage();

        // preprare response
        $this->prepareDocumentOutput()
            ->addDocFields()
            ->addChunks()
            ->addSnippets()
            ->addServiceData();

        $this->saveDocumentCache();

        $this->modx->invokeEvent('OnWebPageComplete', array('asyncDocs' => $this));

        $this->_setSessionVars()
            ->echoResponse();
    }

    /**
     * Invokes plaugins on MODx event
     *
     * @param string $evtName
     * @param string $extParams
     *
     * @return array|boolean Array of event outputs or false
     */
    public function invokeEvent($evtName, $extParams = array()) {
        if (!$evtName)
            return false;

        // remove async docs from pluginEvent array for current event to prevent infinit execution
        array_shift($this->modx->pluginEvent[$evtName]);
//        $first = array_shift($this->modx->pluginEvent[$evtName]);
//        if (strtolower($first) !== $this->namespace) {
//            $this->modx->logEvent(0, 2, 'AsyncDocs must be the first plugin in execution chain to process onPageNotFound Event', self::PLG_NAME);
//            return false;
//        }
        // invoke other plugins of current event, determine landing doc id for custom urls
        $res = $this->modx->invokeEvent($evtName, array_merge($extParams, array('asyncDocs' => $this)));
        $this->modx->event->stopPropagation(); // stop propagation, other plugins already executed
        return $res;
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
     *
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
        if ($this->getOption('cache', true) && $docObj = $this->getCache($this->modx->documentIdentifier, self::DOC_CACHE_PREFIX)) {
            $this->modx->documentGenerated = 0;

            // check page security
            if ($docObj['privateweb'] && isset($docObj['__MODxDocGroups__'])) {
                $pass    = false;
                $usrGrps = $this->modx->getUserDocGroups();
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
                        $tbldg = $this->modx->getFullTableName("document_groups");
                        $secrs = $this->modx->db->query("SELECT id FROM $tbldg WHERE document = '" . $this->modx->documentIdentifier . "' LIMIT 1;");
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
        if ($this->modx->documentObject['cacheable'] == 1 && $this->modx->documentGenerated === 1 && $this->modx->documentObject['type'] == 'document' && $this->modx->documentObject['published'] == 1 && $this->getOption('cache', true)) {
            $this->modx->documentObject['__MODxParsedContent__'] = $this->modx->documentContent;

            // get and store document groups inside document object. Document groups will be used to check security on cache pages
            $sql       = "SELECT document_group FROM " . $this->modx->getFullTableName("document_groups") . " WHERE document='" . $this->modx->documentIdentifier . "'";
            $docGroups = $this->modx->db->getColumn("document_group", $sql);

            if (is_array($docGroups))
                $this->modx->documentObject['__MODxDocGroups__'] = implode(",", $docGroups);

            $this->setCache($this->modx->documentIdentifier, $this->modx->documentObject, self::DOC_CACHE_PREFIX);
        }

        return $this;
    }

}

