<?php

namespace ProbeDock\ProbeDockPHPUnit;

use Rhumsaa\Uuid\Uuid;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Exception\ParseException;
use Guzzle\Http\Client;
use Guzzle\Common\Exception\GuzzleException;

/**
 * This TestListener sends results to Probe Dock at the end of each test suite.
 *
 * @author Simon Oulevay <simon.oulevay@probedock.io>
 */
class ProbeDockPHPUnitListener implements \PHPUnit_Framework_TestListener {

  const ERROR_MESSAGE_MAX_LENGTH = 65535;
  const PAYLOAD_ENCODING = "UTF-8";

  private $probeLog;
  private $isVerbose;
  private $config;
  private $httpClient;
  private $annotationReader;
  private $testsPayloadUrl;
  private $testsRunUid;
  private $testSuiteStartTime;
  private $currentTest;
  private $currentTestSuite;
  private $nbOfTests;
  private $nbOfProbeDockTests;
  private $cacheFile;
  private $cache;
  private $nbOfPayloadsSent;

  public function __construct($options = null) {
    try {
      // init log
      if (isset($options['verbose'])) {
        $this->isVerbose = $options['verbose'];
      } else {
        $this->isVerbose = false;
      }
      if ($this->isVerbose) {
        $this->probeLog = "Probe Dock - INFO Probe Dock client is verbose.\n";
      } else {
        $this->probeLog = '';
      }

      // Load Probe Dock user configurations files
      if (isset($options['home'])) {
        $home = $options['home'];
      } else if (isset($_SERVER['HOME'])) {
        $home = $_SERVER['HOME'];
      } else {
        throw new ProbeDockPHPUnitException("Probe Dock - ERROR: No variables set for user home either with PHP \$_SERVER['HOME'], either with PHPunit TestListener arguments in phpunit.xml.dist");
      }
      $userConfigFile = file_get_contents($home . '/.probedock/config.yml');
      $projectConfigFile = file_get_contents('probedock.yml');
      if ($userConfigFile === false && $projectConfigFile === false) {
        throw new ProbeDockPHPUnitException("Probe Dock - ERROR: Unable to load both Probe Dock user config file ($home/.probedock/config.yml) and Probe Dock project config file (<rojectRoot>/probedock.yml).");
      }

      // parse config files
      $yaml = new Parser();
      try {
        $userConfig = $yaml->parse($userConfigFile);
      } catch (ParseException $e) {
        $this->probeLog .= $e->getMessage() . "\n";
      }
      try {
        $projectConfig = $yaml->parse($projectConfigFile);
      } catch (ParseException $e) {
        $this->probeLog .= $e->getMessage() . "\n";
      }
      if (!isset($userConfig) && !isset($projectConfig)) {
        throw new ProbeDockPHPUnitException("Probe Dock - ERROR: Unable to parse both Probe Dock user config file ($home/.probedock/config.yml) and Probe Dock project config file (<rojectRoot>/probedock.yml).");
      }

      // override/augment user config with project config
      if ($projectConfig) {
        $this->config = $this->overrideConfig($userConfig, $projectConfig);
      }

      // override/augment config with Probe Dock environment variables
      if (getenv("PROBEDOCK_SERVER")) {
        $this->config['server'] = getenv("PROBEDOCK_SERVER");
        $this->probeLog .= "Probe Dock - WARNING: use environment variable instead of config files (PROBEDOCK_SERVER={$this->config['server']}).\n";
      }
      if (getenv("PROBEDOCK_PUBLISH") !== false) {
        $publish = getenv("PROBEDOCK_PUBLISH");
        $this->config['payload']['publish'] = ($publish == 1 || strtoupper($publish) == "TRUE" || strtoupper($publish) == "T");
        $this->probeLog .= "Probe Dock - WARNING: use environment variable instead of config files (PROBEDOCK_PUBLISH=$publish).\n";
      } else {
        // default is true for this setting
        $this->config['payload']['publish'] = true;
      }
      if (getenv("PROBEDOCK_PRINT_PAYLOAD") !== false) {
        $print = getenv("PROBEDOCK_PRINT_PAYLOAD");
        $this->config['payload']['print'] = ($print == 1 || strtoupper($print) == "TRUE" || strtoupper($print) == "T");
        $this->probeLog .= "Probe Dock - WARNING: use environment variable instead of config files (PROBEDOCK_PRINT_PAYLOAD=$print).\n";
      }
      if (getenv("PROBEDOCK_SAVE_PAYLOAD") !== false) {
        $save = getenv("PROBEDOCK_SAVE_PAYLOAD");
        $this->config['payload']['save'] = ($save == 1 || strtoupper($save) == "TRUE" || strtoupper($save) == "T");
        $this->probeLog .= "Probe Dock - WARNING: use environment variable instead of config files (PROBEDOCK_SAVE_PAYLOAD=$save).\n";
      }
      if (getenv("PROBEDOCK_CACHE_PAYLOAD") !== false) {
        $cache = getenv("PROBEDOCK_CACHE_PAYLOAD");
        $this->config['payload']['cache'] = ($cache == 1 || strtoupper($cache) == "TRUE" || strtoupper($cache) == "T");
        $this->probeLog .= "Probe Dock - WARNING: use environment variable instead of config files (PROBEDOCK_CACHE_PAYLOAD=$cache).\n";
      }
      if (getenv("PROBEDOCK_WORKSPACE")) {
        $this->config['workspace'] = getenv("PROBEDOCK_WORKSPACE");
        $this->probeLog .= "Probe Dock - WARNING: use environment variable instead of config files (PROBEDOCK_WORKSPACE={$this->config['workspace']}).\n";
      }
      if (getenv("PROBEDOCK_TEST_REPORT_UID")) {
        $this->testsRunUid = getenv("PROBEDOCK_TEST_REPORT_UID");
      } else if (file_exists("{$this->config['workspace']}/uid")) {
        $uid = file_get_contents("{$this->config['workspace']}/uid");
        if ($uid) {
          $this->testsRunUid = $uid;
        } else {
          throw new ProbeDockPHPUnitException("Probe Dock - ERROR: A UID file exist in workspace, but it cannot be read.");
        }
      } else {
        $this->testsRunUid = Uuid::uuid4()->toString();
      }

      // select Probe Dock server
      if (isset($this->config['server'])) {
        $probedockServerName = $this->config['server'];
      } else {
        throw new ProbeDockPHPUnitException("Probe Dock - ERROR: no Probe Dock server defined either by environment variable, either by config files.");
      }

      // get server root URL
      if (isset($this->config['servers'][$probedockServerName]['apiUrl'])) {
        $probedockServerUrl = $this->config['servers'][$probedockServerName]['apiUrl'];
        if (!filter_var($probedockServerUrl, FILTER_VALIDATE_URL)) {
          throw new ProbeDockPHPUnitException("Probe Dock - ERROR: invalid url for $probedockServerName ($probedockServerUrl)");
        }
      } else {
        throw new ProbeDockPHPUnitException("Probe Dock - ERROR: no apiUrl found for $probedockServerName.");
      }

      // get server credentials
      if (isset($this->config['servers'][$probedockServerName]['apiToken'])) {
        $probedockApiToken = $this->config['servers'][$probedockServerName]['apiToken'];
      } else {
        throw new ProbeDockPHPUnitException("Probe Dock - ERROR: missing apiToken for $probedockServerName.");
      }

      // get payload v1 links from Probe Dock root URL
      $this->httpClient = new Client();
      $this->httpClient->setDefaultOption('headers/Authorization', "Bearer $probedockApiToken");

      $request = $this->httpClient->get($probedockServerUrl . "/ping");

      try {
        $response = $request->send();
      } catch (GuzzleException $e) {
        throw new ProbeDockPHPUnitException("Probe Dock - ERROR: Unable to contact Probe Dock server: {$e->getMessage()}");
      }

      $this->testsPayloadUrl = $probedockServerUrl . "/publish";

      // load cache, if needed
      /*if (isset($this->config['payload']['cache']) && $this->config['payload']['cache']) {
        if (!isset($this->config['workspace'])) {
          throw new ProbeDockPHPUnitException("Probe Dock - ERROR: missing workspace in config files or environment variables. Can not locate cache.");
        }
        if (!isset($this->config['project']['apiId'])) {
          throw new ProbeDockPHPUnitException("Probe Dock - ERROR: missing apiId for project in config files.");
        }
        $this->cache = array();
        $cachePath = "{$this->config['workspace']}/phpunit/servers/{$this->config['server']}/cache.json";
        if (file_exists($cachePath)) {
          $cacheJson = file_get_contents($cachePath);
          if (!$cacheJson) {
            throw new ProbeDockPHPUnitException("Probe Dock - ERROR: unable to read cache file ($cachePath)");
          }
          $this->cacheFile = json_decode($cacheJson, true);
          if (!$this->cacheFile) {
            throw new ProbeDockPHPUnitException("Probe Dock - ERROR: unable to decode JSON of cache file ($cachePath)");
          }
          if (isset($this->cacheFile[$this->config['project']['apiId']]) && is_array($this->cacheFile[$this->config['project']['apiId']])) {
            $this->cache = $this->cacheFile[$this->config['project']['apiId']];
          } else {
            $this->probeLog .= "Probe Dock - WARNING: no existing cache data for this project.\n";
          }
        }
      }*/

      // init class properties
      $this->testSuiteStartTime = intval(microtime(true) * 1000); // UNIX timestamp in ms
      $this->annotationReader = new AnnotationReader();
      $this->nbOfPayloadsSent = 0;
      
      // log info
      $this->probeLog .= "Probe Dock - INFO Probe Dock API: {$this->config['servers'][$this->config['server']]['apiUrl']}\n";
    } catch (ProbeDockPHPUnitException $e) {
      $this->probeLog .= $e->getMessage() . "\n";
    } catch (Exception $e) {
      $this->probeLog .= $e->getMessage() . "\n";
    }
  }

