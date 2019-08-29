<?php

namespace app;

use mysql_xdevapi\Exception;

class Application
{
    private $basePath = 'console/actions';
    private $actionMap = [];
    private $_params = [];

    public function __construct($config = [])
    {
        if (isset($config['actionMap'])) {
            $this->actionMap = $config['actionMap'];
        }
        if (isset($_SERVER['argv'])) {
            $this->_params = $_SERVER['argv'];
            array_shift($this->_params);
        } else {
            $this->_params = [];
        }
        return $this->_params;
    }

    private function resolve()
    {
        $rawParams = $this->_params;
        $endOfOptionsFound = false;
        if (isset($rawParams[0])) {
            $route = array_shift($rawParams);

            if ($route === '--') {
                $endOfOptionsFound = true;
                $route = array_shift($rawParams);
            }
        } else {
            $route = '';
        }

        $params = [];
        $prevOption = null;
        foreach ($rawParams as $param) {
            if ($endOfOptionsFound) {
                $params[] = $param;
            } elseif ($param === '--') {
                $endOfOptionsFound = true;
            } elseif (preg_match('/^--([\w-]+)(?:=(.*))?$/', $param, $matches)) {
                $name = $matches[1];
                if (is_numeric(substr($name, 0, 1))) {
                    throw new \Exception('Parameter "' . $name . '" is not valid');
                }

                $params[$name] = isset($matches[2]) ? $matches[2] : true;
                $prevOption = &$params[$name];
            } elseif (preg_match('/^-([\w-]+)(?:=(.*))?$/', $param, $matches)) {
                $name = $matches[1];
                if (is_numeric($name)) {
                    $params[] = $param;
                } else {
                    $params['_aliases'][$name] = isset($matches[2]) ? $matches[2] : true;
                    $prevOption = &$params['_aliases'][$name];
                }
            } elseif ($prevOption === true) {
                // `--option value` syntax
                $prevOption = $param;
            } else {
                $params[] = $param;
            }
        }

        return [$route, $params];
    }


    public function run()
    {
        try {
            list($route, $params) = $this->resolve();
            $aRoute = explode('/', $route);
            if(isset($this->actionMap[$aRoute[0]])){
                $action = new $this->actionMap[$aRoute[0]]['namespace']();
                $method = $aRoute[1];
                if(method_exists($action, $method)){
                        $action->$method($params);
                }else{
                    throw new \Exception('called method in action "'.$aRoute[0].'" not found');
                }
            }else{
                throw new \Exception('called action not found');
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    private function runAction($route, $params)
    {
        $aRoute = explode('/', $route);
        $aRoute[0] = $this->_actionNamespace . $aRoute[0];
        $aRoute[1] = 'action' . $aRoute[1];
        if (is_callable($aRoute, false, $return)) {
        } else {
            throw new \Exception('called method not found');
        }

    }
}