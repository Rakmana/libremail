<?php

namespace App\Exceptions;

class Stop extends \Exception
{
    public $code = EXC_STOP;
    public $message = "System received SIGPWR, stopping current sync.";
}