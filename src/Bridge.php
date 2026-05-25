<?php

namespace classicframework\scheduler;

use classicframework\core\App;
use classicframework\core\Config;
use classicframework\core\BridgeInterface;

class Bridge implements BridgeInterface
{
  public static function register(App $app)
  {
    $config = Config::extract('scheduler');
    $console = $app->get_service('console');

    $scheduler = new Scheduler($app, $config, $console);

    $app->set_service('scheduler', $scheduler);
  }
}