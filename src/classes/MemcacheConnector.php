<?

/**
 * Class MemcacheConnector - коннектор к memcache-серверу.
 */
class MemcacheConnector {
    /**
     * Хост memcache-сервера.
     */
    const MEMCACHED_HOST = 'localhost';

    /**
     * Порт memcache-сервера.
     */
    const MEMCACHED_PORT = 11211;

    /**
     * Ссылка на объект, предоставляющий функционал для работы с memcached.
     */
    protected $memcacheD;

    /**
     * Конструктор.
     */
    public function __construct()
    {
        $this->memcacheD = new Memcached();
        $this->memcacheD->addServer(self::MEMCACHED_HOST, self::MEMCACHED_PORT);
    }

    public function getInstance() {
        return $this->memcacheD;
    }

    /**
     * Закрытие соединения с memcached-сервером.
     */
    protected function close()
    {
        $this->memcacheD->quit();
    }
}