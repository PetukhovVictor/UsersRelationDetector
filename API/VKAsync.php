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
     * VKAsync constructor. Во время инициализации также происходит запуск потока (выхов метода start).
     *
     * @param $vk VK Инстанс объекта VK API.
     * @param $api_args array Аргументы для выполнения запроса к VK API: название метода и параметры.
     * @param $shared_array Threaded Объект, представляющий из себя разделяемую память, в который будет записан результат выполнения запроса.
     */
    public function __construct($vk, $api_args, $shared_array) {
        $this->vk = $vk;
        $this->shared_array = $shared_array;
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
        $result = call_user_func_array(array($vk, 'api'), $this->api_args);

        if (!isset($result['response']) && isset($result['error'])) {
            throw new Exception("Error code {$result['error']['error_code']}: {$result['error']['error_msg']}");
        } elseif (!isset($result['response'])) {
            throw new Exception("Unknown error.");
        }

        $result = array('data' => $result['response']);
        $this->shared_array[] = (object)$result;
    }
}