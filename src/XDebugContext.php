<?php namespace EdmondsCommerce\BehatErrorDetection;

use Behat\Mink\Driver\Selenium2Driver;
use Behat\MinkExtension\Context\RawMinkContext;

class XDebugContext extends RawMinkContext
{

    /**
     * Use this one to pause execution of the PHP session that is actually running your Behat tests.
     * Ideal for pausing execution whilst you can dig around in the browser session to see what's actually going on
     *
     * @When I xdebug break
     */
    public function iXdebugBreak()
    {
        if(function_exists('xdebug_break'))
        {
            xdebug_break();
        }
    }


    /**
     * Set the Xdebug cookie in the session itself, so you can debug the PHP code powering the site you are testing
     * @When I set xdebug cookie
     */
    public function setXdebugCookie()
    {
        $driver = $this->getSession()->getDriver();

        if ($driver instanceof Selenium2Driver && $driver->getCurrentUrl() === 'data:,')
        {
            $driver->visit('/');
        }

        $driver->setCookie('XDEBUG_SESSION', 'PHPSTORM');
    }


}