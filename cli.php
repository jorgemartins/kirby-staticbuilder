#!/usr/bin/env php
<?php
// vim: et:ts=4:sts=4:sw=4
/*
 * CLI version of plugin
 *
 * Example usage:
 * php site/plugins/staticbuilder/cli.php # build everything
 * php site/plugins/staticbuilder/cli.php home error # build 'home' and 'error' pages
 */

namespace Kirby\StaticBuilder;

use C;
use F;
use Router;
use Pages;
use URL;

$ds = DIRECTORY_SEPARATOR;

// Mapping of CLI option names to Builder config keys
$builderOptsMap = [
    'assets' => 'assets',
    'base' => 'baseurl',
    'extension' => 'extension',
    'files' => 'withfiles',
    'redirects' => 'withredirects',
    'output' => 'outputdir',
    'routes' => 'routes',
];

// Option defaults
$opts = [
    'kirby' => getcwd() . $ds . 'kirby',
    'site' => getcwd() . $ds . 'site.php',
    'assets' => false,
    'base' => false,
    'extension' => false,
    'files' => false,
    'help' => false,
    'json' => false,
    'output' => false,
    'quiet' => false,
    'redirects' => false,
    'routes' => false,
];

// Parse options (--option[=value]) and create an array with positional arguments
// --option enables an option, --option=false disables it & any other value is set as-is
$args = array_filter(array_slice($argv, 1), function($arg) use (&$opts) {
    if (substr($arg, 0, 2) === '--') {
        $parts = explode('=', substr($arg, 2));
        $opt = $parts[0];
        if (!isset($opts[$opt])) {
            echo "Error: unknown option '$opt'\n";
            exit(2);
        }

        $value = isset($parts[1]) ? $parts[1] : true;
        if ($value === true && strpos($arg, '=') !== false) $value = '';
        else if ($value === 'false') $value = false;

        $opts[$opt] = $value;

        return false;
    }
    return true;
});

$command = array_shift($args);

// Show usage if not required arguments aren't provided
if (is_null($command) || $command == 'help' || $opts['help']) {
    echo <<<EOF
usage: {$argv[0]} [options...] <command> [pages...]

Available commands:
    build             Build entire site (or specific pages)
    list              List items that would be built but don't write anything
    help              Display this help text

Options:
    [pages...]        Space separated list of pages to build, entire site if omitted
    --kirby=kirby     Directory where bootstrap.php is located
    --site=site.php   Path to kirby site.php config, specify 'false' to disable
    --output=static   Output directory
    --base=/          Base URL prefix
    --extension=/index.html   Filename (suffix if extension) for built pages
  --routes="*"      Comma-separated list of routes to build (syntax matches staticbuilder.routes)
    --assets="c,s,v"  Comma-separated list of assets to copy to output directory
    --files           Copy page files to output directory
    --redirects       Enable redirect definitions for Apache & nginx
    --json[=x.json]   Output data and outcome for each item as JSON, to stdout or file
    --quiet           Suppress output

EOF;
    exit(1);
}

if (!empty($opts['output'])) {
    // Convert destination to absolute path (via CWD)
    if (substr($opts['output'], 0, 1) != '/') $opts['output'] = getcwd() . $ds . $opts['output'];
}

// Convert CSV values to arrays
if ($opts['routes'] !== false) {
    $opts['routes'] = explode(',', $opts['routes']);
}
if ($opts['assets'] !== false) {
    $opts['assets'] = explode(',', $opts['assets']);
}

// Supress log if outputting JSON
if ($opts['json'] === true) $opts['quiet'] = true;

// Ensure dependencies exist
$bootstrapPath = "{$opts['kirby']}{$ds}bootstrap.php";
if (!file_exists($bootstrapPath)) {
    echo "bootstrap.php not found in '{$opts['kirby']}'.\n";
    echo "You can override the default location using --kirby=path/to/kirby-dir\n";
    exit(3);
}
if ($opts['site'] === false) {
    // Don't load site.php
} else if (!file_exists($opts['site'])) {
    echo "site.php not found at '{$opts['site']}'.\n";
    echo "You can override the default location using --site=path/to/site.php\n";
    exit(4);
}

$log = function($msg) use ($opts) {
    if (!$opts['quiet']) {
        echo "* $msg\n";
    }
};

$startTime = microtime(true);

// Set up virtual server environment (read by Kirby)
// $_SERVER['SERVER_NAME'] = $_ENV['SERVER_NAME'];
// $_SERVER['SERVER_PORT'] = $_ENV['SERVER_PORT'];

// Bootstrap Kirby
require_once($bootstrapPath);
url::$home = rtrim($opts['base'], '/');
require_once($opts['site']);

$kirby = kirby();
date_default_timezone_set($kirby->options['timezone']);
$kirby->site();
$kirby->extensions();
$kirby->plugins();
$kirby->models();
$kirby->router = new Router($kirby->routes());

// Override options?
foreach ($builderOptsMap as $optKey => $configKey) {
    if ($opts[$optKey] !== false) {
        $kirby->set('option', "staticbuilder.$configKey", $opts[$optKey]);
    }
}

// die(server::get('SERVER_NAME', server::get('SERVER_ADDR')));
// die(url::base());
// die(kirby()->urls()->content());
// die(image('home/remote.jpg')->url());

require_once('src/Builder.php');
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
        $files = $opts['files'] && isset($item['files']) ? (', ' . (is_array($item['files']) ? count($item['files']) : $item['files']) . ' files') : null;
        $size = r(is_int($item['size']), '(' . f::niceSize($item['size']) . "$files)");
        $id = isset($item['uri']) ? $item['uri'] : $item['source'];
        echo "[{$item['status']}] {$item['type']} - {$id} $size\n";
    }
});

// Error handler
$builder->shutdown = function() use (&$builder) {
    $error = error_get_last();
    $page = $builder->lastpage;
    if (!$error || $error['type'] === E_NOTICE) return;
    echo "\n\n[error] while generating $page\n{$error['message']}\n@ {$error['file']}:{$error['line']}\n";
};

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

$executionTime = microtime(true) - $startTime;
$log("Finished in: $executionTime s");
$log("Peak memory usage: ". f::niceSize(memory_get_peak_usage()));

if ($opts['json']) {
    $report = json_encode($results, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if (is_string($opts['json'])) {
        file_put_contents($opts['json'], $report);
        $log("Saved JSON report to '{$opts['json']}'.");
    } else {
        echo $report;
    }
}

// Exit with error code if not successful
if (isset($stats['missing'])) {
    exit(8);
}
