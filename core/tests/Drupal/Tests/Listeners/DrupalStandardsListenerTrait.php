<?php

namespace Drupal\Tests\Listeners;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Util\Test;

/**
 * Listens for PHPUnit tests and fails those with invalid coverage annotations.
 *
 * Enforces various coding standards within test runs.
 *
 * @internal
 */
trait DrupalStandardsListenerTrait {

  /**
   * Signals a coding standards failure to the user.
   *
   * @param \PHPUnit\Framework\TestCase $test
   *   The test where we should insert our test failure.
   * @param string $message
   *   The message to add to the failure notice. The test class name and test
   *   name will be appended to this message automatically.
   */
  private function fail(TestCase $test, $message) {
    // Add the report to the test's results.
    $message .= ': ' . get_class($test) . '::' . $test->getName();
    $fail = new AssertionFailedError($message);
    $result = $test->getTestResultObject();
    $result->addFailure($test, $fail, 0);
  }

  /**
   * Helper method to check if a string names a valid class or trait.
   *
   * @param string $class
   *   Name of the class to check.
   *
   * @return bool
   *   TRUE if the class exists, FALSE otherwise.
   */
  private function classExists($class) {
    return class_exists($class, TRUE) || trait_exists($class, TRUE);
  }

  /**
   * Check an individual test run for valid @covers annotation.
   *
   * This method is called from $this::endTest().
   *
   * @param \PHPUnit\Framework\TestCase $test
   *   The test to examine.
   */
  private function checkValidCoversForTest(TestCase $test) {
    // If we're generating a coverage report already, don't do anything here.
    if ($test->getTestResultObject() && $test->getTestResultObject()->getCollectCodeCoverageInformation()) {
      return;
    }
    // Gather our annotations.
    $annotations = Test::parseTestMethodAnnotations(
      static::class,
      $test->getName()
    );
    // Glean the @coversDefaultClass annotation.
    $default_class = '';
    $valid_default_class = FALSE;
    if (isset($annotations['class']['coversDefaultClass'])) {
      if (count($annotations['class']['coversDefaultClass']) > 1) {
        $this->fail($test, '@coversDefaultClass has too many values');
      }
      // Grab the first one.
      $default_class = reset($annotations['class']['coversDefaultClass']);
      // Check whether the default class exists.
      $valid_default_class = $this->classExists($default_class);
      if (!$valid_default_class && interface_exists($default_class)) {
        $this->fail($test, "@coversDefaultClass refers to an interface '$default_class' and those can not be tested.");
      }
      elseif (!$valid_default_class) {
        $this->fail($test, "@coversDefaultClass does not exist '$default_class'");
      }
    }
    // Glean @covers annotation.
    if (isset($annotations['method']['covers'])) {
      // Drupal allows multiple @covers per test method, so we have to check
      // them all.
      foreach ($annotations['method']['covers'] as $covers) {
        // Ensure the annotation isn't empty.
        if (trim($covers) === '') {
          $this->fail($test, '@covers should not be empty');
          // If @covers is empty, we can't proceed.
          return;
        }
        // Ensure we don't have ().
        if (str_contains($covers, '()')) {
          $this->fail($test, "@covers invalid syntax: Do not use '()'");
        }
        // Glean the class and method from @covers.
        $class = $covers;
        $method = '';
        if (str_contains($covers, '::')) {
          [$class, $method] = explode('::', $covers);
        }
        // Check for the existence of the class if it's specified by @covers.
        if (!empty($class)) {
          // If the class doesn't exist we have either a bad classname or
          // are missing the :: for a method. Either way we can't proceed.
          if (!$this->classExists($class)) {
            if (empty($method)) {
              $this->fail($test, "@covers invalid syntax: Needs '::' or class does not exist in $covers");
              return;
            }
            elseif (interface_exists($class)) {
              $this->fail($test, "@covers refers to an interface '$class' and those can not be tested.");
            }
            else {
              $this->fail($test, '@covers class does not exist ' . $class);
              return;
            }
          }
        }
        else {
          // The class isn't specified and we have the ::, so therefore this
          // test either covers a function, or relies on a default class.
          if (empty($default_class)) {
            // If there's no default class, then we need to check if the global
            // function exists. Since this listener should always be listening
            // for endTest(), the function should have already been loaded from
            // its .module or .inc file.
            if (!function_exists($method)) {
              $this->fail($test, '@covers global method does not exist ' . $method);
            }
          }
          else {
            // We have a default class and this annotation doesn't act like a
            // global function, so we should use the default class if it's
            // valid.
            if ($valid_default_class) {
              $class = $default_class;
            }
          }
        }
        // Finally, after all that, let's see if the method exists.
        if (!empty($class) && !empty($method)) {
          $ref_class = new \ReflectionClass($class);
          if (!$ref_class->hasMethod($method)) {
            $this->fail($test, '@covers method does not exist ' . $class . '::' . $method);
          }
        }
      }
    }
  }

  /**
   * Reacts to the end of a test.
   *
   * @param \PHPUnit\Framework\Test $test
   *   The test object that has ended its test run.
   * @param float $time
   *   The time the test took.
   */
  private function doEndTest($test, $time) {
    // \PHPUnit\Framework\Test does not have any useful methods of its own for
    // our purpose, so we have to distinguish between the different known
    // subclasses.
    if ($test instanceof TestCase) {
      $this->checkValidCoversForTest($test);
    }
    elseif ($test instanceof TestSuite) {
      foreach ($test->getGroupDetails() as $tests) {
        foreach ($tests as $test) {
          $this->doEndTest($test, $time);
        }
      }
    }
  }

  /**
   * Reacts to the end of a test.
   *
   * @param \PHPUnit\Framework\Test $test
   *   The test object that has ended its test run.
   * @param float $time
   *   The time the test took.
   */
  protected function standardsEndTest($test, $time) {
    $this->doEndTest($test, $time);
  }

}
