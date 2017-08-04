<? namespace URD;

require_once __DIR__ . '/../MemcacheConnector.php';

class QueryManager extends \MemcacheConnector {
    /**
     * Интервал между запросами в секундах (установлено исходя из ограничения VK API: макс. 3 в секунду).
     */
    const DELAY = 0.4;

    /**
     * Ключ в memcache-хранилище для параметров очреди запросов.
     */
    const QUERIES_QUEUE_PARAMS_MEMCACHE_KEY = 'vk_queries_queue_params';

    /**
     * Префикс ключа в memcache-хранилище для результатов запросов.
     */
    const QUERIES_RESULTS_MEMCACHE_KEY = 'vk_queries_results';

    /**
     * Время жизни результатов запросов в in-memory базе (в секундах).
     */
    const QUERIES_RESULTS_TTL = 300;

    /**
     * Конструктор.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Получение текущей временной метки с точностью до микросекунд.
     *
     * @return float
     */
    private function getTime() {
        return microtime(true);
    }

    public function getResultFromCache($args)
    {
        $hash_query = md5(print_r($args, 1));
        $mc_key = self::QUERIES_RESULTS_MEMCACHE_KEY . '_' . $hash_query;

        return $this->memcacheD->get($mc_key);
    }

    public function cacheResult($result, $args)
    {
        $hash_query = md5(print_r($args, 1));
        $mc_key = self::QUERIES_RESULTS_MEMCACHE_KEY . '_' . $hash_query;
        $this->memcacheD->set($mc_key, $result, self::QUERIES_RESULTS_TTL);
    }

    /**
     * Установка параметров очреди запросов по умолчанию.
     */
    private function setDefaultQueriesQueueParams()
    {
        $this->memcacheD->add(self::QUERIES_QUEUE_PARAMS_MEMCACHE_KEY, array(
            'ts'    => $this->getTime(),
            'delay' => self::DELAY
        ));
    }

    /**
     * Установка заданных параметров очреди запросов.
     *
     * @param float $ts     Временная метка с точностью до микросекунд - время запуска очередного запроса.
     * @param float $delay  Задержка для запуска следующего по очереди запроса.
     * @param int   $cas    Внутренний идентификатор для осуществления операции compare and swap.
     */
    private function setQueriesQueueParams($ts, $delay, &$cas)
    {
        $this->memcacheD->cas($cas, self::QUERIES_QUEUE_PARAMS_MEMCACHE_KEY, array(
            'ts'    => $ts,
            'delay' => $delay
        ));
    }

    /**
     * Ожидание запросом своей очереди на выполнение.
     */
    public function wait()
    {
        $sleep_time = 0;
        /*
         * Атомарно вычисляем время ожидания текущего запроса
         * и устанавливаем время ожидания (временную метку + задержку) для следующего запроса.
         */
        do {
            $waiting_params = $this->memcacheD->get(self::QUERIES_QUEUE_PARAMS_MEMCACHE_KEY, null, \Memcached::GET_EXTENDED);
            if (!$waiting_params) {
                $this->setDefaultQueriesQueueParams();
            } else {
                $value = $waiting_params['value'];
                $current_time = $this->getTime();
                $next_query_time = $value['ts'] + $value['delay'];
                if ($next_query_time <= $current_time) {
                    $this->setQueriesQueueParams($current_time, self::DELAY, $waiting_params['cas']);
                } else {
                    $this->setQueriesQueueParams($value['ts'], $value['delay'] + self::DELAY, $waiting_params['cas']);
                    $sleep_time = $next_query_time - $current_time;
                }
            }
        } while ($this->memcacheD->getResultCode() != \Memcached::RES_SUCCESS);
        if ($sleep_time != 0) {
            usleep($sleep_time * 1000000);
        }
    }
}