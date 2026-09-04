<?php

namespace Modules\RondoIntegration\Services;

class ProvisioningRequestException extends \RuntimeException
{
    private $httpStatus;

    public function __construct($message, $httpStatus)
    {
        parent::__construct($message);
        $this->httpStatus = (int) $httpStatus;
    }

    public function httpStatus()
    {
        return $this->httpStatus;
    }
}
