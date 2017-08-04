<?

/**
 * Class MemcacheConnector - коннектор к memcache-серверу.
 */
abstract class MemcacheConnector {
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
    protected function __construct()
    {
        $this->memcacheD = new Memcached();
        $this->memcacheD->addServer(self::MEMCACHED_HOST, self::MEMCACHED_PORT);
    }

    /**
     * Закрытие соединения с memcached-сервером.
     */
    protected function close()
    {
        $this->memcacheD->quit();
    }
}