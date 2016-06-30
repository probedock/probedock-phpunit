<?php

namespace ProbeDock\ProbeDockPHPUnit;

/**
 * Annotation to track a test with Probe Dock.
 * http://php-and-symfony.matthiasnoback.nl/2011/12/symfony2-doctrine-common-creating-powerful-annotations/
 * 
 * @Annotation
 * @Target("METHOD")
 *
 * @author Simon Oulevay <simon.oulevay@probedock.io>
 */
class ProbeDock {

  const NO_FLAGS = 0;
  const INACTIVE_TEST_FLAG = 1;

  private $probedockAnnotations;

  public function __construct($options) {
    $this->probedockAnnotations = $options;
  }

  public function getKey() {
    if (!isset($this->probedockAnnotations['key'])) {
      return null;
    }

    $key = $this->probedockAnnotations['key'];
    if ($key === null || !is_string($key) || empty($key)) {
      throw new ProbeDockPHPUnitException('An @ProbeDock annotation was found, but the Probe Dock test key is not valid (must be a non-empty string).');
    }

    return $key;
  }

  public function getName() {
    if (isset($this->probedockAnnotations['name'])) {
      $name = $this->probedockAnnotations['name'];
      if ($name === null || !is_string($name) || empty($name)) {
        return null;
      }
      return $name;
    }
    return null;
  }

  public function getCategory() {
    if (isset($this->probedockAnnotations['category'])) {
      $category = $this->probedockAnnotations['category'];
      if ($category === null || !is_string($category) || empty($category)) {
        return null;
      }
      return $category;
    }
    return null;
  }

  public function getTags() {
    if (isset($this->probedockAnnotations['tags'])){
      $tags = explode(',', $this->probedockAnnotations['tags']);
      foreach ($tags as $i => $tag) {
        if (empty($tag)){
          unset($tags[$i]);
        }
      }
      if (empty($tags)){
        return null;
      } else {
        array_values($tags);
        return $tags;
      }
    }
    return null;
  }

  public function getTickets() {
    if (isset($this->probedockAnnotations['tickets'])){
      $tickets = explode(',', $this->probedockAnnotations['tickets']);
      foreach ($tickets as $i => $ticket) {
        if (empty($ticket)){
          unset($tickets[$i]);
        }
      }
      if (empty($tickets)){
        return null;
      } else {
        array_values($tickets);
        return $tickets;
      }
    }
    return null;
  }

  public function getActive() {
    if (isset($this->probedockAnnotations['active'])) {
      return preg_match('/^(1|y|yes|t|true)$/i', (string) $this->probedockAnnotations['active']) === 1;
    } else {
      return true;
    }
  }

}
