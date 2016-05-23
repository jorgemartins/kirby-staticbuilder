#!/usr/bin/env php
<?php
/*
 * CLI version of plugin
 *
 * Example usage:
 * php site/plugins/staticbuilder/cli.php # build everything
 * php site/plugins/staticbuilder/cli.php home error # build 'home' and 'error' pages
 */

namespace Kirby\Plugin\StaticBuilder;

use C;
use F;
use Router;
use Pages;

$ds = DIRECTORY_SEPARATOR;

// Parse options (--option[=value]) and create an array with positional arguments
$opts = [
	'kirby' => getcwd() . $ds . 'kirby',
	'site' => getcwd() . $ds . 'site.php',
	'output' => false, // Use builder default
	'base' => false, // Use builder default
	'json' => false,
	'quiet' => false,
	'help' => false,
];
$args = array_filter(array_slice($argv, 1), function($arg) use (&$opts) {
	if (substr($arg, 0, 2) === '--') {
		$parts = explode('=', substr($arg, 2));
		$opt = $parts[0];
		if (!isset($opts[$opt])) {
			echo "Error: unknown option '$opt'\n";
			exit(1);
		}
		$opts[$opt] = isset($parts[1]) ? $parts[1] : true;
		return false;
	}
	return true;
});

$command = array_shift($args);

// Allow false to be specified as base URL
if ($opts['base'] == 'false') $opts['base'] = false;

if (!empty($opts['output'])) {
	// Ensure destination ends with '/static'
	if (basename($opts['output']) != 'static') $opts['output'] .= '/static';
	// Convert destination to absolute path (via CWD)
	if (substr($opts['output'], 0, 1) != '/') $opts['output'] = getcwd() . $ds . $opts['output'];
}

// Supress log if outputting JSON
if ($opts['json']) $opts['quiet'] = true;


// Show usage if not required arguments aren't provided
if (is_null($command) || $command == 'help' || $opts['help']) {
	echo <<<EOF
usage: {$argv[0]} [options...] <command> [pages...]

Available commands:
	build             Build entire site (or specific pages)
	list              List items that would be built but don't write anything
	help              Display this help text

Options:
	[pages...]        Build the specified pages instead of the entire site
	--kirby=kirby     Directory where bootstrap.php is located
	--site=site.php   Path to kirby site.php config, specify 'false' to disable
	--output=         Output directory
	--base=           Base URL prefix
	--json            Output data and outcome for each item as JSON
	--quiet           Suppress output

EOF;
	exit(1);
}

// Ensure dependencies exist
$bootstrapPath = "{$opts['kirby']}{$ds}bootstrap.php";
if (!file_exists($bootstrapPath)) {
	echo "bootstrap.php not found in '{$opts['kirby']}'.\n";
	echo "You can override the default location using --kirby=path/to/kirby-dir\n";
	exit(1);
} else {
	require_once($bootstrapPath);
}
if ($opts['site'] === 'false') {
	// Don't load site.php
} else if (!file_exists($opts['site'])) {
	echo "site.php not found at '{$opts['site']}'.\n";
	echo "You can override the default location using --site=path/to/site.php\n";
	exit(1);
} else {
	require_once($opts['site']);
}

$log = function($msg) use ($opts) {
	if (!$opts['quiet']) {
		echo "* $msg\n";
	}
};

$startTime = microtime(true);

// Bootstrap Kirby
$kirby = kirby();
date_default_timezone_set($kirby->options['timezone']);
$kirby->site();
$kirby->extensions();
$kirby->plugins();
$kirby->models();
$kirby->router = new Router($kirby->routes());

// Override options?
if ($opts['base']) c::set('plugin.staticbuilder.baseurl', $opts['base']);
if ($opts['output']) c::set('plugin.staticbuilder.outputdir', $opts['output']);

$log("Base URL: '" . c::get('plugin.staticbuilder.baseurl') . "'");
$log("Output directory: " . c::get('plugin.staticbuilder.outputdir'));

require_once('core/builder.php');
$builder = new Builder();

// Store results and track stats
$results = [];
if ($command == 'list') {
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
$builder->onLog(function($item) use (&$results, &$stats, &$opts) {
	$results[] = $item;
	if ($item['status'] === '') $item['status'] = 'n/a';
	$stats[$item['status']] = isset($stats[$item['status']]) ? $stats[$item['status']] + 1 : 1;

	if (!$opts['quiet']) {
		$files = isset($item['files']) ? (', ' . count($item['files']) . ' files') : null;
		$size = r(is_int($item['size']), '(' . f::niceSize($item['size']) . "$files)");
		$id = isset($item['uri']) ? $item['uri'] : $item['source'];
		echo "[{$item['status']}] {$item['type']} - {$id} $size\n";
	}
});

// Build each target and combine summaries
$targets = count($args) > 0 ? new Pages($args) : site();
$builder->run($targets, $command == 'build');

if (!$opts['quiet']) {
	$line = [];
	foreach ($stats as $state => $count) {
		$line[] = "$state: $count";
	}
	$log('Results: ' . join(', ', $line));
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
