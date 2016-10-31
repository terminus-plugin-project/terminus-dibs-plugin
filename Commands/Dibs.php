<?php

namespace Terminus\Commands;

use Terminus\Collections\Environments;
use Terminus\Collections\Sites;
use Terminus\Exceptions\TerminusException;

/**
 * Class SiteDibsCommand
 * @command site
 */
class SiteDibsCommand extends TerminusCommand {

  protected $sites;
  protected $info;

  private $version = '0.1.0';
  private $compatible_terminus_version = '0.13.4';

  /**
   * Create a new site which duplicates the environments, code and content of an existing Pantheon site.
   *
   * @param array $options Options to construct the command object
   */
  public function __construct(array $options = []) {
    $options['require_login'] = TRUE;
    parent::__construct($options);

    date_default_timezone_set('UTC');
    $this->command_start_time = time();

    $this->sites = new Sites();
  }

  /**
   * Call dibs on a site environment.
   *
   * [--version]
   * : Show plugin version and compatible Terminus version.
   *
   * [--site=<site>]
   * : Name of the site for which you want to dibs an environment. (Required)
   *
   * [--env=<env>]
   * : The specific environment you would like to dibs if you know which environment you want ahead of time. (Optional)
   *
   * [--filter=<regex>]
   * : A regex pattern used to filter the pool of environments you are willing to dibs. Defaults to anything but live. (Optional)
   *
   * @subcommand dibs
   *
   * @param array $args Array of main arguments
   * @param array $assoc_args Array of associative arguments
   * @return null
   * @throws \Terminus\Exceptions\TerminusException
   */
  public function envDibs($args, $assoc_args) {
    // Show version info if necessary.
    if (isset($assoc_args['version'])) {
      $this->showVersion();
      return TRUE;
    }

    // Check required arguments.
    if (!isset($assoc_args['site'])) {
      throw new TerminusException('You must specify a site name via the --site flag.', [], 1);
    }

    // Set up defaults.
    $env = isset($assoc_args['env']) ? $assoc_args['env'] : NULL;
    $filter = isset($assoc_args['filter']) ? $assoc_args['filter'] : '^((?!^live$).)*$';

    // If no environment was provided, try to pick an environment.
    if (empty($env)) {
      $site = $this->sites->get($this->input()->siteName(['args' => $assoc_args]));
      $env = $this->getEnvToDib($site, $filter);

      // If we found one, great! Set it up.
      if (!empty($env)) {
        $assoc_args['env'] = $env;
      }
      // If we still don't have an environment, then none can be dibs'd.
      else {
        throw new TerminusException('Unable to find an environment to dibs.', [], 1);
      }
    }

    // Call dibs.
    $this->output()->outputValue($this->callDibs($assoc_args), 'Called Dibs On');
  }

  /**
   * Un-dibs a site environment.
   *
   * [--site=<site>]
   * : Name of the site for which you want to dibs an environment. (Required)
   *
   * [--env=<env>]
   * : The specific environment you would like to dibs if you know which environment you want ahead of time. (Required)
   *
   * @subcommand undibs
   *
   * @param array $args Array of main arguments
   * @param array $assoc_args Array of associative arguments
   * @return null
   * @throws \Terminus\Exceptions\TerminusException
   */
  public function envUndibs($args, $assoc_args) {
    // Check required arguments.
    if (!isset($assoc_args['site']) || !isset($assoc_args['env'])) {
      throw new TerminusException('You must specify a site name (--site) and environment (--env).', [], 1);
    }

    // Undibs the environment by invoking takesies-backsies.
    $this->output()->outputValue($this->takesiesBacksies($assoc_args), "Undibs'd");
  }

  /**
   * Attempts to call dibs given an array of arguments containing a site name
   * and environment name.
   *
   * @param array $assoc_args
   *
   * @return string
   *   Returns the name of the dibs'd environment, if successful.
   *
   * @throws \Terminus\Exceptions\TerminusException
   */
  protected function callDibs($assoc_args) {
    $site = $this->sites->get($this->input()->siteName(['args' => $assoc_args]));

    // Make sure no one's already called dibs on this site.
    if ($who = $this->someoneAlreadyCalledDibsOn($site, $assoc_args['env'])) {
      throw new TerminusException('{Who} already called dibs on {env}.', [
        'Who' => $who === exec('whoami') ? 'You' : $who,
        'env' => $assoc_args['env'],
      ], 1);
    }

    // Prepare to call dibs.
    $sftpCommand = $this->getSftpCommand($assoc_args);
    $workdir = $this->getTempDir();
    chdir($workdir);

    // First, write a dibs file with some metadata.
    $dibs = ['by' => exec('whoami'), 'at' => time(), 'for' => $assoc_args['env']];
    file_put_contents('__dibs.json', json_encode($dibs));

    // Upload __dibs.json, if possible
    exec("(echo 'cd files' && echo 'put __dibs.json') | $sftpCommand 2> /dev/null", $output, $status);

    // If we succeeded, return the environment name.
    if ($status === 0) {
      return $assoc_args['env'];
    }
    else {
      throw new TerminusException('There was a problem calling dibs on {env}. Last message: {message}', [
        'env' => $assoc_args['env'],
        'message' => array_pop($output),
      ], 1);
    }
  }

