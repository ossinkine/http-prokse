<?php

namespace Controller;

use DOMElement;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\MessageInterface;
use Sabberworm\CSS\Parser as CssParser;
use Sabberworm\CSS\Property\Import;
use Sabberworm\CSS\Value\URL;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ProxyController
{
    /**
     * @var UrlGeneratorInterface
     */
    private $urlGenerator;

    /**
     * @var HttpClient
     */
    private $httpClient;

    /**
     * @var string
     */
    private $currentUrl;

    /**
     * @param UrlGeneratorInterface $urlGenerator
     * @param HttpClient            $httpClient
     */
    public function __construct(UrlGeneratorInterface $urlGenerator, HttpClient $httpClient)
    {
        $this->urlGenerator = $urlGenerator;
        $this->httpClient = $httpClient;
    }

    /**
     * @param Request $request
     * @param string  $url
     *
     * @return Response
     */
    public function indexAction(Request $request, $url)
    {
        if ($queryString = $request->getQueryString()) {
            $url .= '?'.$queryString;
        }
        $this->currentUrl = $url;
        $method = $request->getMethod();
        $headers = $request->headers->all();
        unset($headers['host']);
        try {
            $response = $this->httpClient->request(
                $method,
                $url,
                ['headers' => $headers, 'body' => $request->getContent(), 'allow_redirects' => false]
            );
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
        }

        $headers = $this->getHeaders($response);
        list($contentType) = isset($headers['Content-Type']) ? explode('; ', reset($headers['Content-Type'])) : null;
        switch ($contentType) {
            case 'text/html':
                $result = $this->getHtmlResponse($response);
                break;
            case 'text/css':
                $result = $this->getCssResponse($response);
                break;
            default:
                $result = $this->getDefaultResponse($response);
        }
        $result->setStatusCode($response->getStatusCode(), $response->getReasonPhrase());
        $result->headers->add($headers);

        return $result;
    }

    /**
     * @param MessageInterface $response
     *
     * @return array
     */
    private function getHeaders(MessageInterface $response)
    {
        $headers = $response->getHeaders();
        foreach (['Content-Location', 'Location'] as $header) {
            if (isset($headers[$header])) {
                foreach ($headers[$header] as $index => $value) {
                    $headers[$header][$index] = $this->getProxyUrl($value);
                }
            }
        }
        if (isset($headers['Set-Cookie'])) {
            foreach ($headers['Set-Cookie'] as $index => $value) {
                $cookie = [];
                $cookieComponents = explode('; ', $value);
                foreach ($cookieComponents as $cookieComponent) {
                    @list($name, $value) = explode('=', $cookieComponent);
                    if ('Domain' === $name) {
                        continue;
                    }
                    if ('Path' === $name) {
                        $value = $this->getProxyUrl($value);
                    }
                    $cookie[] = $value ? implode('=', [$name, $value]) : $name;
                }
                $headers['Set-Cookie'][$index] = implode('; ', $cookie);
            }
        }

        return $headers;
    }

    /**
     * @param MessageInterface $response
     *
     * @return StreamedResponse
     */
    private function getHtmlResponse(MessageInterface $response)
    {
        $contentType = $response->getHeader('Content-Type');
        $contentType = reset($contentType);
        $crawler = new Crawler();
        $crawler->addContent($response->getBody(), $contentType);
        $elementsWithUrl = [
            'a'          => 'href',
            'applet'     => 'codebase',
            'area'       => 'href',
            'audio'      => 'src',
            'base'       => 'href',
            'blockquote' => 'cite',
            'body'       => 'background',
            'button'     => 'formaction',
            'command'    => 'icon',
            'del'        => 'cite',
            'embed'      => 'src',
            'form'       => 'action',
            'frame'      => ['longdesc', 'src'],
            'head'       => 'profile',
            'html'       => 'manifest',
            'iframe'     => ['longdesc', 'src'],
            'img'        => ['longdesc', 'src', 'usemap'],
            'input'      => ['formaction', 'src', 'usemap'],
            'ins'        => 'cite',
            'link'       => 'href',
            'object'     => ['classid', 'codebase', 'data', 'usemap'],
            'q'          => 'cite',
            'script'     => 'src',
            'source'     => 'src',
            'video'      => ['poster', 'src'],
        ];
        foreach ($elementsWithUrl as $selector => $attributes) {
            $attributes = (array) $attributes;
            /** @var DOMElement $node */
            foreach ($crawler->filter($selector) as $node) {
                foreach ($attributes as $attribute) {
                    if ($node->hasAttribute($attribute)) {
                        $url = $node->getAttribute($attribute);
                        $node->setAttribute($attribute, $this->getProxyUrl($url));
                    }
                }
            }
        }
        foreach ($crawler->filter('style') as $node) {
            $css = $node->nodeValue;
            $node->nodeValue = $this->processCss($css);
        }
        foreach ($crawler->filter('[style]') as $node) {
            $css = $node->getAttribute('style');
            $node->setAttribute('style', $this->processStyle($css));
        }
        $result = sprintf('<!DOCTYPE html><html>%s</html>', $crawler->html());
        $charset = strtolower($crawler->getNode(0)->ownerDocument->encoding);
        if ('utf-8' !== $charset) {
            $result = iconv('utf-8', $charset, $result);
        }

        return new Response($result);
    }

    /**
     * @param MessageInterface $response
     *
     * @return StreamedResponse
     */
    private function getCssResponse(MessageInterface $response)
    {
        $css = $response->getBody();
        $css = $this->processCss($css);

        return new Response($css);
    }

    /**
     * @param MessageInterface $response
     *
     * @return StreamedResponse
     */
    private function getDefaultResponse(MessageInterface $response)
    {
        return new StreamedResponse(function () use ($response) {
            $stream = $response->getBody();
            $response->getBody()->eof();
            while (!$stream->eof()) {
                echo $stream->read(1024);
            }
        });
    }

    /**
     * @param string $source
     *
     * @return string
     */
    private function processStyle($source)
    {
        $source = sprintf('element { %s }', $source);
        $source = $this->processCss($source);
        $source = substr($source, 9, -1);

        return $source;
    }

    /**
     * @param string $source
     *
     * @return string
     */
    private function processCss($source)
    {
        $parser = new CssParser($source);
        $css = $parser->parse();
        foreach ($css->getAllValues() as $value) {
            if ($value instanceof Import) {
                $value = $value->getLocation();
            }
            if ($value instanceof URL) {
                $url = $value->getURL();
                $urlString = $url->getString();
                $url->setString($this->getProxyUrl($urlString));
            }
        }
        $source = $css->render();

        return $source;
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function getProxyUrl($url)
    {
        $url = parse_url($url);
        if (isset($url['scheme']) && in_array($url['scheme'], ['http', 'https'], true)) {
            return $this->buildUrl($url);
        }
        $currentUrl = parse_url($this->currentUrl);
        if (!isset($url['host'])) {
            $url['host'] = $currentUrl['host'];
            if (isset($currentUrl['port'])) {
                $url['port'] = $currentUrl['port'];
            }
            if (isset($currentUrl['user'])) {
                $url['user'] = $currentUrl['user'];
            }
            if (isset($currentUrl['pass'])) {
                $url['pass'] = $currentUrl['pass'];
            }
        }
        if (!isset($url['scheme'])) {
            $url['scheme'] = $currentUrl['scheme'];
        }
        if (isset($url['path']) && strpos($url['path'], '/') !== 0) {
            if (!isset($currentUrl['path'])) {
                $currentUrl['path'] = '/';
            }
            $url['path'] = substr($currentUrl['path'], 0, strrpos($currentUrl['path'], '/') + 1).$url['path'];
            $url['path'] = $this->normalizePath($url['path']);
        }
//        if ('https://ya.ru/' === $this->buildUrl($url)) {
//            debug_print_backtrace();exit;
//        }

        return $this->urlGenerator->generate('index', ['url' => $this->buildUrl($url)]);
    }

    /**
     * @param string $absolutePath
     *
     * @return string
     */
    private function normalizePath($absolutePath)
    {
        $pathComponents = explode('/', $absolutePath);
        foreach ($pathComponents as $i => $pathComponent) {
            if ('.' === $pathComponent) {
                unset($pathComponents[$i]);
            }
            if ('..' === $pathComponent) {
                unset($pathComponents[$i]);
                for ($j = $i - 1; $j >= 0; --$j) {
                    if (isset($pathComponents[$j])) {
                        unset($pathComponents[$j]);
                        break;
                    }
                }
            }
        }
        $result = implode('/', $pathComponents);
        if (strpos($result, '/') !== 0) {
            $result = '/'.$result;
        }

        return $result;
    }

    /**
     * @param array $url
     *
     * @return string
     */
    private function buildUrl(array $url)
    {
        $result = '';
        if (isset($url['host'])) {
            $result = $url['host'];
            if (isset($url['port'])) {
                $result .= ':'.$url['port'];
            }
            if (isset($url['user'])) {
                $credentials = $url['user'];
                if (isset($url['pass'])) {
                    $credentials .= ':'.$url['pass'];
                }
                $result = $credentials.'@'.$result;
            }
            $result = '//'.$result;
        }
        if (isset($url['scheme'])) {
            $result = $url['scheme'].':'.$result;
        }
        if (isset($url['path'])) {
            $result .= $url['path'];
        }
        if (isset($url['query'])) {
            $result .= '?'.$url['query'];
        }
        if (isset($url['fragment'])) {
            $result .= '#'.$url['fragment'];
        }

        return $result;
    }
}
