<?php

namespace Pantheon\Dibs\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

/**
 * Class DibsCommand
 * @command site
 */
class DibsCommand extends TerminusCommand implements SiteAwareInterface {

  use SiteAwareTrait;

  protected $site;
  protected $info;

  /**
   * Dibs constructor.
   */
  public function __construct() {
    parent::__construct();
    date_default_timezone_set('UTC');
  }

  /**
   * Call dibs on a site environment.
   *
   * @param string $site_env Site & environment in the format `site-name.env`
   *   an environment.
   *
   * @param string $message A message about why you're dibs'ing the environment.
   *
   * @return PropertyList
   *
   * @throws TerminusException
   *
   * @command env:dibs
   * @authorize
   * @field-labels
   *   id: Environment
   *   created: Created
   *   domain: Domain
   *   locked: Locked
   *   initialized: Initialized
   *   connection_mode: Connection Mode
   *   php_version: PHP Version
   * @usage terminus env:dibs <site>.<env> "<message>"
   *   Call dibs on the <env> environment of <site>, leaving a note containing
   *   <message>.
   */
  public function envDibs($site_env, $message) {
    list($this->site, $environment) = $this->getSiteEnv($site_env);

    // Call dibs.
    $env = $this->callDibs($environment->id, $message);
    $this->log()->notice('Called dibs on the {env} environment.', ['env' => $env]);
    return new PropertyList($this->site->getEnvironments()->get($env)->serialize());
  }

  /**
   * Call dibs on a site environment, any environment that's available.
   *
   * @param string $site The site name or UUID of the site for which you wish to
   *   dibs an environment.
   *
   * @param string $message The message to set when calling dibs.
   *
   * @param string $filter An optional regex pattern used to filter the pool of
   *   environments you are willing to dibs. Defaults to anything but live.
   *
   * @return PropertyList
   *
   * @throws TerminusException
   *
   * @command site:dibs
   * @authorize
   * @field-labels
   *   id: Environment
   *   created: Created
   *   domain: Domain
   *   locked: Locked
   *   initialized: Initialized
   *   connection_mode: Connection Mode
   *   php_version: PHP Version
   * @usage terminus site:dibs <site> "<message>" [<filter>]
   *   Call dibs on any available <site> environment, leaving a note containing
   *   <message>. Available environments may optionally be filtered by the
   *   <filter> regex pattern applied to environment names.
   */
  public function siteDibsAny($site, $message, $filter = '^((?!^live$).)*$') {
    if ($environment = $this->getEnvToDib($site, $filter)) {
      $env = $this->callDibs($environment, $message);
      $this->log()->notice('Called dibs on the {env} environment.', ['env' => $env]);
      return new PropertyList($this->site->getEnvironments()->get($env)->serialize());
    }
    else {
      throw new TerminusException('Unable to find an environment to call dibs on.', [], 1);
    }
  }

  /**
   * Return a report of environments and their dibs status.
   *
   * @param string $site The site name or UUID of the site for which you wish to
   *   view the dibs report.
   *
   * @param string $filter An optional regex pattern used to filter the pool of
   *   environments for which you wish to view the report.
   *
   * @param integer $duration An optional time threshold (seconds) for duration that an environment has been dibs'd.
   *
   * @return RowsOfFields
   *
   * @command site:dibs:report
   * @authorize
   * @field-labels
   *   env: Environment
   *   status: Status
   *   by: By
   *   at: At
   *   message: Message
   * @usage terminus site:dibs:report <site> [<filter>] [<duration>]
   *   Return a report of environments and their dibs status, optionally
   *   filtered by the <filter> regex pattern applied to environment names
   *   and/or <duration> in seconds for how long a envrionment has been dibs'd.
   */
  public function envDibsReport($site, $filter = '^((?!^live$).)*$', $duration = 0) {
    return new RowsOfFields($this->getDibsReport($site, $filter, $duration));
  }

