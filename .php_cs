<?php

$config = Symfony\CS\Config\Config::create();
$config->fixers(array(
    'align_double_arrow',
    'ordered_use',
    'short_array_syntax',
));
$config->setDir(__DIR__);
$config->getFinder()->exclude('cache');

return $config;
