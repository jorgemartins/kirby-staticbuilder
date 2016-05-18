#!/usr/bin/env php
<?php

namespace Kirby\Plugin\StaticBuilder;

// Parse options (--option) and create an array with positional arguments
$options = [
  'dry-run' => false,
];
$args = array_filter(array_slice($argv, 1), function($arg) {
  if (substr($arg, 0, 2) === '--' && isset($options[substr($arg, 2)])) {
    $options[substr($arg, 2)] = true;
    return false;
  }
  return true;
});

if (count($args) < 3) {
  echo <<<EOF
usage: {$argv[0]} [--dry-run] kirby-root site.php [pages...]

* kirby-root   Directory where bootstrap.php is located
* site.php     Path to kirby site config
* [pages...]   Build the specified pages instead of the entire site

EOF;
  exit(1);
}
list($kirbyRoot, $sitePath) = $args;
$targets = array_slice($args, 2);

$ds = DIRECTORY_SEPARATOR;
require("{$kirbyRoot}{$ds}bootstrap.php");
require($sitePath);
require('core/builder.php');

$builder = new Builder();

if (count($targets) > 0) {
  $targets = array_map('page', $targets);
} else {
  $targets = [site()];
}

$results = [];
$method = $options['dry-run'] ? 'dryrun' : 'write';
foreach ($targets as $target) {
  $builder->$method($target);
  $results = array_merge($results, $builder->summary);
} 

// $stats = [];
// foreach ($builder->summary as $row) {
  // $stats[$row['status']] = isset($stats[$row['status']]) ? $stats[$row['status']] + 1 : 1;
// }

echo json_encode($results, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

// use Kirby;

