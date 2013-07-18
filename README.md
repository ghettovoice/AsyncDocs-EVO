AsyncDocs plugin documentation

Author: Vladimir Vershinin

Copyright: 2013, Vladimir Vershinin

License: http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License

============================================================================

The plugin to ajaxify MODx site.

Loads pages asynchronously.

Used on http://mcinterior.ru/

###Current version: 1.1.1

INSTALLATION
--------------------------------------------------------------------------
- Extract plugin archive or clone repository to the directory "MODxRoot/assets/plugins/asyncdocs"

- Create a new plugin in the manager called "AsyncDocs" and copy/paste the contents of assets/plugins/AsyncDocs/plugin.txt
into the code field.

- Check "OnWebPageInit" and "OnCacheUpdate" events at the System Events tab.

- Optional:

    If you use some plugins for custom urls, then check "OnPageNotFound" event and set AsyncDocs the first in execution order for this event
    
    If you want always minify page html check "OnWebPagePrerender" event

- Copy/paste to the config field on the Config tab

    &Configuration:=AsyncDocs;; &contentSelector=XPath to the content DOM element;string; &fields=Document fields;textarea;pagetitle||longtitle||description &chunks=List of additional chunks separated by "||";textarea; &excludeChunks=Exclude chunks list separated by "||";textarea; &urlScheme=Document URL scheme;string; &contentOnly=Return only content field of document;list;true,false;false &cache=Use cache;list;true,false;true &minify=Mnify output;list;true,false;true&Configuration:=ajaxPageLoader;; &contentSelector=XPath to the content DOM element;string; &fields=Document fields;textarea;pagetitle||longtitle||description &chunks=List of additional chunks separated by "||";textarea; &excludeChunks=Exclude chunks list separated by "||";textarea; &urlScheme=Document URL scheme;string; &contentOnly=Return only content field of document;list;true,false;false &cache=Use cache;list;true,false;true &minify=Minify output;list;true,false;true

- Set needed plugin options

USAGE
--------------------------------------------------------------------------
Some preparations before use:
- Move the immutable part of a template in chunks (e. g: header and footer) and list these chunks in &excludeChunks option.

or

- Set the XPath to the content DOM element in &contentSelector option. This element will be extracted from document output.
 
or if you want load clear content

- Set &contentOnly option to true

Plugin options:
    
    - &contentSelector - XPath to the content DOM element
    - &fields - Document fields (list of doc fields to add to the response separated by "||")
    - &chunks - Additional chunk (list of chunks to add to the response separated  by "||"). Record format: chunkName:prop1~val1:prop2~val2||chunkName2:prop1~val1. Chunk output cached by default, to disable caching - add as as property: cache~false or 0, i.e. chunkName:prop1~val1:prop2~val2:cache~false
    - &excludeChunks - Exclude chunks (list of chunks to exclude from document content separated by "||")
    - &urlScheme - url scheme, passed to DocumentParser::makeUrl method. Default value: empty string - relative url
    - &contentOnly - Return only content field of document without template process. Default: false. May be overridden per request by passing in request: asyncdocs_contentonly = true or false or 1 or 0
    - &cache - Load and save document cache or not. Default: true. May be overridden per request by passing in request: asyncdocs_contentonly = true or false or 0 or 1
    - &minify - Minify output html of documents, chunks and snippets. If you check AsyncDocs fire on OnWebPagePrerender Event - it will minify page html

To get page via ajax make an ajax request to url of the page that you need. To execute plugin you must add `asyncdocs: 1` to request fields or set
request header: `X-AsyncDocs: AsyncDocs`. 
In response plugin set the HTTP Status code: 200 - OK, 301 - Moved for references, 401 - Unauthorized, 404 - Not found, so be careful to handle all this cases in you ajax callbacks.

jQuery example request with field:

    $.ajax({
        url: pageUrl,
        dataType: 'json',
        data: {
            asyncdocs: 1
            [, asyncdocs_contentonly: true || false ] //  Process template or not for current document (optional, override global option)
            [, asyncdocs_cache: true || false ] // Use cache or not for current document (optional, override global option)
        },
        success: function(response) {
            // process response, change page content
        },
        error: function(xhr) {
            // process errors
            // check xhr.status
        },
        // or use
        statusCode: {
            301: function() {
            },
            401: function() {
            },
            404: function() {
            }
        }
    });

jQuery example request with header:

    $.ajax({
        url: pageUrl,
        dataType: 'json',
        headers: {
            'X-AsyncDocs': 'AsyncDocs'
        },
        success: function(response) {
            // process response, change page content
        },
        error: function(xhr) {
            // process errors
            // check xhr.status
        },
        // or use
        statusCode: {
            301: function() {
            },
            401: function() {
            },
            404: function() {
            }
        }
    });


The plugin returns a json object:

    {
        chunks: [],                     // array of rendered additional chunks
        content: string,                // document output
        dir: string,                    // document tree direction, "up"(up the documents tree) or "down"(down the document tree)
        fields: {},                     // additional document fields
        fromCache: boolean,             // cache or generated output
        id: int,                        // document id
        idx: int,                       // document menuindex
        lvl: int,                       // depth level of the document
        prevId: int,                    // previous document id
        prevIdx: int,                   // previous document menuindex
        prevLvl: int,                   // previous document depth level
        status: int,                    // HTTP status code of response; 200 - OK, 404 - not found, 301 - moved for references
        treePath: []                    // array of ids from root as '0' to current document id
    }

Plugins that invoked on "OnPageUnauthorized" and "OnPageNotFound" events
must set to event output id of document to load forward. In this events use $asyncDocs->isAjax() to
determine that this is ajax page request.

Example:

    // some code here that determine id of the landing document and setting $_REQUEST vars

    // landingDocId is defined and this is ajax request than return
    // to AsyncDocs plugin id of landing document to load
    if ($landingDocId && $asyncDocs instanceof AsyncDocs && $asyncDocs->isAjax()) {
        $modx->event->output($landingDocId);
        $modx->event->stopPropagation();
    }
    // landingDocId is defined, not ajax
    elseif ($landingDocId) {
        $modx->sendForward($landingDocId);
    }
