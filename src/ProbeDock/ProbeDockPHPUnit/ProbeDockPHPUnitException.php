<?php

namespace ProbeDock\ProbeDockPHPUnit;

use \Exception;

/**
 * Generic ProbeDockPHPUnitException
 *
 * @author Simon Oulevay <simon.oulevay@probedock.io>
 */
class ProbeDockPHPUnitException extends Exception {

  private static $exceptionOccured = false;

  public function __construct($message) {
    self::$exceptionOccured = true;
    parent::__construct($message);
  }

  public static function exceptionOccured(){
    return self::$exceptionOccured;
  }

}
