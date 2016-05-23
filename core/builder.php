<?php

namespace Kirby\Plugin\StaticBuilder;

use C;
use Exception;
use F;
use URL;
use Folder;
use Page;
use Pages;
use Response;
use Site;
use Str;
use Tpl;

/**
 * Static HTML builder class for Kirby CMS
 * Exports page content as HTML files, and copies assets.
 *
 * @package Kirby\Plugin\StaticBuilder
 */
class Builder {

	// Used for building relative URLs
	const URLPREFIX = 'STATICBUILDER_URL_PREFIX';

	// Kirby instance
	protected $kirby;

	// Project root
	protected $root;

	// Config (there is a 'plugin.staticbuilder.[key]' for each one)
	protected $outputdir     = 'static';
	protected $filename      = '/index.html';
	protected $baseurl       = '/';
	protected $routes        = ['*'];
	protected $excluderoutes = ['staticbuilder*', '/', 'home'];
	protected $assets        = ['assets', 'content', 'thumbs'];
	protected $uglyurls      = false;
	protected $pagefiles     = false;
	protected $filter        = null;

	// Callable for PHP Errors
	public $shutdown;
	public $lastpage;

	// Storing results
	public $summary = [];

	// Callbacks to execute after an item has been built
	protected $onLogCallbacks = [];

	/**
	 * Builder constructor.
	 * Resolve config and stuff.
	 * @throws Exception
	 */
	public function __construct()
	{
		// Kirby instance with some hacks
		$this->kirby = $this->kirbyInstance();

		// Project root
		$this->root = $this->kirby->roots()->index;

		// Ouptut directory
		$dir = c::get('plugin.staticbuilder.outputdir', $this->outputdir);
		$dir = $this->isAbsolutePath($dir) ? $dir : $this->root . DS . $dir;
		$folder = new Folder($this->normalizePath($dir));

		if ($folder->name() !== 'static') {
			throw new Exception('StaticBuilder: outputdir MUST be "static" or end with "/static"');
		}
		if ($folder->exists() === false) {
			$folder->create();
		}
		if ($folder->isWritable() === false) {
			throw new Exception('StaticBuilder: outputdir is not writeable.');
		}
		$this->outputdir = $folder->root();

		// File name or extension for output pages
		if ($fn = c::get('plugin.staticbuilder.filename')) {
			$fn = str_replace(['/', '\\'], '', $fn);
			$this->filename = str::startsWith($fn,'.') ? $fn : '/' . $fn;
		};

		// URL root, make sure it ends with just one slash
		$baseurl = c::get('plugin.staticbuilder.baseurl', $this->baseurl);
		$this->baseurl = rtrim($baseurl, '/') . '/';

		$this->routes = c::get('plugin.staticbuilder.routes', $this->routes);
		$this->excluderoutes = array_merge(
			$this->excluderoutes,
			c::get('plugin.staticbuilder.excluderoutes', [])
		);

		// Normalize assets config
		$assets = c::get('plugin.staticbuilder.assets', $this->assets);
		$this->assets = [];
		foreach ($assets as $a) {
			if (is_string($a)) $this->assets[$a] = $a;
			elseif (is_array($a) && count($a) > 1)
				$this->assets[array_shift($a)] = array_shift($a);
		}

		// Output ugly URLs (e.g. '/my/page/index.html')?
		$this->uglyurls = c::get('plugin.staticbuilder.uglyurls', $this->uglyurls);

		// Copy page files to a folder named after the page URL?
		$this->pagefiles = c::get('plugin.staticbuilder.pagefiles', $this->pagefiles);

		// Filter for pages to build or ignore
		if (is_callable($filter = c::get('plugin.staticbuilder.filter'))) {
			$this->filter = $filter;
		}
	}

	/**
	 * Change some of Kirby’s settings to help us building HTML that
	 * is a tiny bit different from the live pages.
	 * @return \Kirby
	 */
	protected function kirbyInstance() {
		// This will retrieve the existing instance with stale settings
		$kirby = kirby();
		// We need to call configure again with the new url prefix
		c::set('url', static::URLPREFIX);
		$kirby->configure();
		// But this one stays cached anyway, so we have to update it manually
		$kirby->site->url = static::URLPREFIX;
		return $kirby;
	}