  /**
   * Un-dibs a site environment.
   *
   * @param string $site_env Site & environment in the format `site-name.env`
   *
   * @return PropertyList
   *
   * @throws TerminusException
   *
   * @command env:undibs
   * @authorize
   * @field-labels
   *   id: Environment
   *   created: Created
   *   domain: Domain
   *   locked: Locked
   *   initialized: Initialized
   *   connection_mode: Connection Mode
   *   php_version: PHP Version
   * @usage terminus env:undibs <site>.<env>
   *   Undibs the <env> and allow others to call dibs.
   */
  public function envUndibs($site_env) {
    list($this->site, $env) = $this->getSiteEnv($site_env);

    // Undibs the environment by invoking takesies-backsies.
    if ($this->takesiesBacksies($env) === $env->id) {
      $this->log()->notice("Undibs'd the {env} environment.", ['env' => $env->id]);
      return new PropertyList($this->site->getEnvironments()->get($env->id)->serialize());
    }
  }

  /**
   * Attempts to call dibs given an array of arguments containing a site name
   * and environment name.
   *
   * @param string $env
   * @param string $message
   *
   * @return string
   *   Returns the name of the dibs'd environment, if successful.
   *
   * @throws TerminusException
   */
  protected function callDibs($env, $message) {
    // Make sure no one's already called dibs on this site.
    if ($existingDibs = $this->getDibsFor($env)) {
      throw new TerminusException('{Who} already called dibs on {env} on {date}: {message}', [
        'Who' => $existingDibs['by'] === exec('whoami') ? 'You' : $existingDibs['by'],
        'env' => $env,
        'date' => date('D M jS \a\t h:ia', $existingDibs['at']),
        'message' => $existingDibs['message'],
      ], 1);
    }

    // Prepare to call dibs.
    $sftpCommand = $this->getSftpCommand($env);
    $workdir = $this->getTempDir();
    chdir($workdir);

    // First, write a dibs file with some metadata.
    $dibs = [
      'by' => exec('whoami'),
      'at' => time(),
      'for' => $env,
      'message' => $message,
    ];
    $put_succeeded = file_put_contents('__dibs.json', json_encode($dibs));

    // Exit early for local failures.
    if ($put_succeeded === FALSE) {
      throw new TerminusException('Could not write dibs.json to local temp file.', [], 1);
    }

    // Upload __dibs.json, if possible
    exec("(echo 'cd files' && echo 'put __dibs.json') | $sftpCommand 2> /dev/null", $output, $status);

    // If we succeeded, return the environment name.
    if ($status === 0) {
      return $env;
    }
    else {
      throw new TerminusException('There was a problem calling dibs on {env}. Last message: {message}', [
        'env' => $env,
        'message' => array_pop($output),
      ], 1);
    }
  }

  /**
   * Attempts to remove the dibs file from the given site/env.
   *
   * @param Environment $env
   *
   * @return string
   *   Returns the name of the undibs'd environment if successful.
   *
   * @throws TerminusException
   */
  protected function takesiesBacksies($env) {
    $sftpCommand = $this->getSftpCommand($env->id);
    exec("(echo 'cd files' && echo 'rm __dibs.json') | $sftpCommand 2> /dev/null", $output, $status);
    if ($status === 0) {
      return $env->id;
    }
    else {
      throw new TerminusException("There was a problem undibs'ing {env}. Last message: {message}", [
        'env' => $env->id,
        'message' => array_pop($output),
      ], 1);
    }
  }

  /**
   * Returns a list of environments and their dibs status.
   *
   * @param string $site
   * @param string $regex
   *
   * @return array
   *   An  array of dibs statuses.
   */
  protected function getDibsReport($site, $regex, $threshold) {
    $this->site = $this->getSite($site);
    $environments = $this->site->getEnvironments();
    $matches = preg_grep('/' . $regex . '/', $environments->ids());
    $status = [];

    foreach ($matches as $key => $env) {
      // Attempt to get dibs details.
      $dibs = $this->getDibsFor($env);

      // If the dibs file is empty, run an additional check on env readiness.
      if (empty($dibs)) {
        $envStatus = $this->envIsReady($env) ? 'Available' : 'Not Ready';
      }
      else {
        // Otherwise, if the dibs matches the env, it's called.
        $envStatus = $dibs['for'] === $env ? 'Already called' : 'Available';
      }

      // Further filter report by the age the of the dibs if a threshold was set.
      $age = isset($dibs['at']) ? time() - isset($dibs['at']) : 0;

      if ($age > $threshold || $threshold == 0) {
        $status[] = [
          'env' => $env,
          'status' => $envStatus,
          'by' => isset($dibs['by']) ? $dibs['by'] : NULL,
          'at' => isset($dibs['at']) ? date('D M jS \a\t h:ia', $dibs['at']) : NULL,
          'message' => isset($dibs['message']) ? $dibs['message'] : NULL,
        ];
      }
    }

    return $status;
  }