  /**
   * Attempts to remove the dibs file from the given site/env.
   *
   * @param array $assoc_args
   * @return string
   *   Returns the name of the undibs'd environment if successful.
   *
   * @throws \Terminus\Exceptions\TerminusException
   */
  protected function takesiesBacksies($assoc_args) {
    $sftpCommand = $this->getSftpCommand($assoc_args);
    exec("(echo 'cd files' && echo 'rm __dibs.json') | $sftpCommand 2> /dev/null", $output, $status);
    if ($status === 0) {
      return $assoc_args['env'];
    }
    else {
      throw new TerminusException("There was a problem undibs'ing {env}. Last message: {message}", [
        'env' => $assoc_args['env'],
        'message' => array_pop($output),
      ], 1);
    }
  }

  /**
   * Returns an environment to call dibs on, given a regex filter.
   *
   * @param Terminus\Models\Site $site
   * @param string $regex
   *
   * @return string|NULL
   *   The as-yet undib'd environment, or NULL if all environments have already
   *   been spoken-for.
   */
  protected function getEnvToDib($site, $regex) {
    $environments = new Environments(['site' => $site]);
    $matches = preg_grep('/' . $regex . '/', $environments->ids());
    foreach ($matches as $key => $env) {
      // If someone already dibs'd it, we can't dibs it. Them's the rules.
      if ($this->someoneAlreadyCalledDibsOn($site, $env)) {
        unset($matches[$key]);
      }
    }
    return array_pop($matches);
  }

  /**
   * Attempts to return the public file directory for the given site.
   *
   * @param Terminus\Models\Site $site
   *
   * @return string
   *   A slash-prefixed path representing the site's public directory.
   */
  protected function getEnvPublicDirectoryFor($site) {
    if ($site->get('framework') === 'wordpress') {
      return '/wp-content/uploads';
    }
    else {
      return '/sites/default/files';
    }
  }

  /**
   * Outputs plugin information for debugging/support.
   */
  protected function showVersion() {
    $labels = [
      'version' => 'Plugin (dibs) Version',
      'terminus_version' => 'Compatible Terminus Version',
    ];
    $this->output()->outputRecord([
      'version' => $this->version,
      'terminus_version' => $this->compatible_terminus_version,
    ], $labels);
  }

  /**
   * Returns the user who called dibs on a site/env.
   *
   * @param Terminus\Models\Site $site
   *   The site to check.
   * @param string $env
   *   The env to check.
   *
   * @return string
   *   Returns an empty string of dibs hasn't been called on the given site/env.
   *   If someone HAS called dibs, it will return the user who did.
   */
  protected function someoneAlreadyCalledDibsOn($site, $env) {
    $pubDir = $this->getEnvPublicDirectoryFor($site);
    $url = 'http://' . $env . '-' .$site->get('name');
    $url .= '.pantheonsite.io' . $pubDir . '/__dibs.json';
    $url .= '?cb=' . mt_rand();
    $dibsFile = @file_get_contents($url);

    // If there's no dibs file, it hasn't been dibs'd.
    if (empty($dibsFile)) {
      return '';
    }
    // Otherwise, make sure the dibs file matches the env name. If there's a
    // mismatch, then it's possible someone cloned from a dibs'd environment.
    else {
      $dibsFile = json_decode($dibsFile);
      return $dibsFile->for === $env ? $dibsFile->by : '';
    }
  }

  /**
   * Returns the SFTP command to connect to a given site/env.
   *
   * @param array $assoc_args
   *
   * @return string
   */
  protected function getSftpCommand($assoc_args) {
    $info = $this->getSiteInfo($assoc_args);
    return $info['sftp_command'];
  }

  /**
   * Returns site information for a given site/env.
   *
   * @param array $assoc_args
   *
   * @return array
   *   An associative array of site information.
   */
  protected function getSiteInfo($assoc_args) {
    if (!isset($this->info)) {
      $site = $this->sites->get($this->input()->siteName(['args' => $assoc_args]));
      $env_id = $this->input()->env(['args' => $assoc_args, 'site' => $site]);
      $environment = $site->environments->get($env_id);
      $this->info = $environment->connectionInfo();
    }
    return $this->info;
  }

  /**
   * Returns the local temporary directory.
   *
   * @param bool $dir
   * @param string $prefix
   * @return string
   */
  protected function getTempDir($dir=FALSE, $prefix='php') {
    $tempfile = tempnam($dir ? $dir : sys_get_temp_dir(), $prefix ? $prefix : '');
    if (file_exists($tempfile)) {
      unlink($tempfile);
    }
    mkdir($tempfile);
    if (is_dir($tempfile)) {
      $this->tmpDirs[] = $tempfile;
      return $tempfile;
    }
  }

}