	/**
	 * Figure out if a filesystem path is absolute or if we should treat
	 * it as relative (to the project folder or output folder).
	 * @param string $path
	 * @return boolean
	 */
	protected function isAbsolutePath($path) {
		$pattern = '/^([\/\\\]|[a-z]:)/i';
		return preg_match($pattern, $path) == 1;
	}

	/**
	 * Normalize a file path string to remove ".." etc.
	 * @param string $path
	 * @param string $sep Path separator to use in output
	 * @return string
	 */
	protected function normalizePath($path, $sep=DS) {
		$path = preg_replace('/[\\/\\\]+/', $sep, $path);
		$out = [];
		foreach (explode($sep, $path) as $i => $fold) {
			if ($fold == '..' && $i > 0 && end($out) != '..') array_pop($out);
			$fold = preg_replace('/\.{2,}/', '.', $fold);
			if ($fold == '' || $fold == '.') continue;
			else $out[] = $fold;
		}
		return ($path[0] == $sep ? $sep : '') . join($sep, $out);
	}

	/**
	 * Generate a relative path between two paths.
	 * @param string $from
	 * @param string $to
	 * @return string
	 */
	protected function relativePath($from, $to) {
		$fromAry = explode('/', $from);
		$toAry = explode('/', $to);
		while (count($fromAry) && count($toAry) && $fromAry[0] === $toAry[0]) {
			array_shift($fromAry);
			array_shift($toAry);
		}
		return str_repeat('../', count($fromAry)) . implode('/', $toAry);
	}

	protected function shouldBuildRoute($uri) {
		// Not handling routes with parameters
		if (strpos($uri, '(') !== false) return false;
		// Match against ignored routes
		foreach ($this->excluderoutes as $ignored) {
			if (str::endsWith($ignored, '*') && str::startsWith($uri, rtrim($ignored, '*'))) return false;
			if ($uri === $ignored) return false;
		}
		return true;
	}

	/**
	 * Should we include this page and its files in the static build?
	 * @param Page $page
	 * @return bool
	 * @throws Exception
	 */
	protected function filterPage(Page $page) {
		if ($this->filter != null) {
			$val = call_user_func($this->filter, $page);
			if (!is_bool($val)) throw new Exception(
				"StaticBuilder page filter must return a boolean value");
			return $val;
		} else {
			// Only include pages which have an existing text file
			// (We check that it exists because Kirby sets the text file
			// name to the folder name when it can't find one.)
			return file_exists($page->textfile());
		}
	}

	/**
	 * Check that the destination path is somewhere we can write to
	 * @param string $absolutePath
	 * @return boolean
	 */
	protected function filterPath($absolutePath) {
		// Unresolved paths with '..' are invalid
		if (str::contains($absolutePath, '..')) return false;
		return str::startsWith($absolutePath, $this->outputdir . DS);
	}

	/**
	 * Build a relative URL from one absolute path to another,
	 * going back as many times as needed. Paths should be absolute
	 * or are considered to be starting from the same root.
	 * @param string $from
	 * @param string $to
	 * @return string
	 */
	protected function relativeUrl($from='', $to='') {
		$from = explode('/', ltrim($from, '/'));
		$to   = explode('/', ltrim($to, '/'));
		$last = false;
		while (count($from) && count($to) && $from[0] === $to[0]) {
			$last = array_shift($from);
			array_shift($to);
		}
		if (count($from) == 0) {
			if ($last) array_unshift($to, $last);
			return './' . implode('/', $to);
		}
		else {
			return './' . str_repeat('../', count($from)-1) . implode('/', $to);
		}
	}

