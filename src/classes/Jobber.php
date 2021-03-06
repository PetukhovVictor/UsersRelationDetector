<?

require_once __DIR__ . '/MemcacheConnector.php';

/**
 * Class Jobber - класс, реализующий схему "заданий".
 *
 * Создаётся задание с присвоенным идентификатором,
 * скрипт отвязывается от стандартного потока, в который возвращается идентификатор Job'ы.
 * По мере выполнения задания происходит запись промежуточного или конечного результата в in-memory базу.
 */
abstract class Jobber extends MemcacheConnector {
    /**
     * Максимальный идентификатор Job'ы.
     * При достижении данного числа, идентификатор сбрасывается.
     */
    const JOB_MAX_NUMBER = 1000000;

    /**
     * Первый (по умолчанию) идентификатор Job'ы.
     * При достижении максимального идентификатора, сброс происходит на данный идентификатор.
     */
    const JOB_DEFAULT_NUMBER = 1;

    /**
     * Ключ в memcache-хранилище для счетчик заданий.
     */
    const JOB_COUNTER_MEMCACHE_KEY = 'job_counter';

    /**
     * Время жизни конечного результата в in-memory базе (в секундах).
     */
    const RESULT_TTL = 600;

    /**
     * Время жизни промежуточного результата в in-memory базе (в секундах).
     */
    const INTERMEDIATE_RESULT_TTL = 1800;

    /**
     * Идентификатор созданной Job'ы.
     *
     * @type int
     */
    protected $job_id;

    /**
     * Конструктор.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Создание Job'ы.
     * Атамарно инкрементируем счетчик Job'ов в in-memory базе с проверкой на достижение максимального идентификатора
     * и демонизируем скрипт (отвязываем от стандартного потока).
     */
    protected function createJob()
    {
        do {
            $job_item = $this->memcacheD->get(self::JOB_COUNTER_MEMCACHE_KEY, null, Memcached::GET_EXTENDED);
            if (!$job_item) {
                $job_id = self::JOB_DEFAULT_NUMBER;
                $this->memcacheD->add(self::JOB_COUNTER_MEMCACHE_KEY, $job_id);
            } else {
                $job_id = $job_item['value'] > self::JOB_MAX_NUMBER ? self::JOB_DEFAULT_NUMBER : $job_item['value'] + 1;
                $this->memcacheD->cas($job_item['cas'], self::JOB_COUNTER_MEMCACHE_KEY, $job_id);
            }
        } while ($this->memcacheD->getResultCode() != \Memcached::RES_SUCCESS);

        $this->job_id = $job_id;

        \Utils::createDaemon('Job number: ' . $this->job_id . PHP_EOL);
    }

    /**
     * Запись конечного результата в in-memory базу.
     *
     * @param int   $job_id     Идентификатор Job'ы, к которой нужно привязать результат.
     * @param mixed $data       Данные (результат выполнения Job'ы).
     * @param int   $time       Время выполнения.
     */
    protected function recordResult($job_id, $data, $time = null) {
        do {
            $job_item_info = $this->memcacheD->get("job_{$job_id}_result", null, Memcached::GET_EXTENDED);
            if (!$job_item_info) {
                $this->memcacheD->add("job_{$job_id}_result", array(
                    'progress'  => 100,
                    'data'      => $data,
                    'time'      => $time
                ));
            } else {
                $job_data = $job_item_info['value'];
                $job_data['progress'] = 100;
                $job_data['data'] = $data;
                $job_data['time'] = $time;
                $this->memcacheD->cas($job_item_info['cas'], "job_{$job_id}_result", $job_data, self::RESULT_TTL);
            }
        } while ($this->memcacheD->getResultCode() != \Memcached::RES_SUCCESS);
    }

    /**
     * Запись промежуточного результата в in-memory базу.
     *
     * @param int       $job_id     Идентификатор Job'ы, к которой нужно привязать результат.
     * @param double    $progress   Прогресс выполнения (число от 0 до 100).
     * @param mixed     $data       Данные (промежуточный результат выполнения Job'ы).
     */
    protected function recordIntermediateResult($job_id, $progress, $data) {
        do {
            $job_item_info = $this->memcacheD->get("job_{$job_id}_result", null, Memcached::GET_EXTENDED);
            if (!$job_item_info) {
                $this->memcacheD->add("job_{$job_id}_result", array(
                    'progress'  => $progress,
                    'data'      => $data
                ));
            } else {
                $job_data = $job_item_info['value'];
                $job_data['progress'] = $progress;
                $job_data['data'] = $data;
                $this->memcacheD->cas($job_item_info['cas'], "job_{$job_id}_result", $job_data, self::INTERMEDIATE_RESULT_TTL);
            }
        } while ($this->memcacheD->getResultCode() != \Memcached::RES_SUCCESS);
    }

    /**
     * Получение идентификатора Job'ы.
     *
     * @return int Идентификатор Job'ы.
     */
    public function getJobId() {
        return $this->job_id;
    }
}