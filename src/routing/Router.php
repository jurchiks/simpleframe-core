<?php
namespace simpleframe\routing;

use simpleframe\Request;
use simpleframe\routing\exceptions\RouteNotFoundException;

class Router
{
	/**
	 * Return the full URL with protocol and domain name.
	 */
	const LINK_ABSOLUTE = 1;
	/**
	 * Set the link's protocol to HTTPS.
	 */
	const LINK_SECURE = 2;
	/**
	 * Set the link's protocol to HTTP.
	 */
	const LINK_INSECURE = 4;
	
	/** @var Route[] */
	private static $routes = [];
	
	public static function addRoute(string $name, string $url, callable $handler, array $methods = [])
	{
		// using name as key only to prevent multiple routes with the same name
		self::$routes[$name] = new Route($url, $handler, $name, $methods);
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
	 * @param array $getParams : GET parameters to append to the query
	 * @param int $flags : a bitmask of {@link Router::LINK_ABSOLUTE}, {@link Router::LINK_SECURE}
	 * and {@link Router::LINK_INSECURE}.
	 * If neither of LINK_SECURE and LINK_INSECURE is specified, the URL will have the same protocol as the current URL;
	 * however, if one of these is provided, and:
	 * a) the page already has the required protocol and there is no ABSOLUTE flag, then the link will not be absolute;
	 * b) if the protocol is different from the required, the link will be absolute regardless of the ABSOLUTE flag.
	 * @return null|string the link to the named route with the parameters replaced, or null if no route was found
	 */
	public static function link(string $name, array $namedParams = [], array $getParams = [], int $flags = 0)
	{
		if (isset(self::$routes[$name]))
		{
			$route = self::$routes[$name]->generateLink($namedParams);
			
			if (empty($getParams))
			{
				$query = '';
			}
			else
			{
				$query = '?' . http_build_query($getParams, '', '&');
			}
			
			$isLinkExplicitlyAbsolute = ($flags & self::LINK_ABSOLUTE === self::LINK_ABSOLUTE);
			$isProtocolExplicitlySecure = ($flags & self::LINK_SECURE === self::LINK_SECURE);
			$isProtocolExplicitlyInsecure = ($flags & self::LINK_INSECURE === self::LINK_INSECURE);
			$isCurrentPageSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
			$isLinkProtocolSecure = ($isProtocolExplicitlySecure
				? true
				: ($isProtocolExplicitlyInsecure
					? false
					: $isCurrentPageSecure));
			$isFullUrlRequired = ($isLinkExplicitlyAbsolute || ($isCurrentPageSecure !== $isLinkProtocolSecure));
			$prefix = '';
			
			if ($isFullUrlRequired)
			{
				$protocol = 'http';
				
				if ($isLinkProtocolSecure)
				{
					$protocol .= 's';
				}
				
				$prefix = $protocol . '//' . $_SERVER['HTTP_HOST'];
			}
			
			return $prefix . $route . $query;
		}
		
		return null;
	}
}
