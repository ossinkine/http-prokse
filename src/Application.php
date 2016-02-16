<?php

use Controller\ProxyController;
use GuzzleHttp\Client as HttpClient;
use Silex\Provider\ServiceControllerServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;

class Application extends Silex\Application
{
    /**
     * {@inheritdoc}
     *
     * @param array $values
     */
    public function __construct(array $values = [])
    {
        $values['http_client'] = $this->share(function (Application $app) {
            return new HttpClient();
        });
        $values['controller.proxy'] = $this->share(function (Application $app) {
            return new ProxyController($app['url_generator'], $app['http_client']);
        });

        parent::__construct($values);

//        $this->register(new TwigServiceProvider(), [
//            'twig.path'    => __DIR__.'/../views',
//            'twig.options' => [
//                'cache' => is_writable(__DIR__.'/..') ? __DIR__.'/../cache/twig' : false,
//            ],
//        ]);
        $this->register(new UrlGeneratorServiceProvider());
        $this->register(new ServiceControllerServiceProvider());

        $this
            ->get('/{url}', 'controller.proxy:indexAction')
            ->bind('index')
            ->assert('url', '.*')
        ;
    }
}
