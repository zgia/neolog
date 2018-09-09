<?php

namespace Neo\NeoLog;

use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\LogstashFormatter;
use Monolog\Handler\RedisHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;

/**
 * Redis 连接参数
 */
// Host
if (!defined('NEOLOG_REDIS_HOST'))
{
	define('NEOLOG_REDIS_HOST', '127.0.0.1');
}
// Port
if (!defined('NEOLOG_REDIS_PORT'))
{
	define('NEOLOG_REDIS_PORT', 6379);
}
// Timeout, second
if (!defined('NEOLOG_REDIS_TIMEOUT'))
{
	define('NEOLOG_REDIS_TIMEOUT', 1);
}
// Password
if (!defined('NEOLOG_REDIS_PASSWORD'))
{
	define('NEOLOG_REDIS_PASSWORD', '');
}
// DB Index
if (!defined('NEOLOG_REDIS_DBINDEX'))
{
	define('NEOLOG_REDIS_DBINDEX', 0);
}

/**
 * 日志级别
 */
// DEBUG = 100;
// INFO = 200;
// NOTICE = 250;
// WARNING = 300;
// ERROR = 400;
// CRITICAL = 500;
// ALERT = 550;
// EMERGENCY = 600;
if (!defined('NEOLOG_LOGGER_LEVEL'))
{
	define('NEOLOG_LOGGER_LEVEL', 200);
}

/**
 * 每个进程的唯一日志ID
 */
if (!defined('NEOLOG_LOGGER_ID'))
{
	define('NEOLOG_LOGGER_ID',
	       sha1(uniqid('',
	                   true) . str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
	                                                  16))));
}

/**
 * 日志时区
 */
if (!defined('NEOLOG_LOGGER_TIMEZONE'))
{
	define('NEOLOG_LOGGER_TIMEZONE', 'Asia/Shanghai');
}

/**
 * Class Logger
 *
 * @package Neo\NeoLog
 */
class Logger
{
	private static $_handlers = ['Stream' => [], 'Redis' => []];

	/**
	 * @var \Redis
	 */
	private static $redis;

	/**
	 * 日志
	 *
	 * @param string $type
	 *
	 * @return MonologLogger|null
	 */
	protected static function log($type = '')
	{
		$type || $type = 'neo';

		$handlers = [];

		// 写到文件
		if (defined('NEOLOG_LOGGER_FILE') && NEOLOG_LOGGER_FILE)
		{
			$fileHandler = static::log2File($type);
			if ($fileHandler)
			{
				$handlers[] = $fileHandler;
			}
		}

		// 写到Redis
		if (defined('NEOLOG_LOGGER_REDIS') && NEOLOG_LOGGER_REDIS)
		{
			$redisHandler = static::log2RedisWithLogstash($type, 'yhdxlogstash');
			if ($redisHandler)
			{
				$handlers[] = $redisHandler;
			}
		}

		// 写到php://stderr
		if (defined('NEOLOG_LOGGER_STDERR') && NEOLOG_LOGGER_STDERR)
		{
			$streamHandler = static::log2Stream();
			if ($streamHandler)
			{
				$handlers[] = $streamHandler;
			}
		}

		if ($handlers)
		{
			$logger = new MonologLogger('neolog', $handlers);
			MonologLogger::setTimezone(new \DateTimeZone(NEOLOG_LOGGER_TIMEZONE));
		}
		else
		{
			$logger = null;
		}

		return $logger;
	}

	/**
	 * 可以传入外部定义好的Redis
	 *
	 * @param $redis
	 */
	public static function setRedis($redis)
	{
		static::$redis = $redis;
	}

	/**
	 * 获取Redis
	 *
	 * @return \Redis
	 */
	public static function getRedis()
	{
		if (!static::$redis)
		{
			static::$redis = static::initRedis();
		}

		return static::$redis;
	}

	/**
	 * 初始化Redis
	 *
	 * @return \Redis
	 */
	protected static function initRedis()
	{
		$redis = new \Redis();

		if ($redis->connect(NEOLOG_REDIS_HOST, NEOLOG_REDIS_PORT, NEOLOG_REDIS_TIMEOUT))
		{
			if (NEOLOG_REDIS_PASSWORD)
			{
				$redis->auth(NEOLOG_REDIS_PASSWORD);
			}

			if (NEOLOG_REDIS_DBINDEX)
			{
				$redis->select(NEOLOG_REDIS_DBINDEX);
			}

			return $redis;
		}
		else
		{
			return null;
		}
	}

	/**
	 * 创建一个Redis日志处理器
	 *
	 * @param string $type     日志名称
	 * @param string $rediskey Redis key
	 *
	 * @return \Monolog\Handler\AbstractHandler
	 */
	protected static function log2RedisWithLogstash($type, $rediskey = 'neologstash')
	{
		if (array_key_exists($type, static::$_handlers['Redis']))
		{
			return static::$_handlers['Redis'][$type];
		}
		else
		{
			$logRedis = static::getRedis();
			if (!$logRedis)
			{
				return null;
			}

			$redisHandler = new RedisHandler($logRedis, $rediskey, NEOLOG_LOGGER_LEVEL);
			$formatter    = new NeoLogRedisLogstashFormatter($type, $rediskey, null, '');
			$redisHandler->setFormatter($formatter);
			$redisHandler->pushProcessor(new NeoLogRedisProcessor());

			static::$_handlers['Redis'][$type] = $redisHandler;

			return $redisHandler;
		}
	}