  private function overrideConfig(array $baseConfig, array $overridingConfig) {
    $res = $baseConfig;
    foreach ($overridingConfig as $key => $value) {
      if (isset($baseConfig[$key]) && is_array($baseConfig[$key]) && is_array($value) && $this->is_assoc($baseConfig[$key]) && $this->is_assoc($value)) {
        $res[$key] = $this->overrideConfig($baseConfig[$key], $value);
      } else if (isset($baseConfig[$key]) && is_array($baseConfig[$key]) && is_array($value) && !$this->is_assoc($baseConfig[$key]) && !$this->is_assoc($value)) {
        $res[$key] = array_merge($baseConfig[$key], $value);
      } else {
        $res[$key] = $value;
      }
    }
    return $res;
  }

  /**
   * Check if an array as at least one associative keys.
   */
  private function is_assoc(array $array) {
    return (bool) count(array_filter(array_keys($array), 'is_string'));
  }

  public function addError(\PHPUnit_Framework_Test $test, \Exception $e, $time) {
    if ($this->currentTest) {
      $this->currentTest['p'] = false;
      $this->currentTest['m'] = $this->jTraceEx($e) . "\n";
    }
  }

  public function addFailure(\PHPUnit_Framework_Test $test, \PHPUnit_Framework_AssertionFailedError $e, $time) {
    if ($this->currentTest) {
      $this->currentTest['p'] = false;
      $this->currentTest['m'] = $this->jTraceEx($e) . "\n";
    }
  }

