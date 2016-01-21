#Behat Error Detection Context
## By [Edmonds Commerce](https://www.edmondscommerce.co.uk)

A simple Behat Context to allow you to use detect errors from the web browser including PHP exception messages

### Installation

Install via composer

"edmondscommerce/behat-error-detection-context": "~1.0"


### Include Context in Behat Configuration

```
default:
    # ...
    suites:
        default:
            # ...
            contexts:
                - # ...
                - EdmondsCommerce\BehatErrorDetection\ErrorDetectionContext
                - EdmondsCommerce\BehatErrorDetection\W3CValidationContext
                - EdmondsCommerce\BehatErrorDetection\XDebugContext

```