	/**
	 * 创建一个文件日志处理器
	 *
	 * @param string $type 文件名，可以加上前缀@
	 *                     如果加上@，表示创建的日志文件无日期，即放到一个文件呢
	 *                     如果没有@，表示每天创建一个日志文件
	 *
	 * @return \Monolog\Handler\AbstractHandler
	 */
	protected static function log2File($type)
	{
		// 日志目录
		$neo_logger_dir = static::getFileLogDir();

		if (!file_exists($neo_logger_dir))
		{
			@mkdir($neo_logger_dir, 0777, true);
		}

		// 如果日志名以@开头,则使用一个日志文件
		if (strpos($type, '@') === 0)
		{
			$key = substr($type, 1);
		}
		else
		{
			$key = $type . '/' . @ gmdate('Ymd');

			if (!file_exists($neo_logger_dir . '/' . $type))
			{
				@mkdir($neo_logger_dir . '/' . $type, 0777, true);
			}
		}

		if (array_key_exists($key, static::$_handlers['Stream']))
		{
			return static::$_handlers['Stream'][$key];
		}
		else
		{
			$stream = new StreamHandler($neo_logger_dir . '/' . $key . '.log', NEOLOG_LOGGER_LEVEL);

			$SIMPLE_FORMAT = "[%loggertime%] %channel%.%level_name% %loggerid% %message% %context% %extra% %line%" . PHP_EOL;
			$stream->setFormatter(new LineFormatter($SIMPLE_FORMAT));
			$stream->pushProcessor(new NeoLogFileProcessor());

			static::$_handlers['Stream'][$key] = $stream;

			return $stream;
		}
	}


	/**
	 * 创建一个流日志处理器
	 *
	 * @param string $type php://stderr
	 *
	 * @return \Monolog\Handler\AbstractHandler
	 */
	protected static function log2Stream($type = 'stderr')
	{
		$key = 'php_iostream_' . $type;

		if (array_key_exists($key, static::$_handlers['Stream']))
		{
			return static::$_handlers['Stream'][$key];
		}
		else
		{
			$stream = new StreamHandler('php://' . $type, NEOLOG_LOGGER_LEVEL);

			$SIMPLE_FORMAT = "[%loggertime%] %channel%.%level_name% %loggerid% %message% %context% %extra% %line%" . PHP_EOL;
			$stream->setFormatter(new LineFormatter($SIMPLE_FORMAT));

			static::$_handlers['Stream'][$key] = $stream;

			return $stream;
		}
	}

	/**
	 * 统一日志记录入口
	 *
	 * @param string $action
	 * @param string $type
	 * @param string $message
	 * @param mixed  $context
	 */
	protected static function logit($action, $type, $message, $context)
	{
		static $number = 0;

		try
		{
			$logger = static::log($type);
			if ($logger == null)
			{
				return;
			}

			if (!is_array($context))
			{
				$context = (array) $context;
			}

			$context['line'] = static::getLogLine($number);

			$logger->$action($message, $context);

		} catch (\Exception $ex)
		{
			$args['action']  = $action;
			$args['type']    = $type;
			$args['message'] = $message;
			$args['context'] = $context;

			$msg = static::formatLongDate() . "\t" . NEOLOG_LOGGER_ID . "\t" . json_encode($args,
			                                                                               JSON_UNESCAPED_UNICODE) . PHP_EOL . PHP_EOL;

			error_log($msg, 3, static::getFileLogDir() . '/neologerror.log');

			unset($args, $msg);
		}
	}

	/**
	 * 获取日志记录在文件中的启示位置
	 *
	 * @param int $number
	 *
	 * @return string
	 */
	protected static function getLogLine(&$number)
	{
		$backtraces = debug_backtrace((PHP_VERSION_ID < 50306) ? 2 : DEBUG_BACKTRACE_IGNORE_ARGS, 3);
		$backtrace  = $backtraces[count($backtraces) - 1];

		$abspath = substr($backtraces[0]['file'], 0, stripos($backtraces[0]['file'], 'vendor/zgia/'));

		return 'No.' . ($number ++) . str_ireplace($abspath,
		                                           '/',
		                                           $backtrace['file']) . ':' . (int) $backtrace['line'];

	}

	/**
	 * Adds a log record at the DEBUG level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $type    The log type
	 * @param  string $message The log message
	 * @param  mixed  $context The log context
	 *
	 */
	public static function debug($type, $message, $context = null)
	{
		static::logit('debug', $type, $message, $context);
	}

	/**
	 * Adds a log record at the INFO level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $type    The log type
	 * @param  string $message The log message
	 * @param  mixed  $context The log context
	 *
	 */
	public static function info($type, $message, $context = null)
	{
		static::logit('info', $type, $message, $context);
	}

