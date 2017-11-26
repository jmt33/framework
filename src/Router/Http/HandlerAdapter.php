<?php

namespace Swoft\Router\Http;

use Psr\Http\Message\ServerRequestInterface;
use Swoft\App;
use Swoft\Base\RequestContext;
use Swoft\Bean\Annotation\Bean;
use Swoft\Exception\Exception;
use Swoft\Exception\Http\RouteNotFoundException;
use Swoft\Exception\RuntimeException;
use Swoft\Helper\PhpHelper;
use Swoft\Router\HandlerAdapterInterface;
use Swoft\Web\Request;
use Swoft\Web\Response;

/**
 * http handler adapter
 *
 * @Bean("httpHandlerAdapter")
 * @uses      HandlerAdapterMiddleware
 * @version   2017年11月23日
 * @author    stelin <phpcrazy@126.com>
 * @copyright Copyright 2010-2016 swoft software
 * @license   PHP Version 7.x {@link http://www.php.net/license/3_0.txt}
 */
class HandlerAdapter implements HandlerAdapterInterface
{
    private $defaultAction = 'index';
    private $defaultPrefix = 'action';

    /**
     * exexute handler with controller and action
     *
     * @param ServerRequestInterface $request request object
     * @param array                  $handler handler info
     *
     * @return \Swoft\Web\Response
     */
    public function doHandler(ServerRequestInterface $request, array $handler)
    {
        /**
         * @var string $path
         * @var array  $handler
         */
        list($path, $info) = $handler;

        // not founded route
        if ($info == null) {
            throw new RouteNotFoundException("Route not found");
        }

        // http handler
        list($handler, $params) = $this->createHandler($path, $info);

        if (is_array($handler)) {
            list($controller, $actionId) = $handler;
            $actionId = empty($actionId) ? $this->defaultAction : $actionId;
            if (!method_exists($controller, $actionId)) {
                $actionId = $this->defaultPrefix . ucfirst($actionId);
            }
        }

        $params   = $this->bindParams($request, $handler, $info);
        $response = PhpHelper::call($handler, $params);

        if (!$response instanceof Response) {
            $response = RequestContext::getResponse()->auto($response);
        }

        return $response;
    }

    /**
     * 创建控制器
     *
     * @param string $path url路径
     * @param array  $info url参数
     *
     * @return array
     * <pre>
     *  [$controller, $action, $matches]
     * </pre>
     * @throws \InvalidArgumentException
     */
    public function createHandler(string $path, array $info)
    {
        $handler = $info['handler'];
        $matches = $info['matches'] ?? [];

        // is a \Closure or a callable object
        if (is_object($handler)) {
            return [$handler, $matches];
        }

        // is array ['controller', 'action']
        if (is_array($handler)) {
            $segments = $handler;
        } elseif (is_string($handler)) {
            // e.g `Controllers\Home@index` Or only `Controllers\Home`
            $segments = explode('@', trim($handler));
        } else {
            App::error('Invalid route handler for URI: ' . $path);
            throw new \InvalidArgumentException('Invalid route handler for URI: ' . $path);
        }

        $className  = $segments[0];
        $action     = $segments[1];
        $action     = HandlerMapping::convertNodeStr($action);
        $controller = App::getBean($className);
        $handler    = [$controller, $action];

        // Set Controller and Action infos to Request Context
        RequestContext::setContextData([
            'controllerClass'  => $className,
            'controllerAction' => $action,
        ]);

        return [$handler, $matches];
    }

    /**
     * binding params of action method
     *
     * @param ServerRequestInterface $request request object
     * @param mixed                  $handler handler
     * @param array                  $info    route info
     *
     * @return array
     */
    private function bindParams(ServerRequestInterface $request, $handler, array $info)
    {
        if (is_array($handler)) {
            list($controller, $method) = $handler;
            $reflectMethod = new \ReflectionMethod($controller, $method);
            $reflectParams = $reflectMethod->getParameters();
        } else {
            $reflectMethod = new \ReflectionFunction($handler);
            $reflectParams = $reflectMethod->getParameters();
        }

        $bindParams = [];
        $matches    = $info['matches'] ?? [];
        $response   = RequestContext::getResponse();

        // binding params
        foreach ($reflectParams as $key => $reflectParam) {
            $type = $reflectParam->getType()->__toString();
            $name = $reflectParam->getName();
            if ($type == Request::class) {
                $bindParams[$key] = $request;
            } elseif ($type == Response::class) {
                $bindParams[$key] = $response;
            } elseif (isset($matches[$name])) {
                $bindParams[$key] = $this->parserParamType($type, $matches[$name]);
            } else {
                throw new RuntimeException("$method has not the argument of $name");
            }
        }

        return $bindParams;
    }

    /**
     * parser the type of binding param
     *
     * @param string $type  the type of param
     * @param mixed  $value the value of param
     *
     * @return bool|float|int|string
     */
    private function parserParamType(string $type, $value)
    {
        switch ($type) {
            case 'int':
                $value = (int)$value;
                break;
            case 'string':
                $value = (string)$value;
                break;
            case 'bool':
                $value = (bool)$value;
                break;
            case 'float':
                $value = (float)$value;
                break;
            case 'double':
                $value = (double)$value;
                break;
        }

        return $value;
    }
}