  public function addIncompleteTest(\PHPUnit_Framework_Test $test, \Exception $e, $time) {
    if ($this->currentTest) {
      $this->currentTest['p'] = false;
      $this->currentTest['m'] = "This test is marked as incomplete.";
    }
  }

  public function addSkippedTest(\PHPUnit_Framework_Test $test, \Exception $e, $time) {
    if ($this->currentTest) {
      $this->currentTest['f'] = ProbeDock::INACTIVE_TEST_FLAG;
    }
  }

  public function addRiskyTest(\PHPUnit_Framework_Test $test, \Exception $e, $time) {
  }

  public function endTest(\PHPUnit_Framework_Test $test, $time) {
    if ($this->currentTest) {
      // set duration
      $this->currentTest['d'] = intval($time * 1000);

      // check message length and truncate it if needed
      if (isset($this->currentTest['m']) && strlen(mb_convert_encoding($this->currentTest['m'], self::PAYLOAD_ENCODING)) > self::ERROR_MESSAGE_MAX_LENGTH) {
        $this->currentTest['m'] = mb_substr($this->currentTest['m'], 0, self::ERROR_MESSAGE_MAX_LENGTH, self::PAYLOAD_ENCODING);
        $this->probeLog .= "Probe Dock - WARNING some error messages were truncated.\n";
      }

      // add test results to test suite
      array_push($this->currentTestSuite, $this->currentTest);
    } else if ($this->isVerbose) {
      $this->probeLog .= "Probe Dock - WARNING test {$test->getName()} is not a Probe Dock test.\n";
    }
  }

