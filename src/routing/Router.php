<?php
namespace simpleframe\routing;

use js\tools\commons\http\Request;
use js\tools\commons\http\Uri;
use simpleframe\App;
use simpleframe\routing\exceptions\RouteNotFoundException;

class Router
{
	/** @var Route[] */
	private static $routes = [];
	
	public static function addRoute(string $name, string $url, callable $handler, array $methods = [])
	{
		// using name as key only to prevent multiple routes with the same name
		self::$routes[$name] = (new Route($url, $handler, $name))
			->setAcceptedMethods(...$methods);
	}
	
	public static function addRoutes(Route... $routes)
	{
		foreach ($routes as $route)
		{
			self::$routes[$route->getName()] = $route;
		}
	}
	
	public static function render(Request $request)
	{
		// sort routes only when rendering instead of on every route addition
		uasort(
			self::$routes,
			function (Route $a, Route $b)
			{
				// sort by route length descending
				return (strlen($b->getUrl()) <=> strlen($a->getUrl()));
			}
		);
		
		foreach (self::$routes as $route)
		{
			if ($route->render($request))
			{
				return;
			}
		}
		
		throw new RouteNotFoundException();
	}
	
	/**
	 * @param string $name : the name of the route to link
	 * @param array $namedParams : a map of parameter names => values
	 * @return null|Uri the link to the named route with the parameters replaced, or null if no route was found
	 */
	public static function link(string $name, array $namedParams = [])
	{
		if (isset(self::$routes[$name]))
		{
			$route = self::$routes[$name]->generateLink($namedParams);
			$uri = App::getRequest()->getUri()->copy()
				->setPath($route)
				->setQuery('');
			
			if (self::$routes[$name]->getRequireHttps())
			{
				$uri->setScheme('https');
			}
			
			return $uri;
		}
		
		return null;
	}
}
