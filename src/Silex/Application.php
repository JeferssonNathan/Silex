<?php

/*
 * This file is part of the Silex framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Silex;

use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Events as HttpKernelEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Matcher\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Matcher\Exception\NotFoundException;

/**
 * The Silex framework class.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Application extends \Pimple implements HttpKernelInterface, EventSubscriberInterface
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $sc = $this;

        $this['routes'] = $this->asShared(function () {
            return new RouteCollection();
        });
        $this['controllers'] = $this->asShared(function () use ($sc) {
            return new ControllerCollection($sc['routes']);
        });

        $this['dispatcher'] = $this->asShared(function () use ($sc) {
            $dispatcher = new EventDispatcher();
            $dispatcher->addSubscriber($sc);
            $dispatcher->addListener(HttpKernelEvents::onCoreView, $sc, -10);

            return $dispatcher;
        });

        $this['resolver'] = $this->asShared(function () {
            return new ControllerResolver();
        });

        $this['kernel'] = $this->asShared(function () use ($sc) {
            return new HttpKernel($sc['dispatcher'], $sc['resolver']);
        });
    }

    /**
     * Maps a pattern to a callable.
     *
     * You can optionally specify HTTP methods that should be matched.
     *
     * @param string $pattern Matched route pattern
     * @param mixed $to Callback that returns the response when matched
     * @param string $method Matched HTTP methods, multiple can be supplied,
     *                       delimited by a pipe character '|', eg. 'GET|POST'.
     *
     * @return Silex\Controller
     */
    public function match($pattern, $to, $method = null)
    {
        $requirements = array();

        if ($method) {
            $requirements['_method'] = $method;
        }

        $route = new Route($pattern, array('_controller' => $to), $requirements);
        $controller = new Controller($route);
        $this['controllers']->add($controller);

        return $controller;
    }

    /**
     * Maps a GET request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed $to Callback that returns the response when matched
     *
     * @return Silex\Controller
     */
    public function get($pattern, $to)
    {
        return $this->match($pattern, $to, 'GET');
    }

    /**
     * Maps a POST request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed $to Callback that returns the response when matched
     *
     * @return Silex\Controller
     */
    public function post($pattern, $to)
    {
        return $this->match($pattern, $to, 'POST');
    }

    /**
     * Maps a PUT request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed $to Callback that returns the response when matched
     *
     * @return Silex\Controller
     */
    public function put($pattern, $to)
    {
        return $this->match($pattern, $to, 'PUT');
    }

    /**
     * Maps a DELETE request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed $to Callback that returns the response when matched
     *
     * @return Silex\Controller
     */
    public function delete($pattern, $to)
    {
        return $this->match($pattern, $to, 'DELETE');
    }

    /**
     * Registers a before filter.
     *
     * Before filters are run before any route has been matched.
     *
     * This method is chainable.
     *
     * @param mixed $callback Before filter callback
     *
     * @return $this
     */
    public function before($callback)
    {
        $this['dispatcher']->addListener(Events::onSilexBefore, $callback);

        return $this;
    }

    /**
     * Registers an after filter.
     *
     * After filters are run after the controller has been executed.
     *
     * This method is chainable.
     *
     * @param mixed $callback After filter callback
     *
     * @return $this
     */
    public function after($callback)
    {
        $this['dispatcher']->addListener(Events::onSilexAfter, $callback);

        return $this;
    }

    /**
     * Registers an error handler.
     *
     * Error handlers are simple callables which take a single Exception
     * as an argument. If a controller throws an exception, an error handler
     * can return a specific response.
     *
     * When an exception occurs, all handlers will be called, until one returns
     * something (a string or a Response object), at which point that will be
     * returned to the client.
     *
     * For this reason you should add logging handlers before output handlers.
     *
     * This method is chainable.
     *
     * @param mixed $callback Error handler callback, takes an Exception argument
     *
     * @return $this
     */
    public function error($callback)
    {
        $this['dispatcher']->addListener(Events::onSilexError, function(GetResponseForErrorEvent $event) use ($callback) {
            $exception = $event->getException();
            $result = $callback->__invoke($exception);

            if (null !== $result) {
                $event->setStringResponse($result);
            }
        });

        return $this;
    }

    /**
     * Flushes the controller collection.
     */
    public function flush()
    {
        $this['controllers']->flush();
    }

    /**
     * Handles the request and deliver the response.
     *
     * @param Request $request Request to process
     */
    public function run(Request $request = null)
    {
        if (null === $request) {
            $request = Request::createFromGlobals();
        }

        $this->handle($request)->send();
    }

    function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        return $this['kernel']->handle($request, $type, $catch);
    }

    /**
     * Handles onCoreRequest events.
     */
    public function onCoreRequest(KernelEvent $event)
    {
        $this['request'] = $event->getRequest();

        $this['controllers']->flush();

        $matcher = new UrlMatcher($this['routes'], array(
            'base_url'  => $this['request']->getBaseUrl(),
            'method'    => $this['request']->getMethod(),
            'host'      => $this['request']->getHost(),
            'port'      => $this['request']->getPort(),
            'is_secure' => $this['request']->isSecure(),
        ));

        try {
            $attributes = $matcher->match($this['request']->getPathInfo());

            $this['request']->attributes->add($attributes);
        } catch (NotFoundException $e) {
            $message = sprintf('No route found for "%s %s"', $this['request']->getMethod(), $this['request']->getPathInfo());
            throw new NotFoundHttpException('Not Found', $message, 0, $e);
        } catch (MethodNotAllowedException $e) {
            $message = sprintf('No route found for "%s %s": Method Not Allowed (Allow: %s)', $this['request']->getMethod(), $this['request']->getPathInfo(), strtoupper(implode(', ', $e->getAllowedMethods())));
            throw new MethodNotAllowedHttpException($e->getAllowedMethods(), 'Method Not Allowed', $message, 0, $e);
        }

        $this['dispatcher']->dispatch(Events::onSilexBefore);
    }

    /**
     * Handles string responses.
     *
     * Handler for onCoreView.
     */
    public function onCoreView(GetResponseForControllerResultEvent $event)
    {
        $response = $event->getControllerResult();
        $converter = new StringResponseConverter();
        $event->setResponse($converter->convert($response));
    }

    /**
     * Runs after filters.
     *
     * Handler for onCoreResponse.
     */
    public function onCoreResponse(Event $event)
    {
        $this['dispatcher']->dispatch(Events::onSilexAfter);
    }

    /**
     * Executes registered error handlers until a response is returned,
     * in which case it returns it to the client.
     *
     * Handler for onCoreException.
     *
     * @see error()
     */
    public function onCoreException(GetResponseForExceptionEvent $event)
    {
        $errorEvent = new GetResponseForErrorEvent($this, $event->getRequest(), $event->getRequestType(), $event->getException());
        $this['dispatcher']->dispatch(Events::onSilexError, $errorEvent);

        if ($errorEvent->hasResponse()) {
            $event->setResponse($errorEvent->getResponse());
        }
    }

    /**
     * {@inheritdoc}
     */
    static public function getSubscribedEvents()
    {
        // onCoreView listener is added manually because it has lower priority

        return array(
            HttpKernelEvents::onCoreRequest,
            HttpKernelEvents::onCoreResponse,
            HttpKernelEvents::onCoreException,
        );
    }
}