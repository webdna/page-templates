<?php

// Load the project's autoloader rather than a plugin-local one: the plugin is installed
// through a Composer path repository, so the root autoloader already maps
// webdna\pagetemplates\ to plugins/page-templates/src.
require dirname(__DIR__, 3) . '/vendor/autoload.php';
