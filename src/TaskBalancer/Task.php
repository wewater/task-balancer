<?php
namespace Toplan\TaskBalance;

/**
 * Class Task
 * @package Toplan\TaskBalance
 */
class Task {

    /**
     * task status
     */
    const RUNNING = 'running';

    const PAUSED = 'paused';

    const FINISHED = 'finished';

    /**
     * task instance cycle life hooks
     * @var array
     */
    protected static $hooks = [
        'beforeCreateDriver',
        'afterCreateDriver',
        'ready',
        'beforeRun',
        'beforeRunDriver',
        'afterRunDriver',
        'afterRun',
    ];

    /**
     * task name
     * @var
     */
    protected $name;

    /**
     * task`s driver instances
     * @var array
     */
    protected $drivers = [];

    /**
     * task`s back up drivers name
     * @var array
     */
    protected $backupDrivers = [];

    /**
     * task status
     * @var string
     */
    protected $status = '';

    /**
     * current use driver
     * @var null
     */
    protected $currentDriver = null;

    /**
     * task work
     * @var null
     */
    protected $work = null;

    /**
     * task run time
     * @var array
     */
    protected $time = [
        'started_at' => 0,
        'finished_at' => 0
    ];

    /**
     * data for driver
     * @var null
     */
    protected $data = null;

    /**
     * drivers` results
     * @var array
     */
    protected $results = [];

    /**
     * handlers for hooks
     * @var array
     */
    protected $handlers = [];

    /**
     * constructor
     * @param               $name
     * @param               $data
     * @param \Closure|null $work
     */
    public function __construct($name, $data = null, \Closure $work = null)
    {
        $this->name = $name;
        $this->data = $data;
        $this->work = $work;
    }

    /**
     * create a new task
     * @param               $name
     * @param               $data
     * @param \Closure|null $work
     * @return Task
     */
    public static function create($name, $data = null, \Closure $work = null)
    {
        $task = new self($name, $data, $work);
        $task->runWork();
        return $task;
    }

    /**
     * run work
     */
    public function runWork()
    {
        if (is_callable($this->work)) {
            call_user_func($this->work, $this);
            $this->callHookHandler('ready');
        }
    }

    /**
     * run task
     * @param string $driverName
     *
     * @return bool
     * @throws \Exception
     */
    public function run($driverName = '')
    {
        if ($this->isRunning()) {
            //stop run because current task is running
            return false;
        }
        if (!$this->beforeRun()) {
            return false;
        }
        if (!$driverName) {
            $driverName = $this->getDriverNameByWeight();
        }
        $this->resortBackupDrivers($driverName);
        $success = $this->runDriver($driverName);
        return $this->afterRun($success);
    }

    /**
     * before run task
     * @return bool
     */
    private function beforeRun()
    {
        $pass = $this->callHookHandler('beforeRun');
        if ($pass) {
            $this->status = static::RUNNING;
            $this->time['started_at'] = microtime();
        }
        return $pass;
    }

    /**
     * after run task
     * @param $success
     *
     * @return mixed
     */
    private function afterRun($success)
    {
        $this->status = static::FINISHED;
        $this->time['finished_at'] = microtime();
        $data = $this->callHookHandler('afterRun', $this->results);
        return is_bool($data) ? $this->results : $data;
    }

    /**
     * run driver by name
     * @param $name
     *
     * @return bool
     * @throws \Exception
     */
    public function runDriver($name)
    {
        $driver = $this->getDriver($name);
        if (!$driver) {
            throw new \Exception("not found driver [$name] in task [$this->name], please define it for current task");
        }
        $this->currentDriver = $driver;
        // before run a driver,
        // but current driver value is already change to this driver.
        $this->callHookHandler('beforeRunDriver');
        // run driver
        $result = $driver->run();
        // result data
        $success = $driver->success;
        $data = [
            'driver' => $driver->name,
            'time' => $driver->time,
            'success' => $success,
            'result' => $result,
        ];
        // store data
        $this->storeDriverResult($data);
        // after run driver
        $this->callHookHandler('afterRunDriver');
        // weather to use backup driver
        if (!$success) {
            $backUpDriverName = $this->getNextBackupDriverName();
            if ($backUpDriverName) {
               // try to run a backup driver
               return $this->runDriver($backUpDriverName);
            }
            // not find a backup driver, current driver must be run false.
            return false;
        }
        return true;
    }

