<?php

use craft\test\TestSetup;

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

// The plugin is installed through a Composer path repository, so the project's autoloader
// already maps webdna\pagetemplates\ to plugins/page-templates/src. Load that rather than a
// plugin-local vendor/, which would mean a duplicate copy of Craft.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Craft's test framework needs these before it boots. The example scaffold assumes tests/ and
// vendor/ are siblings; here tests/ is three levels below the project root, hence the depth.
const CRAFT_TESTS_PATH = __DIR__;
// Not in the example scaffold, but configureCraft() dereferences it: it becomes the @root alias.
const CRAFT_ROOT_PATH = __DIR__ . '/_craft';
const CRAFT_STORAGE_PATH = __DIR__ . '/_craft/storage';
const CRAFT_TEMPLATES_PATH = __DIR__ . '/_craft/templates';
const CRAFT_CONFIG_PATH = __DIR__ . '/_craft/config';
const CRAFT_MIGRATIONS_PATH = __DIR__ . '/_craft/migrations';
const CRAFT_TRANSLATIONS_PATH = __DIR__ . '/_craft/translations';
define('CRAFT_VENDOR_PATH', dirname(__DIR__, 3) . '/vendor');

TestSetup::configureCraft();
