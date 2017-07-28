<?

require_once __DIR__ . '/../API/VKException.php';

require_once __DIR__ . '/../Loger.php';

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
     * @param $vk           VK          Инстанс объекта VK API.
     * @param $api_args     array       Аргументы для выполнения запроса к VK API: название метода и параметры.
     * @param $shared_array Threaded    Объект, представляющий из себя разделяемую память,
     *                                  в который будет записан результат выполнения запроса.
     * @param $linked_data  array       Привязанные к запросу дополнительные данные.
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

        $loger = new Loger();
        $loger->start();

        $result = call_user_func_array(array($vk, 'api'), $this->api_args);
        $loger->end()->print($this->api_args);

        \VK\VKException::checkResult($result);

        $result = array(
            'data' => $result['response']
        );
        if ($this->linked_data !== null) {
            $result['linked_data'] = (array)$this->linked_data;
        }
        $this->shared_array[] = (object)$result;
    }
}