    /**
     * store driver run result data
     * @param $data
     */
    public function storeDriverResult($data)
    {
        if (!is_array($this->results) || !$this->results) {
            $this->results = [];
        }
        if ($data) {
            array_push($this->results, $data);
        }
    }

    /**
     * generator a back up driver`s name
     * @return null
     */
    public function getNextBackupDriverName()
    {
        $drivers = $this->backupDrivers;
        $currentDriverName = $this->currentDriver->name;
        if (!count($drivers)) {
            return null;
        }
        if (!in_array($currentDriverName, $drivers)) {
            return $drivers[0];
        }
        if (count($drivers) == 1 && array_pop($drivers) == $currentDriverName) {
            return null;
        }
        $currentKey = array_search($currentDriverName, $drivers);
        if (($currentKey + 1) < count($drivers)) {
            return $drivers[$currentKey + 1];
        }
        return null;
    }

    /**
     * get a driver`s name by drivers` weight
     * @return mixed
     * @throws \Exception
     */
    public function getDriverNameByWeight()
    {
        $count = $base = 0;
        $map = [];
        foreach ($this->drivers as $driver) {
            $count += $driver->weight;
            if ($driver->weight) {
                $max = $base + $driver->weight;
                $map[] = [
                    'min' => $base,
                    'max' => $max,
                    'driver' => $driver->name,
                ];
                $base = $max;
            }
        }
        if ($count < 1) {
            return $this->driverNameRand();
        }
        $number = mt_rand(0, $count - 1);
        foreach ($map as $data) {
            if ($number >= $data['min'] && $number < $data['max']) {
                return $data['driver'];
            }
        }
        throw new \Exception('get driver name by weight failed, something wrong');
    }

    /**
     * get a driver name
     * @return mixed
     */
    public function driverNameRand()
    {
        return array_rand(array_keys($this->drivers));
    }


    /**
     * create a new driver instance for current task
     * @return null|static
     * @throws \Exception
     */
    public function driver()
    {
        $args = func_get_args();
        if (!count($args)) {
            throw new \Exception('please give task`s method `driver` some args');
        }
        extract($this->parseDriverArgs($args));
        if (!$name) {
            throw new \Exception('please set driver`s name!');
        }
        $driver = $this->getDriver($name);
        if (!$driver) {
            $this->callHookHandler('beforeCreateDriver');
            $driver = Driver::create($this, $name, $weight, $isBackup, $work);
            $this->drivers[$name] = $driver;
            if ($isBackup) {
                $this->backupDrivers[] = $name;
            }
            $this->callHookHandler('afterCreateDriver');
        }
        return $driver;
    }

