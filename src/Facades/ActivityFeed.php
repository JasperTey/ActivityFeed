<?php

namespace JasperTey\ActivityFeed\Facades;

use Illuminate\Support\Facades\Facade;

class ActivityFeed extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'activityfeed';
    }
}
