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
use Tpl;


/**
 * Static HTML builder class for Kirby CMS
 * Exports page content as HTML files, and copies assets.
 *
 * @package Kirby\Plugin\StaticBuilder
 */
class Builder {

	protected $kirby;

	// Defaults for 'plugin.staticbuilder.folder'
	static $defaultFolder = 'static';
	// Defaults for 'plugin.staticbuilder.assets'
	static $defaultAssets = ['assets', 'content', 'thumbs'];
	// Defaults for 'plugin.staticbuilder.suffix'
	static $defaultSuffix = '/index.html';

	protected $urlPlaceholder = 'STATICBUILDER_KIRBY_INDEX';

	// Resolved config
	protected $index;
	protected $folder;
	protected $suffix;
	protected $urlbase;
	protected $filter;
	protected $assets;
	protected $routes;

	// Callable for PHP Errors
	public $shutdown;
	public $lastpage;

	// Storing results
	public $summary = [];

	// Optional callback to execute after an item has been built
	public $itemCallback = null;

	/**
	 * Builder constructor.
	 * Resolve config and stuff.
	 * @throws Exception
	 */
	public function __construct() {
		$this->kirby = kirby();

		// Source folder
		$this->index = $this->kirby->roots()->index;

		// Ouptut folder (should be called 'static', created and writable)
		$folder = c::get('plugin.staticbuilder.folder', static::$defaultFolder);
		if ($this->isAbsolutePath($folder) == false) {
			$folder = $this->index . DS . $folder;
		}
		$folder = new Folder($this->normalizePath($folder));
		if ($folder->name() !== 'static') {
			throw new Exception('StaticBuilder: destination folder may have any path but the folder name MUST be "static". Configured name was: "' . $folder->name() . '".');
		}
		if ($folder->exists() === false) $folder->create();
		if ($folder->isWritable() === false) {
			throw new Exception('StaticBuilder: destination folder is not writeable.');
		}
		$this->folder = $folder->root();

		// Suffix for output pages
		$suffix = c::get('plugin.staticbuilder.suffix', static::$defaultSuffix);
		$this->suffix = $this->normalizePath($suffix);

		// Filter for pages to build or ignore
		$this->filter = null;
		if (is_callable($filter = c::get('plugin.staticbuilder.filter'))) {
			$this->filter = $filter;
		}

		$this->routes = c::get('plugin.staticbuilder.routes', []);
		$this->urlbase = c::get('plugin.staticbuilder.urlbase', false);

		// Normalize assets config
		$assetConf = c::get('plugin.staticbuilder.assets', static::$defaultAssets);
		$assets = [];
		foreach ($assetConf as $a) {
			if (is_string($a)) $assets[$a] = $a;
			elseif (is_array($a) and count($a) > 1)
				$assets[array_shift($a)] = array_shift($a);
		}
		$this->assets = $assets;
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
	 * @return string
	 */
	protected function normalizePath($path) {
		$path = preg_replace('/[\\/\\\]+/', DS, $path);
		$out = [];
		foreach (explode(DS, $path) as $i => $fold) {
			if ($fold == '..' && $i > 0 && end($out) != '..') array_pop($out);
			$fold = preg_replace('/\.{2,}/', '.', $fold);
			if ($fold == '' || $fold == '.') continue;
			else $out[] = $fold;
		}
		return ($path[0] == DS ? DS : '') . join(DS, $out);
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
		// Be careful to use strict comparison (false == 0 is true)
		return strpos($absolutePath, $this->folder . DS) === 0;
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
			'dest'   => 'static/',
			'size'   => null,
			// Specific to pages
			'title'  => $page->title()->value,
			'uri'    => $page->uri(),
			'files'  => [],
		];
		$folder = $this->normalizePath( $this->folder . DS . $page->uri() );
		$file   = $page->isHomePage() ? 'index.html' : $page->uri() . DS . $this->suffix;
		$target = $this->normalizePath( $this->folder . DS . $file);
		$log['dest'] .= str_replace($this->folder . DS, '', $target);

		// Check if we will build this page and report why not
		if ($this->filterPage($page) == false) {
			$log['status'] = 'ignore';
			if ($this->filter == null) $log['reason'] = 'Page has no text file';
			else $log['reason'] = 'Excluded by custom filter';
			return $this->summary[] = $log;
		}
		// This one may happen if the page file suffix tries to go up the dir structure
		elseif ($this->filterPath($target) == false) {
			$log['status'] = 'ignore';
			$log['reason'] = 'Output path for page goes outside of static directory';
		}

		// Not writing
		if ($write == false) {
			// Get status of output path
			if (is_file($target)) {
				$outdated = filemtime($target) < $page->modified();
				$log['status'] = $outdated ? 'outdated' : 'uptodate';
				$log['size'] = filesize($target);
			}
			else {
				$log['status'] = 'missing';
			}
			// Get number of files
			$log['files'] = $page->files()->count();
		}
		else {
			// Store reference to this page in case there's a fatal error
			$this->lastpage = $log['source'];
			
			// Mark page as active
			$this->kirby->site()->visit($page->uri());

			// FIXME: controller only runs once?
			// For instance if I have a posts.php controller, its results
			// seems to be cached and reused for all other pages of the
			// same type. Building a single page doesn't show this problem.

			// Tried calling the controller every time but it doesn't seem to work?
			/*
			$controller = $this->kirby->get('controller', $page->template());
			$data = [];
			if (is_callable($controller)) {
				$data = call_user_func($controller,
					$this->kirby->site(),
					$this->kirby->site()->children(),
					$page);
				if (!is_array($data)) $data = [];
			}
			*/

			// Render page
			$text = $this->rewriteUrls($this->kirby->render($page, [], false), $page->url());

			// Write page content
			f::write($target, $text);
			$log['status'] = 'generated';
			$log['size'] = strlen($text);

			// Copy page files in a folder
			foreach ($page->files() as $file) {
				$filedest = $folder . DS . $file->filename();
				$file->copy($filedest);
				$log['files'][] = 'static/' . str_replace($this->folder . DS, '', $filedest);
			}
		}
		$this->notifyCallback($log);
		return $this->summary[] = $log;
	}

