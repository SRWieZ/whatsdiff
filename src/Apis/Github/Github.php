<?php

namespace Whatsdiff\Apis\Github;

use Saloon\Http\Connector;

class Github extends Connector
{
    public function resolveBaseUrl(): string
    {
        return '';
    }
}