	/**
	 * Rewrites URLs in the response body of a page
	 * @param string $text Response text
	 * @param string $pageUrl URL for the page
	 * @return string
	 */
	protected function rewriteUrls($text, $pageUrl) {
		$relative = $this->baseurl === './';
		if ($relative || $this->uglyurls) {
			// Match restrictively urls starting with prefix, and which are
			// correctly escaped (no whitespace or quotes).
			$find = preg_quote(static::URLPREFIX) . '(\/?[^<>{}"\'\s]*)';
			$text = preg_replace_callback(
				"!$find!",
				function($found) use ($pageUrl, $relative) {
					$url = $found[0];
					if ($this->uglyurls) {
						$path = $found[1];
						if (!$path || $path === '/') {
							$url = rtrim($url, '/') . '/index.html';
						}
						elseif (!str::endsWith($url, '/') && !pathinfo($url, PATHINFO_EXTENSION)) {
							$url .= $this->filename;
						}
					}
					if ($relative) {
						if ($this->uglyurls) $pageUrl .= $this->filename;
						$pageUrl = str_replace(static::URLPREFIX, '', $pageUrl);
						$url = str_replace(static::URLPREFIX, '', $url);
						$url = $this->relativeUrl($pageUrl, $url);
					}
					return $url;
				},
				$text
			);
		}
		// Except if we have converted to relative URLs, we still have
		// the placeholder prefix in the text. Swap in the base URL.
		$pattern = '!' . preg_quote(static::URLPREFIX) . '\/?!';
		return preg_replace($pattern, $this->baseurl, $text);
	}

	/**
	 * Write the HTML for a page and copy its files
	 * @param Page $page
	 * @param bool $write Should we write files or just report info (dry-run).
	 * @return array
	 */
	protected function buildPage(Page $page, $write=false) {
		$log = [
			'type'   => 'page',
			'status' => '',
			'reason' => '',
			'source' => 'content/' . $page->diruri(),
			'dest'   => null,
			'size'   => null,
			// Specific to pages
			'title'  => $page->title()->value,
			'uri'    => $page->uri(),
			'files'  => []
		];

		// Figure where we might write the page and its files
		$base = ltrim(str_replace(static::URLPREFIX, '', $page->url()), '/');
		$file = $page->isHomePage() ? 'index.html' : $base . $this->filename;
		$file = $this->normalizePath($this->outputdir . DS . $file);
		$log['dest'] = str_replace($this->outputdir, 'static', $file);

		// Store reference to this page in case there's a fatal error
		$this->lastpage = $log['source'];

		// Check if we will build this page and report why not
		if (!$this->filterPage($page)) {
			$log['status'] = 'ignore';
			if ($this->filter == null) $log['reason'] = 'Page has no text file';
			else $log['reason'] = 'Excluded by custom filter';
			return $this->log($log);
		}
		// Should never happen, but better safe than sorry
		elseif (!$this->filterPath($file)) {
			$log['status'] = 'ignore';
			$log['reason'] = 'Output path for page goes outside of static directory';
		}

		if ($write == false) {
			// Get status of output path
			if (is_file($file)) {
				$outdated = filemtime($file) < $page->modified();
				$log['status'] = $outdated ? 'outdated' : 'uptodate';
				$log['size'] = filesize($file);
			}
			else {
				$log['status'] = 'missing';
			}
			if ($this->pagefiles) {
				$log['files'] = $page->files()->count();
			}
			return $this->log($log);
		}

		// Render page
		$this->kirby->site()->visit($page->uri());
		$text = $this->kirby->render($page, [], false);
		$text = $this->rewriteUrls($text, $page->url());

		f::write($file, $text);
		$log['size'] = strlen($text);
		$log['status'] = 'generated';

		// Copy page files in a folder
		if ($this->pagefiles) {
			$dir = $this->normalizePath($this->outputdir . DS . $base);
			foreach ($page->files() as $f) {
				$dest = $dir . DS . $f->filename();
				$f->copy($dest);
				$log['files'][] = str_replace($this->outputdir, 'static', $dest);
			}
		}
		return $this->log($log);
	}

