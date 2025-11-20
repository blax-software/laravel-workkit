<?php

use Blax\Workkit\Services\MiscService;

if (!function_exists('misc')) {
    function misc(): MiscService
    {
        static $instance;

        if (!$instance) {
            $instance = new MiscService();
        }

        return $instance;
    }
}
