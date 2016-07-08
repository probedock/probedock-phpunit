# PHPUnit probe for Probe Dock

> PHPUnit listener to publish test results to [Probe Dock](https://github.com/probedock/probedock).

* [Setup](#setup)
* [Usage](#usage)
* [Troubleshooting](#troubleshooting)
    * [AnnotationException: the annotation was never imported](#annotation-exception)



<a name="setup"></a>
## Setup

Add `probedock-phpunit` as a dependency in your `composer.json` file:

```json
{
  "name": "my/package",
  "require": {
    "probedock/probedock-phpunit": "^0.2.0"
  }
}
```

Then run `php composer.phar update`.

If you haven't done so already, set up your Probe Dock configuration file(s).
This procedure is described here:

* [Probe Setup Procedure](https://github.com/probedock/probedock-probes#setup)

You must then add the Probe Dock PHPUnit listener to your PHPUnit configuration file (e.g. `phpunit.xml.dist`).
This is the listener you must add:

```xml
<listener class="ProbeDock\ProbeDockPHPUnit\ProbeDockPHPUnitListener">
  <arguments>
  </arguments>
</listener>
```

Here's a complete sample of a `phpunit.xml.dist` configuration file from a Symfony project, showing where to add the listener (at the bottom):

```xml
<?xml version="1.0" encoding="UTF-8"?>

<!-- https://phpunit.de/manual/current/en/appendixes.configuration.html -->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="http://schema.phpunit.de/4.8/phpunit.xsd"
         backupGlobals="false"
         colors="true"
         bootstrap="app/autoload.php">

  <php>
    <ini name="error_reporting" value="-1" />
    <server name="KERNEL_DIR" value="app/" />
  </php>

  <testsuites>
    <testsuite name="Project Test Suite">
      <directory>tests</directory>
    </testsuite>
  </testsuites>

  <filter>
    <whitelist>
      <directory>src</directory>
      <exclude>
        <directory>src/*Bundle/Resources</directory>
        <directory>src/*/*Bundle/Resources</directory>
        <directory>src/*/Bundle/*Bundle/Resources</directory>
      </exclude>
    </whitelist>
  </filter>

  <listeners>
    <listener class="ProbeDock\ProbeDockPHPUnit\ProbeDockPHPUnitListener">
      <arguments>
      </arguments>
    </listener>
  </listeners>
</phpunit>
```

All test results will now be published to Probe Dock the next time you run your test suite!



<a name="usage"></a>
### Usage

To enrich tests with more information, you can use the `@ProbeDock` annotation:

```php
<?php

namespace Tests\AppBundle\Controller;

use ProbeDock\ProbeDockPHPUnit\ProbeDock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DefaultControllerTest extends WebTestCase {

  /**
   * @ProbeDock(name="Custom name different than the function name", category="Web Test", tags="api,http,get")
   */
  public function testIndex() {
    $client = static::createClient();

    $crawler = $client->request('GET', '/');

    $this->assertEquals(200, $client->getResponse()->getStatusCode());
    $this->assertContains('Welcome to Symfony', $crawler->filter('#container h1')->text());
  }
}
```



<a name="troubleshooting"></a>
### Troubleshooting



<a name="annotation-exception"></a>
#### AnnotationException: the annotation was never imported

This library uses [Doctrine annotations](http://doctrine-orm.readthedocs.io/projects/doctrine-common/en/latest/reference/annotations.html)
so you can enrich tests with additional information such as tags.

If you are using other annotations, they may come into conflict with the Doctrine annotations library.
For example, this error may occur in a project where an `@expectedException` annotation was used in the tests:

```
Uncaught Doctrine\Common\Annotations\AnnotationException: [Semantical Error] The annotation "@expectedException" in method My\Class::testMethod() was never imported. Did you maybe forget to add a "use" statement for this annotation?
```

To solve this issue, you must add the annotations not known by Doctrine to its global ignore list.
The following code in your tests' bootstrap file will do the trick:

```php
namespace Doctrine\Common\Annotations {
  require __DIR__ . '/../vendor/autoload.php';
  use Doctrine\Common\Annotations\AnnotationReader;

  AnnotationReader::addGlobalIgnoredName('expectedException');
  // Repeat the line above to ignore other annotations...
}
```

If you do not already have a bootstrap file for your tests, you can create it and add its path to the `<phpunit>` tag in your `phpunit.xml.dist` configuration file:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="./tests/bootstrap.php" colors="true">
  <!-- Your PHPUnit configuration... -->
</phpunit>
```



## Contributing

* [Fork](https://help.github.com/articles/fork-a-repo)
* Create a topic branch - `git checkout -b feature`
* Push to your branch - `git push origin feature`
* Create a [pull request](http://help.github.com/pull-requests/) from your branch

Please add a changelog entry with your name for new features and bug fixes.



## Contributors

* Originally developed by [François Vessaz](https://github.com/fvessaz)



## License

**probedock-phpunit** is licensed under the [MIT License](http://opensource.org/licenses/MIT).
See [LICENSE.txt](LICENSE.txt) for the full text.