	protected function buildRoute($uri, $write=false) {
		if (!is_string($uri) || !$this->shouldBuildRoute($uri)) {
			return false;
		}

		$log = [
			'type'   => 'route',
			'status' => '',
			'reason' => '',
			'source' => $uri,
			'dest'   => $uri,
			'size'   => null,
		];

		if ($uri == '*') {
			// Replace '*' entry with all GET routes
			foreach ($this->kirby->router->routes('GET') as $entry) {
				$this->buildRoute($entry->pattern, $write);
			}
			if ($write) $log['status'] = 'generated';
		} else if (preg_match('~^([^{}]+)(/[^/{}]*)\{([^/{}}]+)\}([^/{}]*)(.*)~', $uri, $parts)) {
			// Expand {param} in route pattern
			list($match, $id, $prefix, $param, $suffix, $trailing) = $parts;
			$page = page($id);
			if ($page) {
				$permutations = $page->children()->visible()->pluck($param, ',', true);
				foreach ($permutations as $perm) {
					$this->buildRoute(join('', [$id, $prefix, $perm, $suffix, $trailing]), $write);
				}
				if ($write) $log['status'] = 'generated';
			} else {
				$log['status'] = 'missing';
			}
		} else {
			// Append trailing /index.htm to destination if extension is missing
			$target = $uri;
			if (pathinfo($target, PATHINFO_EXTENSION) == '') {
				$target = rtrim($target, '/') . ($target == '/' ? '/index.html' : $this->filename);
			}
			$target = $this->normalizePath( $this->outputdir . DS . $target);
			$log['dest'] = $target;

			if ($this->filterPath($target) == false) {
				$log['status'] = 'ignore';
				$log['reason'] = 'Output path for page goes outside of static directory';
			}

			if ($write == false) {
				// Get status of output path
				if (is_file($target)) {
					$log['status'] = 'outdated';
					$log['size'] = filesize($target);
				}
			} else {
				$this->lastpage = $log['source'];

				// Temporarily override request method to ensure correct route is found
				$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
				$_SERVER['REQUEST_METHOD'] = 'GET';
				$this->kirby->site()->visit($uri);
				$route = $this->kirby->router->run($uri);

				if (is_null($route)) {
					// Unmatched route
					$log['status'] = 'missing';
				} else {
					// Grab route output using output buffering
					ob_start();
					$response = call($route->action(), $route->arguments());
					$text = $this->rewriteUrls(ob_get_contents(), $uri);
					ob_end_clean();

					// Write page content
					f::write($target, $text);
					$log['status'] = 'generated';
					$log['size'] = strlen($text);
				}

				$_SERVER['REQUEST_METHOD'] = $requestMethod;
			}
		}

		return $this->log($log);
	}

	/**
	 * Copy a file or folder to the static directory
	 * This function is responsible for normalizing paths and making sure
	 * we don't write files outside of the static directory.
	 *
	 * @param string $from Source file or folder
	 * @param string $to Destination path
	 * @param bool $write Should we write files or just report info (dry-run).
	 * @return array|boolean
	 * @throws Exception
	 */
	protected function copyAsset($from=null, $to=null, $write=false) {
		if (!is_string($from) or !is_string($to)) {
			return false;
		}
		$log = [
			'type'   => 'asset',
			'status' => '',
			'reason' => '',
			// Use unnormalized, relative paths in log, because they
			// might help understand why a file was ignored
			'source' => $from,
			'dest'   => 'static/',
			'size'   => null
		];

		// Source can be absolute
		if ($this->isAbsolutePath($from)) {
			$source = $from;
		} else {
			$source = $this->normalizePath($this->root . DS . $from);
		}

		// But target is always relative to static dir
		$target = $this->normalizePath($this->outputdir . DS . $to);
		if ($this->filterPath($target) == false) {
			$log['status'] = 'ignore';
			$log['reason'] = 'Cannot copy asset outside of the static folder';
			return $this->log($log);
		}
		$log['dest'] .= str_replace($this->outputdir . DS, '', $target);

		// Get type of asset
		if (is_dir($source)) {
			$log['type'] = 'folder';
		}
		elseif (is_file($source)) {
			$log['type'] = 'file';
		}
		else {
			$log['status'] = 'ignore';
			$log['reason'] = 'Source file or folder not found';
		}

		// Copy a folder
		if ($write && $log['type'] == 'folder') {
			$source = new Folder($source);
			$existing = new Folder($target);
			if ($existing->exists()) $existing->remove();
			$log['status'] = $source->copy($target) ? 'done' : 'failed';
		}

		// Copy a file
		if ($write && $log['type'] == 'file') {
			$log['status'] = copy($source, $target) ? 'done' : 'failed';
		}

		return $this->log($log);
	}

