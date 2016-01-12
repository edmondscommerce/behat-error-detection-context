<?php

namespace EdmondsCommerce\BehatErrorDetection;

use \Behat\Behat\Hook\Scope\BeforeScenarioScope;
use \Behat\Behat\Hook\Scope\AfterStepScope;
use \Behat\Behat\Hook\Scope\AfterScenarioScope;

class ErrorDetectionContext extends Behat\MinkExtension\Context\RawMinkContext
{

    private static $checkedUrls = [];

    private $scenarioData = [];

    private $logPath;

    /**
     *
     * @BeforeScenario
     *
     * @param BeforeScenarioScope $scope
     */
    public function beforeScenario(BeforeScenarioScope $scope)
    {
        $this->scenarioData = [
            'file' => $scope->getFeature()->getFile(),
            'line' => $scope->getScenario()->getLine(),
            'title' => $scope->getScenario()->getTitle(),
            'tags' => $scope->getScenario()->getTags()
        ];

        $this->setLogPath();
    }

    protected function setLogPath()
    {
        $file = str_replace('.feature', '_failures', basename($this->scenarioData['file']))
            . '_'
            . preg_replace(
                '#[^a-z]#i',
                '_',
                $this->scenarioData['title']
            ) . json_encode($this->scenarioData['tags'])
            . '.txt';
        $tag = 'no-tag';
        foreach ($GLOBALS['argv'] as $a) {
            if (false !== strpos($a, '--tags=')) {
                $tag = str_replace('--tags=', '', $a);
                break;
            }
        }
        $dir = __DIR__ . '/../../behat_results/'
            . $tag . '/'
            . gethostname() . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = $dir . '_' . $file;
        file_put_contents($path, '');
        $this->logPath = $path;
    }

    /**
     * @AfterStep
     * @param AfterStepScope $scope
     * @throws Exception
     * @throws bool
     */
    public function afterStep(AfterStepScope $scope)
    {
        $caught = false;
        try {
            $this->checkForExceptionOrFatal();
            $this->lookForJSErrors($scope);
            $this->w3cValidate($scope->getEnvironment()->getContext('W3CValidationContext'));
        } catch (\Exception $e) {
            $caught = $e;
        }
        $this->logFailedStep($scope, $caught);
        $this->screenShotFailedSteps($scope, $caught);
        if ($caught) {
            throw $caught;
        }
    }

    /**
     * @AfterScenario
     * @param AfterScenarioScope $scope
     */
    public function afterScenario(AfterScenarioScope $scope)
    {
        $this->dieOnFailedScenario($scope);
        if ($scope->getTestResult()->isPassed()) {
            unlink($this->logPath);
        }

    }

    protected function checkForExceptionOrFatal()
    {
        $message = '';
        try {
            $html = $this->getSession()->getPage()->getHtml();

            if (false !== strpos($html, '<h1>EXCEPTION: ')) {
                preg_match('%<h1>EXCEPTION:(.+?)</h1>%s', $html, $matches);
                $message .= "\n\n  ** Exception Detected **\n\n " . $matches[1] . "\n\n";
            }
            if (false !== strpos($html, 'Fatal error: ')) {
                preg_match('%Fatal error:.+?on line.*?[0-9]+%s', $html, $matches);
                $message .= "\n\n  ## Fatal Error Detected ##\n\n " . $matches[0] . "\n\n";
            }
            if (false !== strpos($html, 'Parse error: ')) {
                preg_match('%Parse error:.+?on line.*?[0-9]+%s', $html, $matches);
                $message .= "\n\n  ++ Parse error Detected ++\n\n " . $matches[0] . "\n\n";
            }

        } catch (WebDriver\Exception\StaleElementReference $e) {
            // silence
        } catch (Exception $e) {
            echo "\n" . get_class($e) . ":\n\n" . $e->getMessage() . "\n";
        }
        if ($message) {
            throw new \Exception($message);
        }
    }