	protected function buildRoute($uri, $write=false) {
		if (!is_string($uri)) {
			return false;
		}
		$log = [
			'type'   => 'route',
			'status' => '',
			'reason' => '',
			'source' => $uri,
			'dest'   => 'static/',
			'size'   => null,
		];
		$target = $this->normalizePath( $this->folder . DS . $uri . (substr($uri, -1) == '/' ? $this->suffix : ''));
		$log['dest'] .= str_replace($this->folder . DS, '', $target);

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
		$this->notifyCallback($log);
		return $this->summary[] = $log;
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
			$source = $this->normalizePath($this->index . DS . $from);
		}

		// But target is always relative to static dir
		$target = $this->normalizePath($this->folder . DS . $to);
		if ($this->filterPath($target) == false) {
			$log['status'] = 'ignore';
			$log['reason'] = 'Cannot copy asset outside of the static folder';
			return $this->summary[] = $log;
		}
		$log['dest'] .= str_replace($this->folder . DS, '', $target);

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

		$this->notifyCallback($log);
		return $this->summary[] = $log;
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

	protected function rewriteUrls($text, $relativeTo = '') {
		$quotedPlaceholder = preg_quote($this->urlPlaceholder, '~');
		$relativeTo = preg_replace("~^$quotedPlaceholder/?~", '', $relativeTo);
		return preg_replace_callback(
			"~$quotedPlaceholder/?([^\"'{}\s]*)~",
			function ($m) use($relativeTo) {
				if ($this->urlbase === false || $this->urlbase == 'file://') {
					$path = $this->relativePath($relativeTo, $m[1]);
					// Strip first ../ when relative to root
					if ($relativeTo === '' && strpos($path, '../') === 0) {
						$path = substr($path, 3);
					}
					// Prepend with ./ to clarify the relativity
					if (substr($path, 0, 3) != '../') {
						$path = "./$path";
					}
					// Append trailing /index.htm if extension is missing
					if ($this->urlbase == 'file://') {
						$basename = basename($path);
						if ($basename == '..' || $basename == '.' || strpos($basename, '.') === false) {
							$path = rtrim($path, '/') . ($path == '/' ? '/index.html' : $this->suffix);
						}
					}
					return $path;
				} else {
					return $this->urlbase . '/' . $m[1];
				}
			},
			$text
		);
	}

	protected function notifyCallback($item) {
		$this->itemCallback && call($this->itemCallback, [$item]);
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
	 * Build or rebuild static content
	 * @param Page|Pages|Site $content Content to write to the static folder
	 * @param boolean $write Build pages - set to false to dry-run
	 * @return array
	 */
	public function run($content, $write=true) {
		$this->summary = [];

		// Temporarily override URL config
		$originalUrlConfig = get_object_vars($this->kirby->urls());
		$index = $this->kirby->urls()->index();
		$escapedIndex = ($index == '/') ? '' : preg_quote($index, '~');
		foreach ($originalUrlConfig as $key => $value) {
			$this->kirby->urls()->$key = preg_replace(
				"~^(https?://)?$escapedIndex~",
				$this->urlPlaceholder,
				$value
			);
		}
		url::$home = $this->kirby->site->url = $this->kirby->urls()->index = $this->urlPlaceholder;

		if ($write) {
			// Kill PHP Error reporting when building pages, to "catch" PHP errors
			// from the pages or their controllers (and plugins etc.). We're going
			// to try to hande it ourselves
			$level = error_reporting();
			$catchErrors = c::get('plugin.staticbuilder.catcherrors', false);
			if ($catchErrors) {
				$this->shutdown = function () { $this->showFatalError(); };
				register_shutdown_function($this->shutdown);
				error_reporting(0);
			}
		}

		if ($content instanceof Site) {
			foreach ($this->assets as $from=>$to) {
				$this->copyAsset($from, $to, $write);
			}
			foreach ($this->routes as $route) {
				$this->buildRoute($route, $write);
			}
		}
		foreach($this->getPages($content) as $page) {
			$this->buildPage($page, $write);
		}

		// Restore error reporting if building pages worked
		if ($write && $catchErrors) {
			error_reporting($level);
			$this->shutdown = function () {};
		}

		// Restore overridden URL config
		unset($this->kirby->urls()->index);
		foreach ($originalUrlConfig as $key => $value) {
			$this->kirby->urls()->$key = $value;
		}
		$this->kirby->site->url = $this->kirby->urls()->index();
	}

	/**
	 * Render the HTML report page
	 *
	 * @param array $data
	 * @return Response
	 */
	public function htmlReport($data) {
		// Forcefully remove headers that might have been set by some
		// templates, controllers or plugins when rendering pages.
		header_remove();
		$body = tpl::load(__DIR__ . DS . '..' . DS . 'templates' . DS . 'html.php', $data);
		return new Response($body, 'html', $data['error'] ? 500 : 200);
	}

}
