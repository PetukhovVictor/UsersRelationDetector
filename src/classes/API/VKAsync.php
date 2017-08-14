<?

require_once __DIR__ . '/../API/VKException.php';

require_once __DIR__ . '/../QueryManager.php';

require_once __DIR__ . '/../Profiler.php';

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
     * Идентиикатор задания, в рамках которого выполняется запрос.
     *
     * @type int
     */
    private $job_id;

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
     * Ссылка на объект, предоставляющий функционал для профилирования запросов.
     *
     * @type \Profiler
     */
    private $profiler;

    /**
     * VKAsync constructor. Во время инициализации также происходит запуск потока (выхов метода start).
     *
     * @param $vk           VK          Инстанс объекта VK API.
     * @param $job_id       int         Идентиикатор задания, в рамках которого выполняется запрос.
     * @param $api_args     array       Аргументы для выполнения запроса к VK API: название метода и параметры.
     * @param $shared_array Threaded    Объект, представляющий из себя разделяемую память,
     *                                  в который будет записан результат выполнения запроса.
     * @param $linked_data  array       Привязанные к запросу дополнительные данные.
     */
    public function __construct(&$vk, $job_id, $api_args, $shared_array, $linked_data = null) {
        $this->vk = $vk;
        $this->job_id = $job_id;
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
        $profiler = new Profiler();
        $query_manager = new QueryManager();

        $api_args = $this->api_args;
        $vk_api = array($this->vk, 'api');
        $vk_api_method = $api_args[0];

        $profiler_data = $profiler->run(function () use($vk_api, $api_args) {
            return call_user_func_array($vk_api, $api_args);
        });
        $result = $profiler_data['result'];
        $metrics = $profiler_data['metrics'];
        $profiler->write($this->job_id, $vk_api_method, $metrics);

        \VK\VKException::checkResult($result);

        $result = array('data' => $result['response']);

        $query_manager->cacheResult((object)$result, $api_args);

        if ($this->linked_data !== null) {
            $result['linked_data'] = (array)$this->linked_data;
        }
        $this->shared_array[] = (object)$result;
    }
}