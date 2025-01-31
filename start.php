<?php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/app/common/ICheck.php';
use Workerman\Worker;
use Workerman\Protocols\Http;
use Workerman\Connection\TcpConnection;
use Webman\App;
use Webman\Config;
use Webman\Route;
use Webman\Middleware;
use Dotenv\Dotenv;
use support\Request;
use support\bootstrap\Log;
use support\bootstrap\Container;
use app\common\ICheck;

//检查扩展与函数
if (class_exists('app\\common\\ICheck')) {
    $currentOs = \DIRECTORY_SEPARATOR === '\\' ? 1 : 2;
    ICheck::analysis($currentOs);
} else {
    exit('Class ICheck not found'.PHP_EOL);
}
//不存在env时，复制一份
is_file(__DIR__ . DIRECTORY_SEPARATOR . '.env') || copy(__DIR__ . DIRECTORY_SEPARATOR . '.env.example', __DIR__ . DIRECTORY_SEPARATOR . '.env');
//检查db_path目录
is_dir(db_path()) or mkdir(db_path(), 0777, true);
is_writable(db_path()) or exit('错误：'.db_path().'目录无写入权限，程序终止！');

echo microtime(true).'  检查配置，是否同时监听IPv6...'.PHP_EOL;
$listen_ipv6 = false;
$default_config_file = db_path() . '/default.json';
if (is_file($default_config_file)) {
    $default_config = file_get_contents($default_config_file);
    $conf = json_decode($default_config, true);
    $listen_ipv6 = isset($conf['listen_ipv6']);
    if ($listen_ipv6) {
        echo microtime(true).'  您设置同时监听IPv6，Windows系统本机访问URL为http://localhost:8787'.PHP_EOL;
    } else {
        echo microtime(true).'  未监听IPv6，如果您有公网IPv6地址，可以打开监听[ IYUUPlus -> 系统设置 -> 常规设置 -> 监听IPv6 ]'.PHP_EOL;
    }
} else {
    echo microtime(true).'  未检测到常规配置JSON文件。'.PHP_EOL;
}

if (method_exists('Dotenv\Dotenv', 'createUnsafeImmutable')) {
    Dotenv::createUnsafeImmutable(base_path())->load();
} else {
    Dotenv::createMutable(base_path())->load();
}

Config::load(config_path(), ['route', 'container']);
$config = config('server');

if ($timezone = config('app.default_timezone')) {
    date_default_timezone_set($timezone);
}

Worker::$onMasterReload = function (){
    if (function_exists('opcache_get_status')) {
        if ($status = opcache_get_status()) {
            if (isset($status['scripts']) && $scripts = $status['scripts']) {
                foreach (array_keys($scripts) as $file) {
                    opcache_invalidate($file, true);
                }
            }
        }
    }
};
Worker::$logFile                      = $config['log_file'] ?? '';
Worker::$pidFile                      = $config['pid_file'];
Worker::$stdoutFile                   = $config['stdout_file'];
TcpConnection::$defaultMaxPackageSize = $config['max_package_size'] ?? 10*1024*1024;

$worker = new Worker($listen_ipv6 ? 'http://[::]:8787' : $config['listen'], $config['context']);
$property_map = [
    'name',
    'count',
    'user',
    'group',
    'reusePort',
    'transport',
];
foreach ($property_map as $property) {
    if (isset($config[$property])) {
        $worker->$property = $config[$property];
    }
}

$worker->onWorkerStart = function ($worker) {
    set_error_handler(function ($level, $message, $file = '', $line = 0, $context = []) {
        if (error_reporting() & $level) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }
    });
    register_shutdown_function(function ($start_time) {
        if (time() - $start_time <= 1) {
            sleep(1);
        }
    }, time());
    foreach (config('autoload.files', []) as $file) {
        include_once $file;
    }
    if (method_exists('Dotenv\Dotenv', 'createUnsafeImmutable')) {
        Dotenv::createUnsafeImmutable(base_path())->load();
    } else {
        Dotenv::createMutable(base_path())->load();
    }
    Config::reload(config_path(), ['route', 'container']);
    foreach (config('bootstrap', []) as $class_name) {
        /** @var \Webman\Bootstrap $class_name */
        $class_name::start($worker);
    }
    $app = new App($worker, Container::instance(), Log::channel('default'), app_path(), public_path());
    Route::load(config_path() . '/route.php');
    Middleware::load(config('middleware', []));
    Middleware::load(['__static__' => config('static.middleware', [])]);
    Http::requestClass(Request::class);

    $worker->onMessage = [$app, 'onMessage'];
};

if (!isWin()) {
    foreach (config('process', []) as $process_name => $config) {
        $worker = new Worker($config['listen'] ?? null, $config['context'] ?? []);
        $property_map = [
            'count',
            'user',
            'group',
            'reloadable',
            'reusePort',
            'transport',
            'protocol',
        ];
        $worker->name = $process_name;
        foreach ($property_map as $property) {
            if (isset($config[$property])) {
                $worker->$property = $config[$property];
            }
        }

        $worker->onWorkerStart = function ($worker) use ($config) {
            foreach (config('autoload.files', []) as $file) {
                include_once $file;
            }
            Dotenv::createMutable(base_path())->load();
            Config::reload(config_path(), ['route']);

            $bootstrap = $config['bootstrap'] ?? config('bootstrap', []);
            if (!in_array(support\bootstrap\Log::class, $bootstrap)) {
                $bootstrap[] = support\bootstrap\Log::class;
            }
            foreach ($bootstrap as $class_name) {
                /** @var \Webman\Bootstrap $class_name */
                $class_name::start($worker);
            }

            foreach ($config['services'] ?? [] as $server) {
                if (!class_exists($server['handler'])) {
                    echo "process error: class {$server['handler']} not exists\r\n";
                    continue;
                }
                $listen = new Worker($server['listen'] ?? null, $server['context'] ?? []);
                if (isset($server['listen'])) {
                    echo "listen: {$server['listen']}\n";
                }
                $class = Container::make($server['handler'], $server['constructor'] ?? []);
                worker_bind($listen, $class);
                $listen->listen();
            }

            if (isset($config['handler'])) {
                if (!class_exists($config['handler'])) {
                    echo "process error: class {$config['handler']} not exists\r\n";
                    return;
                }

                $class = Container::make($config['handler'], $config['constructor'] ?? []);
                worker_bind($worker, $class);
            }

        };
    }
}

Worker::runAll();
