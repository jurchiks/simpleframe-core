<?php
namespace simpleframe;

use Exception;
use js\tools\commons\exceptions\LogException;
use js\tools\commons\http\Request;
use js\tools\commons\http\Uri;
use js\tools\commons\templating\Engine;
use RuntimeException;
use simpleframe\responses\ErrorResponse;
use simpleframe\responses\ExceptionResponse;
use simpleframe\responses\TemplateResponse;
use simpleframe\routing\exceptions\RouteNotFoundException;
use simpleframe\routing\exceptions\RouteParameterException;
use simpleframe\routing\Router;
use Throwable;
use UnexpectedValueException;

final class App
{
	/** @var callable[] */
	private static $exceptionHandlers = [];
	/** @var callable[] */
	private static $shutdownHandlers = [];
	/** @var callable[] */
	private static $consoleHandlers = [];
	
	/**
	 * @param string $exceptionClass : the full name of the exception to handle, e.g.\name\space\Exception::class
	 * @param callable $handler : the handler to call if an uncaught exception of the specified class has been thrown;
	 * must return a boolean value - false if handling is to be passed to the framework, true if all work is done in
	 *     the handler
	 */
	public static function registerExceptionHandler(string $exceptionClass, callable $handler)
	{
		self::$exceptionHandlers[$exceptionClass] = $handler;
	}
	
	public static function registerShutdownHandler(callable $handler)
	{
		self::$shutdownHandlers[] = $handler;
	}
	
	public static function registerConsoleHandler(string $handler, string $command = null)
	{
		if ($command === null)
		{
			if (!class_exists($handler)
				|| !isset(class_parents($handler)[ConsoleHandler::class])
			)
			{
				throw new RuntimeException('Invalid console handler, only callables and classes allowed');
			}
			
			$handlers = $handler::listMethods(); // PHPStorm warns about this, but it is actually supported by PHP
			
			foreach ($handlers as $handler)
			{
				if (isset(self::$consoleHandlers[$handler[1]]))
				{
					throw new RuntimeException('Duplicate console handler for command ' . $handler[1]);
				}
				
				self::$consoleHandlers[$handler[1]] = $handler;
			}
		}
		else
		{
			if (!is_callable($handler))
			{
				throw new RuntimeException('Invalid console handler, only callables and classes allowed');
			}
			
			if (isset(self::$consoleHandlers[$command]))
			{
				throw new RuntimeException('Duplicate console handler for command ' . $command);
			}
			
			self::$consoleHandlers[$command] = $handler;
		}
	}
	
	public static function getTemplatingEngine()
	{
		static $engine = null;
		
		if ($engine === null)
		{
			$engine = new Engine(ROOT_DIR . '/app/templates/');
			$engine->addRoot(__DIR__ . '/templates/');
		}
		
		return $engine;
	}
	
	public static function getRequest(): Request
	{
		static $request = null;
		
		if ($request === null)
		{
			$data = [];
			
			if (isset($GLOBALS['argc']) && ($GLOBALS['argc'] > 0))
			{
				$argv = $GLOBALS['argv'];
				$i = 1;
				$method = $argv[$i];
				
				if ($argv[$i + 1] === '-s')
				{
					$secure = true;
					$i++;
				}
				else
				{
					$secure = false;
				}
				
				$route = $argv[$i + 1];
				
				if (isset($argv[$i + 2]))
				{
					parse_str($argv[$i + 2], $data);
				}
				
				$url = 'http' . ($secure ? 's' : '') . '://';
				$url .= Config::getString('host', 'domain.tld');
				$url .= '/' . trim($route, '/');
				
				$request = new Request($method, new Uri($url), $data, [], '');
			}
			else
			{
				$request = Request::createFromGlobals();
			}
		}
		
		return $request;
	}
	
	/**
	 * @param string $rootDir : the root directory of the project
	 */
	public static function run(string $rootDir)
	{
		if (!is_dir($rootDir . '/app/'))
		{
			throw new RuntimeException('Invalid root directory, must contain /app/');
		}
		
		define('ROOT_DIR', $rootDir);
		define('LOG_DIR', ROOT_DIR . '/logs');
		
		self::init();
		
		// user initializers, event bindings, etc
		if (file_exists(ROOT_DIR . '/app/bootstrap.php'))
		{
			require ROOT_DIR . '/app/bootstrap.php';
		}
		
		// routes
		if (file_exists(ROOT_DIR . '/app/routes.php'))
		{
			require ROOT_DIR . '/app/routes.php';
		}
		else
		{
			throw new RuntimeException('No routes defined, what do you expect to see here?');
		}
		
		$timezone = Config::get('timezone');
		
		if (!empty($timezone))
		{
			date_default_timezone_set($timezone);
		}
		
		self::render();
	}
	
