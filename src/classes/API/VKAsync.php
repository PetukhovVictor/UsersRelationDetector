<?

/**
 * Class VKAsync - реализует асинхронное выполнение запросов к VK API и запись результата в разделяемую память (Threaded).
 */
class VKAsync extends Thread {
    /**
     * @var VK Инстанс объекта VK API.
     */
    private $vk;

    /**
     * @var array Аргументы для выполнения запроса к VK API: название метода и параметры.
     */
    private $api_args;

    /**
     * @var Threaded Объект, представляющий из себя разделяемую память, в который будет записан результат выполнения запроса.
     * Запись происходит аналогично записи в массив (array_push).
     */
    private $shared_array;

    /**
     * @var Threaded Объект, представляющий из себя разделяемую память, в который будет записан результат выполнения запроса.
     * Запись происходит аналогично записи в массив (array_push).
     */
    private $linked_data;

    /**
     * VKAsync constructor. Во время инициализации также происходит запуск потока (выхов метода start).
     *
     * @param $vk VK Инстанс объекта VK API.
     * @param $api_args array Аргументы для выполнения запроса к VK API: название метода и параметры.
     * @param $shared_array Threaded Объект, представляющий из себя разделяемую память, в который будет записан результат выполнения запроса.
     */
    public function __construct($vk, $api_args, $shared_array, $linked_data = null) {
        $this->vk = $vk;
        $this->shared_array = $shared_array;
        $this->linked_data = $linked_data;
        $this->api_args = (array)$api_args;
        $this->start();
    }

    /**
     * Выполнение запроса к VK API и запись результата в разделяемую память.
     *
     * @throws Exception
     */
    public function run() {
        $vk = $this->vk;

        $start_time = time();
        $result = call_user_func_array(array($vk, 'api'), $this->api_args);
        $time = time() - $start_time;

        $log_args = array();
        foreach ($this->api_args[1] as $param => $value) {
            $param_info = array($param, strlen($value) > 10 ? substr($value, 0, 10) . '...' : $value);
            array_push($log_args, implode(' = ', $param_info));
        }
        $log_args = implode(', ', $log_args);

        echo "{$this->api_args[0]}: $log_args" . PHP_EOL;
        echo "Time: " . round($time % 60) . " s." . PHP_EOL . PHP_EOL;

        if (!isset($result['response']) && isset($result['error'])) {
            throw new Exception("Error code {$result['error']['error_code']}: {$result['error']['error_msg']}");
        } elseif (!isset($result['response'])) {
            throw new Exception("Unknown error.");
        }

        $result = array(
            'data' => $result['response']
        );
        if ($this->linked_data !== null) {
            $result['linked_data'] = (array)$this->linked_data;
        }
        $this->shared_array[] = (object)$result;
    }
}