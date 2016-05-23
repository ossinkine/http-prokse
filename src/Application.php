<?php

use Controller\ProxyController;
use GuzzleHttp\Client as HttpClient;
use Silex\Provider\ServiceControllerServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\EventListener\ResponseListener;
use Symfony\Component\HttpKernel\KernelEvents;

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

        // Remove ResponseListener to avoid headers replacement
        $this['dispatcher'] = $this->extend('dispatcher', $this->share(function (EventDispatcherInterface $dispatcher) {
            $listeners = $dispatcher->getListeners(KernelEvents::RESPONSE);
            foreach ($listeners as $listener) {
                list($object, $method) = $listener;
                if ($object instanceof ResponseListener) {
                    $dispatcher->removeListener(KernelEvents::RESPONSE, $listener);
                }
            }

            return $dispatcher;
        }));

//        $this->register(new TwigServiceProvider(), [
//            'twig.path'    => __DIR__.'/../views',
//            'twig.options' => [
//                'cache' => is_writable(__DIR__.'/..') ? __DIR__.'/../cache/twig' : false,
//            ],
//        ]);
        $this->register(new UrlGeneratorServiceProvider());
        $this->register(new ServiceControllerServiceProvider());

        $this
            ->match('/{url}', 'controller.proxy:indexAction')
            ->bind('index')
            ->assert('url', '.*')
        ;
    }
}