  public function endTestSuite(\PHPUnit_Framework_TestSuite $suite) {
    if (ProbeDockPHPUnitException::exceptionOccured()) {
      $this->probeLog .= "Probe Dock - WARNING RESULTS WERE NOT SENT TO PROBE DOCK.\nThis is due to previously logged errors.\n";
      return;
    } else if (empty($this->currentTestSuite)) {
      // nothing to do
      return;
    }
    try {
      $payload = array();

      // set test run UID
      $payload['u'] = $this->testsRunUid;

      // set test run duration
      $endTime = intval(microtime(true) * 1000); // UNIX timestamp in ms
      $payload['d'] = ($endTime - $this->testSuiteStartTime);

      // set project infos
      $payload['r'] = array(array());

      // set project API identifier
      if (isset($this->config['project']['apiId'])) {
        $payload['r'][0]['j'] = $this->config['project']['apiId'];
      } else {
        throw new ProbeDockPHPUnitException("Probe Dock - ERROR missing apiId for project in config files.");
      }

      // set project version
      if (isset($this->config['project']['version'])) {
        $payload['r'][0]['v'] = $this->config['project']['version'];
      } else {
        throw new ProbeDockPHPUnitException("Probe Dock - ERROR missing version for project in config files.");
      }

      // set test results
      $payload['r'][0]['t'] = $this->currentTestSuite;

      // convert payload in UTF-8
      $utf8Payload = $this->convertEncoding($payload, self::PAYLOAD_ENCODING);

      // publish payload
      if ($this->config['payload']['publish']) {
        $jsonPayload = json_encode($utf8Payload);
        $request = $this->httpClient->post($this->testsPayloadUrl, array("Content-Type" => "application/json"), $jsonPayload, array("exceptions" => false));
        try {
          $response = $request->send();
        } catch (GuzzleException $e) {
          throw new ProbeDockPHPUnitException("Probe Dock - ERROR: Unable to post results to Probe Dock server: {$e->getMessage()}");
        }
        if ($response->getStatusCode() == 202) {
          $this->nbOfPayloadsSent += 1;
          $coverageRatio = $this->nbOfProbeDockTests / $this->nbOfTests;
          $formatter = new \NumberFormatter(locale_get_default(), \NumberFormatter::PERCENT);
          $this->probeLog .= "Probe Dock - INFO {$this->nbOfProbeDockTests} test results successfully sent (payload {$this->nbOfPayloadsSent}) out of {$this->nbOfTests} ({$formatter->format($coverageRatio)}) tests in {$suite->getName()}.\n";

          // save cache, if cache is used
          if ($this->config['payload']['cache']) {
            foreach ($this->cache as $key => $hash) {
              $this->cacheFile[$this->config['project']['apiId']][$key] = $hash;
            }
            $utf8cache = $this->convertEncoding($this->cacheFile, self::PAYLOAD_ENCODING);
            $jsonCache = json_encode($utf8cache);
            $cacheDirPath = "{$this->config['workspace']}/phpunit/servers/{$this->config['server']}";
            if (!file_exists($cacheDirPath)) {
              mkdir($cacheDirPath, 0755, true);
            }
            if (!file_put_contents($cacheDirPath . "/cache.json", $jsonCache)) {
              throw new ProbeDockPHPUnitException("Probe Dock - ERROR unable to save cache in workspace");
            }
          }
        } else {
          $this->probeLog .= "Probe Dock - ERROR Probe Dock server ({$this->testsPayloadUrl}) returned an HTTP {$response->getStatusCode()} error:\n{$response->getBody(true)}\n";
        }
      } else {
        $this->probeLog .= "Probe Dock - WARNING RESULTS WERE NOT SENT TO PROBE DOCK.\nThis is due to 'publish' parameters in config file or to PROBEDOCK_PUBLISH environment variable.\n";
      }

      // save payload
      if (isset($this->config['payload']['save']) && $this->config['payload']['save']) {
        if (!isset($this->config['workspace'])) {
          throw new ProbeDockPHPUnitException("Probe Dock - ERROR no 'workspace' parameter in config files. Could not save payload.");
        }
        $payloadDirPath = "{$this->config['workspace']}/phpunit/servers/{$this->config['server']}";
        if (!file_exists($payloadDirPath)) {
          mkdir($payloadDirPath, 0755, true);
        }
        if (file_put_contents($payloadDirPath . "/payload.json", $jsonPayload)) {
          $this->probeLog .= "Probe Dock - INFO payload saved in workspace.\n";
        } else {
          throw new ProbeDockPHPUnitException("Probe Dock - ERROR unable to save payload in workspace");
        }
      }

      // print payload for DEBUG purpose
      if (isset($this->config['payload']['print']) && $this->config['payload']['print']) {
        $jsonPretty = new \Camspiers\JsonPretty\JsonPretty;
        $jsonPrettyPayload = $jsonPretty->prettify($utf8Payload);
        $this->probeLog .= "Probe Dock - DEBUG generated JSON payload:\n$jsonPrettyPayload\n";
      }

      // empty currentTestSuite to avoid double transmission of it.
      $this->currentTestSuite = null;
    } catch (ProbeDockPHPUnitException $e) {
      $this->probeLog .= $e->getMessage() . "\n";
    }
  }

