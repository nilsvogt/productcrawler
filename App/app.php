#!/usr/bin/env php
<?php

namespace App;

use App\Command\CrawlProductsCommand;

require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Console\Application;

chdir(__DIR__ . '/..');

$application = new Application();

$application->add(new CrawlProductsCommand());

$application->run();
