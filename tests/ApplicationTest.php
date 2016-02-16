<?php

use GuzzleHttp\Psr7\Response as HttpResponse;
use PHPUnit_Framework_MockObject_MockObject as MockObject;
use Silex\WebTestCase;
use Symfony\Component\HttpKernel\Client;

class ApplicationTest extends WebTestCase
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var MockObject
     */
    protected $httpClient;

    public function setUp()
    {
        parent::setUp();
        $this->client = $this->createClient();
    }

    public function testHtmlProxy()
    {
        $body = <<<HTML
<html>
<head>
    <style>
        body { background-image: url("/image/background.jpg"); }
    </style>
    <script src="/js/script.js"></script>
</head>
<body>
    <a href="//youtube.com/">
        <img src="https://youtube.com/logo.png" style="background-image: url(//youtube.com/background.jpg);">
    </a>
</body>
</html>
HTML;
        $response = new HttpResponse(
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Set-Cookie'   => 'session=123; Path=/; Domain=google.com',
            ],
            $body
        );
        $this->httpClient
            ->expects(static::once())
            ->method('request')
            ->with(
                'GET',
                'https://google.com/',
                [
                    'headers' => [
                        'user-agent'      => ['Symfony2 BrowserKit'],
                        'accept'          => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
                        'accept-language' => ['en-us,en;q=0.5'],
                        'accept-charset'  => ['ISO-8859-1,utf-8;q=0.7,*;q=0.7'],
                        'x-php-ob-level'  => [1],
                        'connection'      => ['close'],
                    ],
                    'allow_redirects' => false,
                ]
            )
            ->willReturn($response)
        ;
        $this->client->request('GET', '/https://google.com/', [], [], ['HTTP_CONNECTION' => 'close']);
        $response = $this->client->getResponse();
        static::assertTrue($response->isOk());
        static::assertEquals(
            [
                'cache-control' => ['no-cache'],
                'set-cookie'    => ['session=123; Path=/https://google.com/'],
                'content-type'  => ['text/html; charset=UTF-8'],
            ],
            $response->headers->all()
        );
        static::assertEquals(<<<HTML
<!DOCTYPE html><html><head>
<style>body {background-image: url("/https://google.com/image/background.jpg");}</style>
<script src="/https://google.com/js/script.js"></script>
</head>
<body>
    <a href="/https://youtube.com/">
        <img src="/https://youtube.com/logo.png" style='background-image: url("/https://youtube.com/background.jpg");'></a>
</body></html>
HTML
            ,
            $response->getContent()
        );
    }

    public function testCssProxy()
    {
        $body = <<<CSS
body { background-image: url("/image/background.jpg"); }
a:hover { background-image: url(../image/link.png); }
CSS;
        $response = new HttpResponse(
            200,
            ['Content-Type' => 'text/css; charset=UTF-8'],
            $body
        );
        $this->httpClient
            ->expects(static::once())
            ->method('request')
            ->with(
                'GET',
                'https://google.com/css/style.css',
                [
                    'headers' => [
                        'user-agent'      => ['Symfony2 BrowserKit'],
                        'accept'          => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
                        'accept-language' => ['en-us,en;q=0.5'],
                        'accept-charset'  => ['ISO-8859-1,utf-8;q=0.7,*;q=0.7'],
                        'x-php-ob-level'  => [1],
                        'connection'      => ['close'],
                    ],
                    'allow_redirects' => false,
                ]
            )
            ->willReturn($response)
        ;
        $this->client->request('GET', '/https://google.com/css/style.css', [], [], ['HTTP_CONNECTION' => 'close']);
        $response = $this->client->getResponse();
        static::assertTrue($response->isOk());
        static::assertEquals(
            [
                'cache-control' => ['no-cache'],
                'content-type'  => ['text/css; charset=UTF-8'],
            ],
            $response->headers->all()
        );
        static::assertEquals(<<<CSS
body {background-image: url("/https://google.com/image/background.jpg");}
a:hover {background-image: url("/https://google.com/image/link.png");}
CSS
            ,
            $response->getContent()
        );
    }

    public function createApplication()
    {
        $app = new Application(['debug' => true]);
        $app['http_client'] = $this->httpClient = $this->getMock('GuzzleHttp\Client');

        return $app;
    }
}
