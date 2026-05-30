<?php

declare(strict_types=1);

namespace Bitrix\Main\Config;

class Option
{
    public static function get(string $moduleId, string $name, string $default = '', string $siteId = ''): string
    {
        return $default;
    }

    public static function set(string $moduleId, string $name, string $value, string $siteId = ''): void
    {
    }
}

namespace Bitrix\Main\Web;

class Json
{
    public static function encode($data, $options = 0): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | (int) $options) ?: '{}';
    }

    public static function decode(string $json)
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}

class HttpClient
{
    private int $status = 0;
    /** @var array<string, string> */
    private array $headers = [];

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
    }

    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function get(string $url)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Расширение curl не включено в PHP');
        }
        $ch = curl_init($url);
        $curlHeaders = [];
        foreach ($this->headers as $k => $v) {
            $curlHeaders[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $result = curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000 && is_resource($ch)) {
            curl_close($ch);
        } else {
            unset($ch);
        }

        return $result !== false ? $result : false;
    }
}

namespace Bitrix\Main\Data;

class Cache
{
    public static function createInstance(): self
    {
        return new self();
    }

    public function initCache($ttl, $uniqueId, $initDir = ''): bool
    {
        return false;
    }

    public function getVars(): array
    {
        return [];
    }

    public function startDataCache(): bool
    {
        return true;
    }

    public function endDataCache($vars = false): void
    {
    }
}

namespace Bitrix\Main\HttpRequest;

class Request
{
    public function getPostList(): array
    {
        return $_POST;
    }
}

namespace Bitrix\Main;

use Bitrix\Main\HttpRequest\Request;

class Loader
{
    /** @var array<string, string> */
    private static array $classMap = [];

    public static function registerAutoLoadClasses(string $moduleId, array $classes): void
    {
        self::$classMap = array_merge(self::$classMap, $classes);
        static $registered = false;
        if (!$registered) {
            spl_autoload_register([self::class, 'autoload'], true, true);
            $registered = true;
        }
    }

    public static function autoload(string $class): void
    {
        if (!isset(self::$classMap[$class])) {
            return;
        }
        $file = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/draxter.aichat/' . self::$classMap[$class];
        if (is_file($file)) {
            require_once $file;
        }
    }

    public static function includeModule(string $moduleId): bool
    {
        if ($moduleId !== 'draxter.aichat') {
            return false;
        }
        $path = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/draxter.aichat/include.php';
        if (!is_file($path)) {
            return false;
        }
        require_once $path;

        return true;
    }
}

class Context
{
    private static ?self $instance = null;
    private Request $request;

    private function __construct()
    {
        $this->request = new Request();
    }

    public static function getCurrent(): self
    {
        return self::$instance ??= new self();
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getSite(): ?object
    {
        return null;
    }
}

class EventManager
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function addEventHandler(string $module, string $event, callable|array $callback): void
    {
    }
}