	/**
	 * Get a collection of pages to work with (collection may be empty)
	 * @param Page|Pages|Site $content Content to write to the static folder
	 * @return Pages
	 */
	protected function getPages($content) {
		if ($content instanceof Pages) {
			return $content;
		}
		elseif ($content instanceof Site) {
			return $content->index();
		}
		else {
			$pages = new Pages([]);
			if ($content instanceof Page) $pages->add($content);
			return $pages;
		}
	}

	/**
	 * Try to render any PHP Fatal Error in our own template
	 * @return bool
	 */
	protected function showFatalError() {
		$error = error_get_last();
		// Check if last error is of type FATAL
		if (isset($error['type']) && $error['type'] == E_ERROR) {
			echo $this->htmlReport([
				'error' => 'Error while building pages',
				'summary' => $this->summary,
				'lastPage' => $this->lastpage,
				'errorDetails' => $error['message'] . "<br>\n"
					. 'In ' . $error['file'] . ', line ' . $error['line']
			]);
		}
	}

	/**
	 * Append the item to the current summary and notify any callbacks.
	 * @param array $item Metadata for item that was built
	 * @return array $item
	 */
	protected function log($item) {
		foreach ($this->onLogCallbacks as $cb) $cb($item);
		return $this->summary[] = $item;
	}

	/**
	 * Register new log callback.
	 * @param function $callback
	 */
	public function onLog($callback) {
		$this->onLogCallbacks[] = $callback;
	}

	/**
	 * Build or rebuild static content
	 * @param Page|Pages|Site $content Content to write to the static folder
	 * @param boolean $write Should we actually write files
	 * @return array
	 */
	public function run($content, $write=false) {
		$this->summary = [];

		if ($write) {
			// Kill PHP Error reporting when building pages, to "catch" PHP errors
			// from the pages or their controllers (and plugins etc.). We're going
			// to try to hande it ourselves
			$level = error_reporting();
			$catchErrors = c::get('plugin.staticbuilder.catcherrors', false);
			if ($catchErrors) {
				$this->shutdown = function () {
					$this->showFatalError();
				};
				register_shutdown_function($this->shutdown);
				error_reporting(0);
			}
		}

		if ($write && $content instanceof Site) {
			$folder = new Folder($this->outputdir);
			$folder->flush();
		}

		foreach($this->getPages($content) as $page) {
			$this->buildPage($page, $write);
		}

		if ($content instanceof Site) {
			foreach ($this->routes as $route) {
				$this->buildRoute($route, $write);
			}

			foreach ($this->assets as $from=>$to) {
				$this->copyAsset($from, $to, $write);
			}
		}

		// Restore error reporting if building pages worked
		if ($write && $catchErrors) {
			error_reporting($level);
			$this->shutdown = function () {};
		}
	}

	/**
	 * Render the HTML report page
	 *
	 * @param array $data
	 * @return Response
	 */
	public function htmlReport($data=[]) {
		// Forcefully remove headers that might have been set by some
		// templates, controllers or plugins when rendering pages.
		header_remove();
		$css = __DIR__ . DS . '..' . DS . 'assets' . DS . 'report.css';
		$tpl = __DIR__ . DS . '..' . DS . 'templates' . DS . 'report.php';
		$data['styles'] = file_get_contents($css);
		$body = tpl::load($tpl, $data);
		return new Response($body, 'html', $data['error'] ? 500 : 200);
	}

}
