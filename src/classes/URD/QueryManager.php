<? namespace URD;

require_once __DIR__ . '/../MemcacheConnector.php';

class QueryManager extends \MemcacheConnector {
    /**
     * Минимальный интервал между вызовами методов VK API (поставлено исходя из ограничения: макс. 3 в секунду).
     *
     * @type int
     */
    const DELAY = 0.4;

    const WAITING_PARAMS_MEMCACHE_KEY = 'vk_queries_waiting_params';

    public function __construct()
    {
        parent::__construct();
    }

    private function setWaitingParamsWithCurrent()
    {
        $this->memcacheD->add(self::WAITING_PARAMS_MEMCACHE_KEY, array(
            'ts'    => microtime(true),
            'delay' => self::DELAY
        ));
    }

    private function setWaitingParams($ts, $delay, &$cas)
    {
        $this->memcacheD->cas($cas, self::WAITING_PARAMS_MEMCACHE_KEY, array(
            'ts'    => $ts,
            'delay' => $delay
        ));
    }

    public function wait()
    {
        $sleep_time = 0;
        do {
            $waiting_params = $this->memcacheD->get(self::WAITING_PARAMS_MEMCACHE_KEY, null, \Memcached::GET_EXTENDED);
            if (!$waiting_params) {
                $this->setWaitingParamsWithCurrent();
            } else {
                $value = $waiting_params['value'];
                $current_time = microtime(true);
                $next_query_time = $value['ts'] + $value['delay'];
                if ($next_query_time <= $current_time) {
                    $this->setWaitingParams($current_time, self::DELAY, $waiting_params['cas']);
                } else {
                    $this->setWaitingParams($value['ts'], $value['delay'] + self::DELAY, $waiting_params['cas']);
                    $sleep_time = $next_query_time - $current_time;
                }
            }
        } while ($this->memcacheD->getResultCode() != \Memcached::RES_SUCCESS);
        if ($sleep_time != 0) {
            usleep($sleep_time);
        }
    }
}