  /**
   * Returns an environment to call dibs on, given a regex filter.
   *
   * @param string $site
   * @param string $regex
   *
   * @return string|NULL
   *   The as-yet undib'd environment, or NULL if all environments have already
   *   been spoken-for.
   */
  protected function getEnvToDib($site, $regex) {
    $this->site = $this->getSite($site);
    $environments = $this->site->getEnvironments();
    $matches = preg_grep('/' . $regex . '/', $environments->ids());

    foreach ($matches as $key => $env) {
      // If no one's called dibs and the environment is ready, return fast!
      if (!$this->getDibsFor($env) && $this->envIsReady($env)) {
        return $env;
      }
    }

    // If we're here, then we're out of luck. Nothing left to dibs.
    return NULL;
  }

  /**
   * Attempts to return the public file directory for the given site.
   *
   * @return string
   *   A slash-prefixed path representing the site's public directory.
   */
  protected function getEnvPublicDirectoryForThisSite() {
    if ($this->site->get('framework') === 'wordpress') {
      return '/wp-content/uploads';
    }
    else {
      return '/sites/default/files';
    }
  }

  /**
   * Returns the user who called dibs on a site/env.
   *
   * @param string $env
   *   The env to check.
   *
   * @return array
   *   Returns an empty array if dibs hasn't been called on the given site/env.
   *   If someone HAS called dibs, it will return the dibs array.
   */
  protected function getDibsFor($env) {
    $pubDir = $this->getEnvPublicDirectoryForThisSite();
    $url = 'http://' . $env . '-' . $this->site->get('name');
    $url .= '.pantheonsite.io' . $pubDir . '/__dibs.json';
    $url .= '?cb=' . mt_rand();
    $dibsFile = $this->getRemoteDibsFile($url);

    // If there's no dibs file, it hasn't been dibs'd.
    if (empty($dibsFile)) {
      return [];
    }
    // Otherwise, make sure the dibs file matches the env name. If there's a
    // mismatch, then it's possible someone cloned from a dibs'd environment.
    else {
      $dibsFile = json_decode($dibsFile, TRUE);
      return $dibsFile['for'] === $env ? $dibsFile : [];
    }
  }

  /**
   * Returns whether or not the given site environment is fully set up.
   *
   * @param string $env
   *
   * @return bool
   *   TRUE if the environment is ready (fully cloned and available, as far as
   *   we know).
   */
  protected function envIsReady($env) {
    $mysqlCommand = $this->getMySqlCommand($env);
    // Check to see if "watchdog" and "wp_users" tables exist. These tables seem
    // to be standard across frameworks/versions and are very far toward the end
    // of table names alphabetically.
    $checkStatusCmd = $mysqlCommand . ' -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=\'pantheon\' AND TABLE_NAME IN (\'watchdog\', \'wp_users\');"';
    $response = exec($checkStatusCmd . ' 2> /dev/null', $output, $status);

    if ($status === 0) {
      return (string) $response === '1';
    }
    else {
      // If there was a problem connecting via MySQL, chances are it's not ready.
      return FALSE;
    }
  }

  /**
   * Returns the SFTP command to connect to a given site/env.
   *
   * @param string $env
   *
   * @return string
   */
  protected function getSftpCommand($env) {
    $info = $this->getSiteInfo($env);
    return $info['sftp_command'];
  }

  /**
   * Returns the MySQL command to connect to a given site/env.
   *
   * @param string $env
   *
   * @return string
   */
  protected function getMySqlCommand($env) {
    $info = $this->getSiteInfo($env);
    return $info['mysql_command'];
  }

  /**
   * Returns site information for a given site/env.
   *
   * @param string $env
   *
   * @return array
   *   An associative array of site information.
   */
  protected function getSiteInfo($env) {
    if (!isset($this->info[$env])) {
      $environment = $this->site->getEnvironments()->get($env);
      $this->info[$env] = $environment->connectionInfo();
    }

    return $this->info[$env];
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

  /**
   * Returns a remote dibs file using curl.
   * @param $url
   * @return string
   */
  protected function getRemoteDibsFile($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode == 200) {
      return $result;
    }
    else {
      return '';
    }
  }
}
