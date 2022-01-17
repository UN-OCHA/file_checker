<?php

namespace Drupal\file_checker\Commands;

use Drush\Commands\DrushCommands;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;

/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml
 */
class FileCheckerCommands extends DrushCommands {

  /**
   * Start file checking.
   *
   * @usage drush file-checking-start
   *   Starts file checking.
   *
   * @command file:checking-start
   * @aliases fcheck-start,file-checking-start
   */
  public function checkingStart() {
    $success = \Drupal::service('file_checker.bulk_file_checking')->start();
    if ($success) {
      $this->output->writeln(dt("Bulk file checking requested. To actually check files, next run 'drush file-checking-execute'."));
    }
    else {
      $this->output->writeln(dt("Bulk file checking has already been requested. To actually check files, instead run 'drush file-checking-execute'."));
    }
  }

  /**
   * Start file checking.
   *
   * @usage drush file-checking-start
   *   Cancels file checking.
   *
   * @command file:checking-cancel
   * @aliases fcheck-cancel,file-checking-cancel
   */
  public function checkingCancel() {
    \Drupal::service('file_checker.bulk_file_checking')->cancel();
    $this->output->writeln(dt("Bulk file checking cancelled."));
  }

  /**
   * Execute file checking for a period of time.
   *
   * @param $seconds
   *   Number of seconds to execute for.
    * @param array $options An associative array of options whose values come from cli, aliases, config, etc.
   * @option log
   *   Log this execution.
   * @usage drush file-checking-execute --log
   *   Check files for 50 seconds, and logs it.
   * @usage drush fcheck-exec 110
   *   Check files for 110 seconds".
   *
   * @command file:checking-execute
   * @aliases fcheck-exec,file-checking-execute
   */
  public function checkingExecute($seconds, array $options = ['log' => null]) {
    $this->output->writeln(dt('Files will be checked for up to @seconds seconds. Checking now ...', array('@seconds' => $seconds)));
    $runState = \Drupal::service('file_checker.bulk_file_checking')->executeInBackground($seconds, drush_get_option('log',FALSE));
    if ($runState['aborted']) {
      $this->output->writeln(dt("File checking has not been previously started, checking aborted."));
      $this->output->writeln(dt("To start checking, first run 'drush file-checking-start'."));
    }
    else {
      $this->output->writeln(dt("@files_just_checked files just checked.", ['@files_just_checked' => $runState['files_just_checked']]));
      $this->output->writeln(dt("So far in this run @files_checked_count out of @files_to_check files checked, with @files_missing_count missing files detected.", [
        '@files_checked_count' => $runState['files_checked_count'],
        '@files_to_check' => $runState['files_to_check'],
        '@files_missing_count' => $runState['files_missing_count'],
      ]));
      if ($runState['finished']) {
        $this->output->writeln(dt("File checking completed."));
      }
      else {
        $this->output->writeln(dt("To check more files, run 'drush file-checking-execute' again."));
      }
    }
  }

  /**
   * Repair missing files; move files to their expected location.
   *
   * @param $csv
   *   CSV file that contains two columns, the current (wrong) file path and the actual file path.
    * @param array $options An associative array of options whose values come from cli, aliases, config, etc.
   * @option log
   *   Log this execution.
   * @usage drush file-checking-repair /tmp/list.csv --log
   *   Moves all files listed in /tmp/list.csv and logs it.
   * @usage drush fcheck-repair /tmp/list.csv
   *   Moves all files listed in /tmp/list.csv.
   *
   * @command file:checking-repair
   * @aliases fcheck-repair,file-checking-repair
   */
  public function checkingRepair($csv, array $options = ['log' => null]) {
    if (empty($csv)) {
      $this->output->writeln(dt('The repair command requires a csv file as parameter'));
      return;
    }

    if (!file_exists($csv)) {
      $this->output->writeln(dt('The file "%csv" does not exist.', ['%csv' => $csv]));
      return;
    }

    $fp = fopen($csv, "r");
    if (!$fp) {
      $this->output->writeln(dt('The file "%csv" cannot be read.', ['%csv' => $csv]));
      return;
    }

    // A line counter.
    $line = 0;
    while(!feof($fp)) {
      ++$line;

      $data = fgetcsv($fp, 4096);

      if (empty($data) || !is_array($data)) {
        $this->output->writeln(dt('ERROR: Failed to get csv data from %csv on line %line', ['%csv' => $csv, '%line' => $line]));
        return;
      }

      array_walk($data, 'trim');

      // If either field is blank, skip!
      if (empty($data[0]) || empty($data[1])) {
        $this->output->writeln(dt('WARNING: Skipping empty data on line %line.', ['%line' => $line]));
        continue;
      }

      // We now have the file we want on disk in $data[0] and the current on-disk file in $data[1].
      // Check how sane this is.
      $source = $this->getpathByUri($data[1]);
      if (!file_exists($source)) {
        $this->output->writeln(dt('ERROR: Source file %source does not exist on line %line', ['%source' => $data[1], '%line' => $line])); 
        continue;
      }

      $dest = $this->getFileByUri($data[0]);
      if ($dest !== NULL) {
        $this->output->writeln(dt('ERROR: Destination file %dest already exists on line %line', ['%dest' => $data[0], '%line' => $line]));
        continue;
      }

      $this->output->writeln(dt('INFO: Move %source => %dest', ['%source' => $data[1], '%dest' => $data[0]]));
      \Drupal::service('file_system')->move($data[1], $data[0], FileSystemInterface::EXISTS_REPLACE);
    }

    // Fin.
    fclose($fp)
  }

  private function getPathByUri($uri) {
    return \Drupal::service('file_system')->realpath($uri);
  }

  private function getFileByUri($uri) {
    $files = \Drupal::entityTypeManager()
      ->getStorage('file')
      ->loadByProperties(['uri' => $uri]);
      $file = reset($files) ?: NULL;
    return $file;
  }
}