	/**
	 * Adds a log record at the NOTICE level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $type    The log type
	 * @param  string $message The log message
	 * @param  mixed  $context The log context
	 *
	 */
	public static function notice($type, $message, $context = null)
	{
		static::logit('notice', $type, $message, $context);
	}

	/**
	 * Adds a log record at the WARNING level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $type    The log type
	 * @param  string $message The log message
	 * @param  mixed  $context The log context
	 *
	 */
	public static function warn($type, $message, $context = null)
	{
		static::logit('warn', $type, $message, $context);
	}

	/**
	 * Adds a log record at the WARNING level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $type    The log type
	 * @param  string $message The log message
	 * @param  mixed  $context The log context
	 *
	 */
	public static function warning($type, $message, $context = null)
	{
		static::logit('warning', $type, $message, $context);
	}

	/**
	 * Adds a log record at the ERROR level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $type    The log type
	 * @param  string $message The log message
	 * @param  mixed  $context The log context
	 *
	 */
	public static function error($type, $message, $context = null)
	{
		static::logit('error', $type, $message, $context);
	}

	/**
	 * Adds a log record at the CRITICAL level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $type    The log type
	 * @param  string $message The log message
	 * @param  mixed  $context The log context
	 *
	 */
	public static function crit($type, $message, $context = null)
	{
		static::logit('crit', $type, $message, $context);
	}

	/**
	 * Adds a log record at the ALERT level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $type    The log type
	 * @param  string $message The log message
	 * @param  mixed  $context The log context
	 *
	 */
	public static function alert($type, $message, $context = null)
	{
		static::logit('alert', $type, $message, $context);
	}

	/**
	 * Adds a log record at the EMERGENCY level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $type    The log type
	 * @param  string $message The log message
	 * @param  mixed  $context The log context
	 *
	 */
	public static function emerg($type, $message, $context = null)
	{
		static::logit('emerg', $type, $message, $context);
	}

	/**
	 * Adds a log record at the EMERGENCY level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $type    The log type
	 * @param  string $message The log message
	 * @param  mixed  $context The log context
	 *
	 */
	public static function emergency($type, $message, $context = null)
	{
		static::logit('emergency', $type, $message, $context);
	}

	/**
	 * 获取文件日志目录
	 *
	 * @return string
	 */
	public static function getFileLogDir()
	{
		return (defined('NEOLOG_LOGGER_DIR') && NEOLOG_LOGGER_DIR) ? NEOLOG_LOGGER_DIR : '/tmp/logs';
	}

	/**
	 * 输出格式化后的时间串
	 *
	 * @param int $time
	 *
	 * @return false|string
	 */
	public static function formatLongDate($time = 0)
	{
		$time || $time = time();

		$ts = new \DateTime(null, new \DateTimeZone(NEOLOG_LOGGER_TIMEZONE));
		$ts->setTimestamp($time);

		return $ts->format('Y-m-d H:i:s');
	}

	/**
	 * 返回带毫秒的时间串
	 *
	 * @return string
	 */
	public static function formatMicrotime()
	{
		list($usec, $sec) = explode(" ", microtime());

		return static::formatLongDate($sec) . '.' . substr($usec, 2);
	}
}

/**
 * Class NeoLogProcessor
 * @package Neo
 */
class NeoLogProcessor
{
	/**
	 * 添加更多内容
	 *
	 * @param array $record
	 *
	 * @return array
	 */
	public function more(array $record)
	{
		$record['loggerid'] = NEOLOG_LOGGER_ID;

		$record['loggertime'] = Logger::formatMicrotime();

		$record['line'] = $record['context']['line'];
		unset($record['context']['line']);

		$record['extra']['yhdx_host'] = $_SERVER['YHDX_HOST'];

		return $record;
	}
}

/**
 * Class NeoLogFileProcessor
 * @package Neo
 */
class NeoLogFileProcessor extends NeoLogProcessor
{
	/**
	 * @param  array $record
	 *
	 * @return array
	 */
	public function __invoke(array $record)
	{
		return $this->more($record);
	}
}

/**
 * Class NeoLogFileProcessor
 * @package Neo
 */
class NeoLogRedisProcessor extends NeoLogProcessor
{
	/**
	 * @param  array $record
	 *
	 * @return array
	 */
	public function __invoke(array $record)
	{
		return $this->more($record);
	}
}

/**
 * Class NeoLogRedisLogstashFormatter
 * @package Neo
 */
class NeoLogRedisLogstashFormatter extends LogstashFormatter
{
	/**
	 * @param array $record
	 *
	 * @return array
	 */
	protected function formatV0(array $record)
	{
		$message = parent::formatV0($record);

		if (isset($record['loggerid']))
		{
			$message['@loggerid'] = $record['loggerid'];
		}

		$message['@loggertime'] = Logger::formatMicrotime();

		if (isset($record['line']))
		{
			$message['@fileline'] = $record['line'];
		}

		return $message;
	}
}