  /**
   * Recursively convert encoding.
   */
  private function convertEncoding($data, $encoding) {
    if (is_string($data)) {
      return mb_convert_encoding($data, $encoding);
    } else if (is_array($data)) {
      $res = array();
      foreach ($data as $key => $value) {
        $res[$key] = $this->convertEncoding($value, $encoding);
      }
      return $res;
    } else {
      return $data;
    }
  }

  public function startTest(\PHPUnit_Framework_Test $test) {
    $this->currentTest = null;
    $this->nbOfTests += 1;
    if (!ProbeDockPHPUnitException::exceptionOccured()) {
      $testName = $test->getName();
      $reflectionObject = new \ReflectionObject($test);
      $reflectionMethod = $reflectionObject->getMethod($testName);
      $annotations = $this->annotationReader->getMethodAnnotations($reflectionMethod);
      foreach ($annotations as $annotation) {
        if ($annotation instanceof ProbeDock) {
          // this is a Probe Dock-annotated test
          try {
            $this->currentTest = array();
            $this->nbOfProbeDockTests += 1;

            // set test key
            $this->currentTest['k'] = $annotation->getKey();

            // set test name
            $userDefinedTestName = $annotation->getName();
            if ($userDefinedTestName) {
              $this->currentTest['n'] = $userDefinedTestName;
            } else {
              $convertedTestName = preg_replace('/(?!^)[A-Z]{2,}(?=[A-Z][a-z])|[A-Z][a-z]/', ' $0', $testName);
              $this->currentTest['n'] = ucfirst(strtolower($convertedTestName));
            }

            // set default for passed
            $this->currentTest['p'] = true;

            // set flags
            $flag = $annotation->getFlags();
            if ($flag > 0) {
              $this->currentTest['f'] = $flag;
            }

            // set category
            $userProposedCategory = $annotation->getCategory();
            if ($userProposedCategory) {
              $this->currentTest['c'] = $userProposedCategory;
            } else if (isset($this->config['project']['category'])) {
              $this->currentTest['c'] = $this->config['project']['category'];
            }

            // set tags
            if (isset($this->config['project']['tags'])) {
              $allTags = $this->config['project']['tags'];
            } else {
              $allTags = array();
            }
            $testTags = $annotation->getTags();
            if ($testTags) {
              foreach ($testTags as $tag) {
                if (!in_array($tag, $allTags)) {
                  array_push($allTags, $tag);
                }
              }
            }
            if (!empty($allTags)) {
              sort($allTags);
              $this->currentTest['g'] = $allTags;
            }

            // set tickets
            if (isset($this->config['project']['tickets'])) {
              $allTickets = $this->config['project']['tickets'];
            } else {
              $allTickets = array();
            }
            $testTickets = $annotation->getTickets();
            if ($testTickets) {
              foreach ($testTickets as $ticket) {
                if (!in_array($ticket, $allTickets)) {
                  array_push($allTickets, $ticket);
                }
              }
            }
            if (!empty($allTickets)) {
              sort($allTickets);
              $this->currentTest['t'] = $allTickets;
            }

            // use cache, only if it is enabled
            if ($this->config['payload']['cache']) {
              // get previous hash if any
              $oldHash = null;
              if (isset($this->cache[$this->currentTest['k']])) {
                $oldHash = $this->cache[$this->currentTest['k']];
              }

              // compute new hash
              $hashContent = $this->currentTest['n'] . " || ";
              if (isset($this->currentTest['c'])) {
                $hashContent .= $this->currentTest['c'];
              }
              $hashContent .= " || ";
              if (isset($this->currentTest['g'])) {
                $hashContent .= implode(" ", $this->currentTest['g']);
              }
              $hashContent .= " || ";
              if (isset($this->currentTest['t'])) {
                $hashContent .= implode(" ", $this->currentTest['t']);
              }
              $newHash = hash("sha256", $hashContent);

              if ($oldHash === $newHash) {
                unset($this->currentTest['n']);
                unset($this->currentTest['c']);
                unset($this->currentTest['g']);
                unset($this->currentTest['t']);
              } else {
                $this->cache[$this->currentTest['k']] = $newHash;
              }
            }
          } catch (ProbeDockPHPUnitException $e) {
            $this->probeLog .= $e->getMessage() . "\n";
          }
        }
      }
    }
  }

