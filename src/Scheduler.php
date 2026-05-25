<?php

namespace classicframework\scheduler;

use classicframework\core\App;

class Scheduler
{
  protected $app = null;
  protected $config = array();
  protected $console = null;

  public function __construct(App $app, $config = array(), $console = null)
  {
    $this->app = $app;
    $this->config = is_array($config) ? $config : array();
    $this->console = $console;
  }

  public function run()
  {
    $tasks = $this->tasks();

    $result = array(
      'executed' => array(),
      'skipped' => array(),
      'failed' => array(),
    );

    foreach ($tasks as $task) {
      $name = isset($task['name']) ? (string) $task['name'] : '';
      $command = isset($task['command']) ? (string) $task['command'] : '';

      if ($name === '' || $command === '') {
        continue;
      }

      if (!$this->is_due($task)) {
        $result['skipped'][] = $name;
        continue;
      }

      if (!$this->lock($name)) {
        $result['skipped'][] = $name;
        continue;
      }

      register_shutdown_function(array($this, 'unlock'), $name);

      try {
        $code = $this->run_command($task);

        if ($code === 0 && $this->save_last_run($name)) {
          $result['executed'][] = $name;
        } else {
          $result['failed'][] = $name;
        }
      } catch (\Exception $e) {
        $result['failed'][] = $name;
      }

      $this->unlock($name);
    }

    return $result;
  }

  protected function tasks()
  {
    return isset($this->config['tasks']) && is_array($this->config['tasks'])
      ? $this->config['tasks']
      : array();
  }

  protected function is_due($task)
  {
    $name = (string) $task['name'];
    $every = isset($task['every']) ? (int) $task['every'] : 60;

    if ($every < 1) {
      $every = 60;
    }

    $last_run = $this->last_run($name);

    return $last_run + $every <= time();
  }

  protected function run_command($task)
  {
    if (!is_object($this->console) || !method_exists($this->console, 'run')) {
      throw new \Exception('Console service is missing.');
    }

    $command = isset($task['command']) ? (string) $task['command'] : '';
    $args = isset($task['args']) && is_array($task['args']) ? $task['args'] : array();

    $argv = array_merge(array('console', $command), $args);

    return (int) $this->console->run($argv);
  }

  protected function last_run($name)
  {
    $file = $this->state_path() . '/' . $this->safe_name($name) . '.last';

    if (!is_file($file)) {
      return 0;
    }

    return (int) trim((string) file_get_contents($file));
  }

  protected function save_last_run($name)
  {
    $file = $this->state_path() . '/' . $this->safe_name($name) . '.last';

    $result = @file_put_contents($file, (string) time());

    if ($result === false) {
      return false;
    }

    return true;
  }

  protected function lock($name)
  {
    $path = $this->lock_path($name);

    if (is_dir($path)) {
      $max_age = isset($this->config['lock_timeout']) ? (int) $this->config['lock_timeout'] : 3600;
      $modified = filemtime($path);

      // if ($modified !== false && $modified + $max_age < time()) {
      if ($modified === false || $modified + $max_age < time()) {
        @rmdir($path);
      } else {
        return false;
      }
    }

    clearstatcache();
    return @mkdir($path, 0777, true);
  }

  public function unlock($name)
  {
    $path = $this->lock_path($name);

    if (is_dir($path)) {
      @rmdir($path);
    }

    return true;
  }

  protected function lock_path($name)
  {
    return $this->state_path() . '/' . $this->safe_name($name) . '.lock';
  }

  protected function state_path()
  {
    $path = isset($this->config['state_path']) && (string) $this->config['state_path'] !== ''
      ? (string) $this->config['state_path']
      : APP_PATH . '/tmp/scheduler';

    if (!is_dir($path)) {
      if (!@mkdir($path, 0777, true)) {
        throw new \Exception('Scheduler state path not writable: ' . $path);
      }
    }

    return rtrim($path, '/\\');
  }

  protected function safe_name($name)
  {
    $name = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string) $name);
    return strtolower(trim($name, '_'));
  }
}