    protected function w3cValidate(W3CValidationContext $v)
    {
        $current_url = $this->getSession()->getCurrentUrl();
        if (!$current_url) {
            return;
        }
        $parsed_url = parse_url($current_url);

        if (isset($parsed_url['host'])) {
            $url = $parsed_url['host'] . $parsed_url['path'];
            if (!isset(self::$checkedUrls[$url])) {
                $valid = true;
                try {
                    $v->iCheckStaticSourceCodeOnW3CValidationService();
                    $v->itShouldPass();
                } catch (\Behat\Mink\Exception\ExpectationException $e) {
                    echo "\n\n ** W3C Errors on URL:\n ** $url\n\n" . $e->getMessage();
                    $valid = false;
                } catch (\Exception $e) {
                    echo "\n\n ~~ Exception when validating URL:\n ~~ $url\n\n" . $e->getMessage();
                }
                if (false === $valid) {
                    echo "\n\n -- Valid W3C Check for URL:\n -- $url\n\n";
                }
                self::$checkedUrls[$url] = true;
            }
        }
    }

    protected function lookForJSErrors(AfterStepScope $scope)
    {
        $current_url = $this->getSession()->getCurrentUrl();
        if (!$current_url) {
            return;
        }
        $driver = $this->getMink()->getSession()->getDriver();
        if ($driver instanceof \Behat\Mink\Driver\Selenium2Driver) {
            $html = $this->getSession()->getPage()->getHtml();
            if (false === strpos($html, 'window.jsErrors')) {
                echo "\n\n JS error detection wont work without this JS in place:

<script>
    window.jsErrors = [];
    window.onerror = function (errorMessage) {
        window.jsErrors[window.jsErrors.length] = errorMessage;
    };
</script>

                ";
            } else {

                try {
                    $errors = $driver->evaluateScript("return window.jsErrors");
                } catch (\Exception $e) {
                    // output where the error occurred for debugging purposes
                    echo $this->scenarioData;
                    throw $e;
                }

                if (!$errors || empty($errors)) {
                    return;
                }

                $file = sprintf("%s:%d", $scope->getFeature()->getFile(), $scope->getStep()->getLine());
                $message = sprintf("Found %d javascript error%s", count($errors), count($errors) > 0 ? 's' : '');

                echo '-------------------------------------------------------------' . PHP_EOL;
                echo $file . PHP_EOL;
                echo $message . PHP_EOL;
                echo '-------------------------------------------------------------' . PHP_EOL;

                foreach ($errors as $index => $error) {
                    echo sprintf("   #%d: %s", $index, $error) . PHP_EOL;
                }

                throw new \Exception($message);
            }
        }
    }


    protected function dieOnFailedScenario(AfterScenarioScope $scope)
    {
        if (99 === $scope->getTestResult()->getResultCode()) {
            if (isset($_SERVER['BEHAT_DIE_ON_FAILURE'])) {
                die("\n\nBEHAT_DIE_ON_FAILURE is defined\n\nKilling Full Process\n\n\n\n");
            } else {
                echo "\n\nTo die on failure, please run:\nexport BEHAT_DIE_ON_FAILURE=true;\n\n";
            }
        }
    }

    protected function logFailedStep(AfterStepScope $scope, $caught = false)
    {
        if (!$scope->getTestResult()->isPassed() || $caught) {
            $file = $this->logPath;
            $exception = $caught ?: $scope->getTestResult()->getException();
            $message = "\n\n--------------------------------------------\n\n"
                . var_export($this->scenarioData, true)
                . "\n\n" . var_export($exception->getMessage(), true);
            file_put_contents($file, $message, FILE_APPEND);
            echo "\n\n Error logged to $file\n\n";
            echo "\n( URL ".$this->getSession()->getCurrentUrl().")\n\n";
        }
    }

    /**
     * Take screen shot when step fails.
     * And then pause everything
     * Works only with Selenium2Driver.
     *
     * @param Behat\Behat\Hook\Scope\AfterStepScope $scope
     *
     */
    protected function screenShotFailedSteps(Behat\Behat\Hook\Scope\AfterStepScope $scope, $caught = false)
    {
        if (99 === $scope->getTestResult()->getResultCode() || $caught) {
            $driver = $this->getSession()->getDriver();
            if ($driver instanceof \Behat\Mink\Driver\Selenium2Driver) {

                $name = substr(preg_replace('%[^a-z0-9]%i', '_',
                    array_pop($_SERVER['argv']) . ':' . $scope->getStep()->getText() . '_' . $driver->getCurrentUrl()), 0, 100);
                $file = '/tmp/behat_' . $name . '.png';
                file_put_contents($file, $this->getSession()->getDriver()->getScreenshot());
                echo "\n( Error Screen Shot Saved to $file)\n\n";
            }
        }
    }

}
