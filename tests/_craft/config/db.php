<?php

use craft\helpers\App;

// Points at db_test, a database created solely for the suite. This matters: Craft's test
// framework runs with dbSetup.clean, which drops every table in whatever database it is
// given. Aiming it at the development database would destroy the fixtures.
return [
    'dsn' => App::env('CRAFT_TEST_DB_DSN'),
    'user' => App::env('CRAFT_TEST_DB_USER'),
    'password' => App::env('CRAFT_TEST_DB_PASSWORD'),
    'tablePrefix' => App::env('CRAFT_TEST_DB_TABLE_PREFIX') ?: '',
];
