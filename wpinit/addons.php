<?php

class acyAddons extends acyHook
{
    public function __construct()
    {
        acym_trigger('onAcymInitWordpressAddons');
    }
}

$acyPlugin = new acyAddons();