	private static function init()
	{
		set_exception_handler(
			function ($e)
			{
				if (isset(self::$exceptionHandlers[get_class($e)]))
				{
					$handler = self::$exceptionHandlers[get_class($e)];
					
					if ($handler($e))
					{
						return;
					}
				}
				
				if ($e instanceof RouteNotFoundException)
				{
					// exception for a special exception; if a user mistypes a URL, show a 404 not found page.
					(new ErrorResponse(new TemplateResponse('page_not_found', ['exception' => $e]), 404))->render();
					
					return;
				}
				
				if ($e instanceof RouteParameterException)
				{
					$data = ['exception' => $e];
					(new ErrorResponse(new TemplateResponse('route_invalid_parameters', $data), 404))->render();
					
					return;
				}
				
				if ($e instanceof UnexpectedValueException)
				{
					$trace = $e->getTrace();
					
					if (isset($trace[0]['class'])
						&& ($trace[0]['class'] === LogException::class)
						&& (stripos($e->getMessage(), 'check permissions') !== false)
					)
					{
						error_log('Cannot write to app log files, please check permissions for ' . LOG_DIR);
						(new ExceptionResponse($e))->render();
						
						return;
					}
				}
				
				Logger::log(Logger::CRITICAL, strval($e));
				(new ExceptionResponse($e))->render();
			}
		);
		
		set_error_handler(
			function ($errno, $errstr, $file, $line)
			{
				static $logLevels = [
					E_NOTICE            => Logger::NOTICE,
					E_USER_NOTICE       => Logger::NOTICE,
					E_STRICT            => Logger::NOTICE,
					E_DEPRECATED        => Logger::NOTICE,
					E_USER_DEPRECATED   => Logger::NOTICE,
					E_WARNING           => Logger::WARNING,
					E_USER_WARNING      => Logger::WARNING,
					E_ERROR             => Logger::ERROR,
					E_RECOVERABLE_ERROR => Logger::ERROR,
					E_USER_ERROR        => Logger::ERROR,
				];
				
				$message = 'Error in file ' . $file . ', line ' . $line . ':' . PHP_EOL //
					. $errstr . ' (code: ' . $errno . ')';
				
				Logger::log($logLevels[$errno] ?? Logger::ERROR, $message);
				
				(new ErrorResponse($message))->render();
				
				if (isset($logLevels[$errno]) && ($logLevels[$errno] === Logger::NOTICE))
				{
					// notice level errors usually don't break code, so we continue;
					// it has been logged and displayed already anyway
					return false;
				}
				
				return true;
			}
		);
		
		register_shutdown_function(
			function ()
			{
				/**
				 * @param Exception|Throwable $e
				 */
				$log = function ($e)
				{
					Logger::log(
						Logger::CRITICAL,
						sprintf(
							"%s thrown in shutdown handler: %s\n%s",
							get_class($e),
							$e->getMessage(),
							$e->getTraceAsString()
						)
					);
				};
				
				foreach (self::$shutdownHandlers as $handler)
				{
					try
					{
						$handler();
					}
					catch (Throwable $t)
					{
						$log($t);
					}
				}
			}
		);
	}
	
	private static function render()
	{
		if (isset($GLOBALS['argc']) && ($GLOBALS['argc'] > 0))
		{
			$argv = ($GLOBALS['argv'] ?? []);
			
			if (!isset($argv[1], $argv[2]) || !in_array($argv[1], Request::METHODS))
			{
				if (isset(self::$consoleHandlers[$argv[1]]))
				{
					(self::$consoleHandlers[$argv[1]])(...array_slice($argv, 2));
					exit();
				}
				
				echo 'Examples:', PHP_EOL, //
					"\tphp index.php method path[ data]", PHP_EOL, //
					"\tphp index.php get /foo", PHP_EOL, //
					"\tphp index.php get -s /foo/bar a=1&b=2", PHP_EOL, //
					"\tphp index.php post /foo/bar a=1&b=2", PHP_EOL, //
					"\tphp index.php delete /foo/bar a=1&b=2", PHP_EOL, //
					"\t OR", PHP_EOL, //
					"\tphp index.php command[ arguments]";
				exit(1);
			}
		}
		
		Router::render(self::getRequest());
	}
}
