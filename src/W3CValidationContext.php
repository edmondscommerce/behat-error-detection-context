<?php namespace EdmondsCommerce\BehatErrorDetection;

use Behat\Behat\Context\ClosuredContextInterface, Behat\Behat\Context\TranslatedContextInterface, Behat\Behat\Context\BehatContext;
use Behat\Gherkin\Node\PyStringNode, Behat\Gherkin\Node\TableNode;
use Behat\Mink\Element\NodeElement;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Mink\Exception\ElementNotFoundException, Behat\Mink\Exception\ExpectationException, Behat\Mink\Exception\ResponseTextException, Behat\Mink\Exception\ElementHtmlException, Behat\Mink\Exception\ElementTextException;
use Behat\MinkExtension\Context\RawMinkContext;
use \Guzzle\Http\Exception\CurlException;
use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;

class W3CValidationContext extends RawMinkContext implements Context, SnippetAcceptingContext
{
    /**
     * @var string
     */
    protected $url = 'https://validator.w3.org/nu/?out=json';
    /**
     * @var array;
     */
    protected $warnings;
    /**
     * @var array;
     */
    protected $errors;

    /**
     * @When /^I check source code on W3C validation service$/
     */
    public function iCheckSourceCodeOnW3CValidationService()
    {
        $this->resetErrors();
        $this->resetWarnings();
        // get compressed source code of page
        $compressedSourceCode = $this->compressMarkup($this->getSession()->getPage()->getContent());
        $this->validate($compressedSourceCode);
    }

    /**
     * @When /^I check static source code on W3C validation service$/
     */
    public function iCheckStaticSourceCodeOnW3CValidationService()
    {
        $this->resetErrors();
        $this->resetWarnings();
        $static_html = file_get_contents(
            $this->getSession()->getCurrentUrl(),
            false,
            stream_context_create([
                    "ssl" => [
                        "verify_peer" => false,
                        "verify_peer_name" => false,
                    ]
                ]
            )
        );
        $compressedSourceCode = $this->compressMarkup($static_html);
        $this->validate($compressedSourceCode);
    }

    /**
     * @Then it should pass
     */
    public function itShouldPass()
    {
        $msg = '';
        try
        {
            $this->iShouldSeeNW3CValidationErrors(0);
        } catch (ExpectationException $e)
        {
            $msg .= "\n\nErrors:\n\n" . $e->getMessage();
        }

        try
        {
            $this->iShouldSeeNW3CValidationWarnings(0);
        } catch (ExpectationException $e)
        {
            $msg .= "\n\nWarnings:\n\n" . $e->getMessage();
        }
        if ($msg)
        {
            throw new ExpectationException($msg, $this->getSession());
        }
    }

    /**
     * @Then /^I should see (.*) W3C validation errors$/
     */
    public function iShouldSeeNW3CValidationErrors($countStr)
    {
        $expectedErrorCount = $this->getNumber($countStr);
        $actualErrorCount = count($this->errors);
        if ($actualErrorCount != $expectedErrorCount)
        {
            throw new ExpectationException("Expected errors: {$expectedErrorCount}. Actual found errors: {$actualErrorCount}." . ($actualErrorCount ? (" Detailed list of errors: \n" . implode("\n---------------------------------------------------------\n", $this->errors)) : ''), $this->getSession());
        }
    }

    /**
     * @Given /^I should see (.*) W3C validation warnings$/
     */
    public function iShouldSeeNW3CValidationWarnings($countStr)
    {
        $expectedWarningCount = $this->getNumber($countStr);
        $actualWarningCount = count($this->warnings);
        if ($actualWarningCount != $expectedWarningCount)
        {
            throw new ExpectationException("Expected warnings: {$expectedWarningCount}. Actual found warnings: {$actualWarningCount}." . ($actualWarningCount ? (" Detailed list of warnings: \n" . implode("\n---------------------------------------------------------\n", $this->warnings)) : ''), $this->getSession());
        }
    }

    /**
     * @param string $markupCode
     * @return string
     */
    protected function compressMarkup($markupCode)
    {
        // compressing all white spaces
        $markupCode = preg_replace('/(\r\n|\n|\r|\t)/im', '', $markupCode);
        $markupCode = preg_replace('/\s+/m', ' ', $markupCode);
        return $markupCode;
    }

    /**
     * @param $str
     * @return int
     */
    protected function getNumber($str)
    {
        switch (trim($str))
        {
            case 'no':
                $result = 0;
                break;
            default:
                $result = intval($str);
                break;
        }
        return $result;
    }

    /**
     * @return void
     */
    protected function resetWarnings()
    {
        $this->warnings = array();
    }

    /**
     * @return void
     */
    protected function resetErrors()
    {
        $this->errors = array();
    }

    /**
     * External call to the W3C Validation API, using curl.
     *
     * @param $html
     * @throws ExpectationException
     */
    public function validate($html)
    {
        $resource = curl_init($this->url);
        curl_setopt($resource, CURLOPT_USERAGENT, 'curl');
        curl_setopt($resource, CURLOPT_POST, true);
        curl_setopt($resource, CURLOPT_HTTPHEADER, array('Content-Type: text/html; charset=utf-8'));
        curl_setopt($resource, CURLOPT_POSTFIELDS, $html);
        curl_setopt($resource, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($resource, CURLOPT_RETURNTRANSFER, true);
        $response = json_decode(curl_exec($resource), TRUE);
        if ($response === NULL || !is_array($response))
        {
            throw new ExpectationException('Could not parse W3C JSON API output', $this->getSession());
        }
        if (array_key_exists('messages', $response))
        {
            foreach ($response['messages'] as $message)
            {
                switch ($message['type'])
                {
                    case 'info':
                        if (array_key_exists('subType', $message) && $message['subType'] === 'warning')
                        {
                            $this->warnings[] = "
message: {$message['message']}
line: {$message['lastLine']}
column: {$message['lastColumn']}
extract: {$message['extract']}
";
                        }
                        break;
                    case 'error':
                        $this->errors[] = "
message: {$message['message']}
line: {$message['lastLine']}
column: {$message['lastColumn']}
extract: {$message['extract']}
";
                        break;
                    default:
                }
            }
        }
    }
}