  public function startTestSuite(\PHPUnit_Framework_TestSuite $suite) {
    $this->currentTestSuite = array();
    $this->nbOfProbeDockTests = 0;
    $this->nbOfTests = 0;
  }

  /**
   * From http://php.net//manual/en/exception.gettraceasstring.php
   * 
   * jTraceEx() - provide a Java style exception trace
   * @param $exception
   * @param $seen      - array passed to recursive calls to accumulate trace lines already seen
   *                     leave as NULL when calling this function
   * @return array of strings, one entry per trace line
   */
  private function jTraceEx($e, $seen = null) {
    $starter = $seen ? 'Caused by: ' : '';
    $result = array();
    if (!$seen)
      $seen = array();
    $trace = $e->getTrace();
    $prev = $e->getPrevious();
    $result[] = sprintf('%s%s: %s', $starter, get_class($e), $e->getMessage());
    $file = $e->getFile();
    $line = $e->getLine();
    while (true) {
      $current = "$file:$line";
      if (is_array($seen) && in_array($current, $seen)) {
        $result[] = sprintf(' ... %d more', count($trace) + 1);
        break;
      }
      $result[] = sprintf(' at %s%s%s(%s%s%s)', count($trace) && array_key_exists('class', $trace[0]) ? str_replace('\\', '.', $trace[0]['class']) : '', count($trace) && array_key_exists('class', $trace[0]) && array_key_exists('function', $trace[0]) ? '.' : '', count($trace) && array_key_exists('function', $trace[0]) ? str_replace('\\', '.', $trace[0]['function']) : '(main)', $line === null ? $file : basename($file), $line === null ? '' : ':', $line === null ? '' : $line);
      if (is_array($seen))
        $seen[] = "$file:$line";
      if (!count($trace))
        break;
      $file = array_key_exists('file', $trace[0]) ? $trace[0]['file'] : 'Unknown Source';
      $line = array_key_exists('file', $trace[0]) && array_key_exists('line', $trace[0]) && $trace[0]['line'] ? $trace[0]['line'] : null;
      array_shift($trace);
    }
    $result = join("\n", $result);
    if ($prev)
      $result .= "\n" . jTraceEx($prev, $seen);

    return $result;
  }

  function __destruct() {
    if (!empty($this->probeLog)) {
      print "\n\n{$this->probeLog}\n\n";
    }
  }

}