    /**
     * parse arguments for method `driver()`
     * @param $args
     *
     * @return array
     */
    private function parseDriverArgs($args)
    {
        $result = [
            'name' => '',
            'work' => null,
            'weight' => 1,
            'isBackup' => false,
        ];
        foreach ($args as $arg) {
            //find work
            if (is_callable($arg)) {
                $result['work'] = $arg;
            }
            //find weight, backup, name
            if (is_string($arg) || is_numeric($arg)) {
                $arg = preg_replace('/\s+/', ' ', "$arg");
                $subArgs = explode(' ', trim($arg));
                foreach ($subArgs as $subArg) {
                    if (preg_match('/^[0-9]+$/', $subArg)) {
                        $result['weight'] = $subArg;
                    } elseif (preg_match('/(backup)/', strtolower($subArg))) {
                        $result['isBackup'] = true;
                    } else {
                        $result['name'] = $subArg;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * current task has character driver?
     * @param $name
     *
     * @return bool
     */
    public function hasDriver($name)
    {
        if (!$this->drivers) {
            return false;
        }
        return isset($this->drivers[$name]);
    }

    /**
     * get a driver from current task drives pool
     * @param $name
     *
     * @return null
     */
    public function getDriver($name)
    {
        if ($this->hasDriver($name)) {
            return $this->drivers[$name];
        }
        return null;
    }

    /**
     * init back up drivers
     * @param $name
     */
    public function resortBackupDrivers($name)
    {
        if (count($this->backupDrivers) < 2) {
            return;
        }
        if (in_array($name, $this->backupDrivers)) {
            $key = array_search($name, $this->backupDrivers);
            unset($this->backupDrivers[$key]);
            array_unshift($this->backupDrivers, $name);
            $this->backupDrivers = array_values($this->backupDrivers);
        }
    }

    /**
     * task is running ?
     * @return bool
     */
    public function isRunning()
    {
        return $this->status == static::RUNNING;
    }

    /**
     * reset status
     * @return $this
     */
    public function reset()
    {
        $this->status = '';
        $this->results = null;
        return $this;
    }

    /**
     * add a driver to backup drivers
     * @param $driverName
     */
    public function addToBackupDrivers($driverName)
    {
        if ($driverName instanceof Driver) {
            $driverName = $driverName->name;
        }
        if (!in_array($driverName, $this->backupDrivers)) {
            array_push($this->backupDrivers, $driverName);
        }
    }

    /**
     * remove character driver from backup drivers
     * @param $driverName
     */
    public function removeFromBackupDrivers($driverName)
    {
        if ($driverName instanceof Driver) {
            $driverName = $driverName->name;
        }
        if (in_array($driverName, $this->backupDrivers)) {
            $key = array_search($driverName, $this->backupDrivers);
            unset($this->backupDrivers[$key]);
            $this->backupDrivers = array_values($this->backupDrivers);
        }
    }

    /**
     * set data
     * @param $data
     *
     * @return $this
     */
    public function data($data)
    {
        $this->data = $data;
        return $this;
    }

    /**
     * set hook handler
     * @param      $hookName
     * @param null $handler
     *
     * @throws \Exception
     */
    public function hook($hookName, $handler = null)
    {
        if ($handler && is_callable($handler) && is_string($hookName)) {
            if (in_array($hookName, self::$hooks)) {
                $this->handlers[$hookName] = $handler;
            } else {
                throw new \Exception("Do not support hook [$hookName]");
            }
        } elseif (is_array($hookName)) {
            foreach ($hookName as $k => $h) {
                $this->hook($k, $h);
            }
        }
    }

    /**
     * call hook handler
     * @param $hookName
     * @param $data
     *
     * @return mixed|null
     */
    private function callHookHandler($hookName, $data = null)
    {
        if (array_key_exists($hookName, $this->handlers)) {
            $handler = $this->handlers[$hookName];
            $result = call_user_func_array($handler, [$this, $data]);
            if ($result === null) {
                return true;
            }
            return $result;
        }
        return true;
    }

    /**
     * properties overload
     * @param $name
     *
     * @return null
     */
    public function __get($name)
    {
        if (isset($this->$name)) {
            return $this->$name;
        }
        if (array_key_exists($name, $this->drivers)) {
            return $this->drivers[$name];
        }
        return null;
    }

    /**
     * method overload
     * @param $name
     * @param $args
     *
     * @throws \Exception
     */
    public function __call($name, $args)
    {
        if (in_array($name, self::$hooks)) {
            if (isset($args[0]) && is_callable($args[0])) {
                $this->hook($name, $args[0]);
            } else {
                throw new \Exception("Please give method [$name()] a callable argument");
            }
        } else {
            throw new \Exception("Not find method [$name]");
        }
    }
}