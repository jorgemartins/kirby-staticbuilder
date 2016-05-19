#!/usr/bin/env php
<?php
/*
 * CLI version of plugin
 *
 * Example usage:
 * php build.php ../kirby ../site.php # build everything
 * php build.php ../kirby ../site.php home error # build 'home' and 'error' pages
 */

namespace Kirby\Plugin\StaticBuilder;

use F;
use Router;

// Parse options (--option) and create an array with positional arguments
$opts = [
	'dry-run' => false,
	'json' => false,
	'quiet' => false,
];
$args = array_filter(array_slice($argv, 1), function($arg) use (&$opts) {
	if (substr($arg, 0, 2) === '--') {
		$opt = substr($arg, 2);
		if (!isset($opts[$opt])) {
			echo "Error: unknown option '$opt'\n";
			exit(1);
		}
		$opts[$opt] = true;
		return false;
	}
	return true;
});

if ($opts['json']) $opts['quiet'] = true;

// Show usage if not required arguments aren't provided
if (count($args) < 2) {
	echo <<<EOF
usage: {$argv[0]} [--dry-run] [--quiet] [--json] kirby-root site.php [pages...]

* kirby-root   Directory where bootstrap.php is located
* site.php     Path to kirby site config
* [pages...]   Build the specified pages instead of the entire site

EOF;
	exit(1);
}

list($kirbyRoot, $sitePath) = $args;
$targets = array_slice($args, 2);

$log = function($msg) use ($opts) {
	if (!$opts['quiet']) {
		echo "* $msg\n";
	}
};

$startTime = microtime(true);

// Bootstrap Kirby
$ds = DIRECTORY_SEPARATOR;
require("{$kirbyRoot}{$ds}bootstrap.php");
require($sitePath);
date_default_timezone_set($kirby->options['timezone']);
$kirby->site();
$kirby->extensions();
$kirby->plugins();
$kirby->models();
$kirby->router = new Router($kirby->routes());

$builder = new Builder();

// Determine targets to build
if (count($targets) > 0) {
	$targets = array_map('page', $targets);
} else {
	$targets = [site()];
}

// Store results and track stats
$results = [];
if ($opts['dry-run']) {
	$stats = [
		'outdated' => 0,
		'uptodate' => 0,
	];
} else {
	$stats = [
		'generated' => 0,
		'done' => 0,
	];
}

// Register result callback
$builder->itemCallback = function($item) use (&$results, &$stats, &$opts) {
	$results[] = $item;
	if ($item['status'] === '') $item['status'] = 'n/a';
	$stats[$item['status']] = isset($stats[$item['status']]) ? $stats[$item['status']] + 1 : 1;

	if (!$opts['quiet']) {
		$files = isset($item['files']) ? (', ' . count($item['files']) . ' files') : null;
		$size = r(is_int($item['size']), '(' . f::niceSize($item['size']) . "$files)");
		echo "[{$item['status']}] {$item['type']} - {$item['source']} $size\n";
	}
};

// Build each target and combine summaries
foreach ($targets as $target) {
	$builder->run($target, !$opts['dry-run']);
}

if (!$opts['quiet']) {
	$line = [];
	foreach ($stats as $state => $count) {
		$line[] = "$state: $count";
	}
	$log("Results: " . join(', ', $line));
}

if ($opts['json']) {
	echo json_encode($results, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
}

$executionTime = microtime(true) - $startTime;
$log("Finished in $executionTime s");

// Exit with error code if not successful
if (isset($stats['missing'])) {
	exit(2);
}
