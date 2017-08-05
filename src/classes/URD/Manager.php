<? namespace URD;

require_once __DIR__ . '/../API/VK.php';
require_once __DIR__ . '/../API/VKAsync.php';
require_once __DIR__ . '/../API/VKException.php';

require_once __DIR__ . '/../Jobber.php';
require_once __DIR__ . '/../Profiler.php';
require_once __DIR__ . '/../Utils.php';

require_once __DIR__ . '/Program.php';

/**
 * Class Manager - класс для управления работой программы URD (абстракция над URD).
 *
 * @package URD
 */
final class Manager extends \Jobber {
    /**
     * Логин пользователя ВКонтакте, от имени которого будет производиться выполнение запросов к API.
     */
    const VK_LOGIN = '+79199015140';

    /**
     * Пароль пользователя ВКонтакте, от имени которого будет производиться выполнение запросов к API.
     */
    const VK_PASSWORD = '53DumdSeV6RLVv';

    /**
     * Идентификатор приложения ВКонтакте, через которое будут осуществляться запросы к API.
     * На данный момент используется APP_ID и APP_SECRET приложения Windows Phone (т. к. для него доступна прямая авторизация).
     */
    const APP_ID = 3697615;

    /**
     * Secret приложения ВКонтакте, через которое будут осуществляться запросы к API.
     */
    const APP_SECRET = 'AlVXZFMUqyrnABp8ncuU';

    /**
     * Ссылка на объект, предоставляющий функционал для работы с VK API.
     *
     * @type \VK
     */
    private $vk;

    /**
     * Ассоциативный массив с параметрами программы.
     *
     * @type [string => mixed]
     */
    private $program_params;

    /**
     * Ссылка на объект, предоставляющий функционал для работы с запросами (очередь выполнения и кэширование).
     *
     * @type \QueryManager
     */
    private $qm;

    /**
     * Конструктор.
     *
     * @param   [string => mixed]   $program_params Ассоциативный массив с параметрами программы.
     * @param   string              $access_token   Токен для доступа к VK API (если не передаётся, используется прямая авторизация).
     */
    public function __construct($program_params, $access_token = null)
    {
        parent::__construct();
        $this->program_params = $program_params;
        $this->vk = new \VK(self::APP_ID, self::APP_SECRET);
        $this->qm = new \QueryManager();
        if ($access_token) {
            $this->vk->setAccessToken($access_token);
        } else {
            $this->vkAuth();
            $this->vk->setAccessTokenErrorInterceptor(array($this, 'accessTokenErrorIntercept'));
        }
    }

    /**
     * Перехватчик ошибок авторизации при выполнении запросов к API.
     * Перехватчик инициирует повторную авторизацию.
     *
     * @return bool Флаг, указывающий на необходимость повторить запрос к API,
     *              на котором возникла ошибка неалидного access token.
     */
    public function accessTokenErrorIntercept()
    {
        $this->vkAuth(true);
        return true;
    }

    /**
     * Принудительная авторизация приложения ВКонтакте.
     *
     * @return string   Новый access token.
     */
    private function vkAuthForce() {
        $access_token_info = $this->vk->getAccessTokenByCredentials(self::VK_LOGIN, self::VK_PASSWORD);
        $access_token = $access_token_info['access_token'];
        $this->memcacheD->set('vk_access_token', $access_token);
        return $access_token;
    }

    /**
     * Авторизация приложения ВКонтакте для выполнения запросов к API.
     * Используется прямая авторизация (с указанием логина и пароля) с помощью специального пользователя.
     *
     * @param   boolean $force  Флаг, указывающий на необходимость принудительной авторизации
     *                          (без использования закэшированного access token).
     */
    private function vkAuth($force = false)
    {
        $access_token = $force ?
            $this->vkAuthForce() :
            $this->memcacheD->get('vk_access_token') ?? $this->vkAuthForce();
        $this->vk->setAccessToken($access_token);
    }

    /**
     * Вызов VK API.
     *
     * @param boolean   $is_async           Флаг, указывающий на асинхронность вызова.
     * @param array     $api_args           Аргументы VK API (метод + параметры).
     * @param array     $async_api_params   Дополнительные параметры для асинхронного вызова VK API.
     *
     * @return mixed|\VKAsync               Либо результат выполнения запроса (синхронное выполнение),
     *                                      либо воркер (асинхронное выполнение), либо закэшированный результат.
     */
    public function vkApiCall($is_async, $api_args, $async_api_params = null)
    {
        $cache_result = $this->qm->getResultFromCache($api_args);
        if ($cache_result) {
            if ($is_async) {
                $async_api_params['result_array'][] = $cache_result;
                $cache_result = null;
            }
            return $cache_result;
        }
        $this->qm->wait();
        return $is_async ?
            $this->vkApiCallAsync($api_args, $async_api_params) :
            $this->vkApiCallSync($api_args);
    }

    /**
     * Синхронный вызов VK API.
     *
     * @param array $api_args   Аргументы VK API (метод + параметры).
     *
     * @return mixed
     */
    private function vkApiCallSync($api_args)
    {
        $vk_api = array($this->vk, 'api');
        $vk_api_method = $api_args[0];

        $profiler_data = \Profiler::run(function() use($vk_api, $api_args) {
            return call_user_func_array($vk_api, $api_args);
        });
        $result = $profiler_data['result'];
        $metrics = $profiler_data['metrics'];
        \Profiler::write($this->job_id, $vk_api_method, $metrics, $this->memcacheD);

        \VK\VKException::checkResult($result);

        $response = $result['response'];
        $this->qm->cacheResult($response, $api_args);

        return $response;
    }

    /**
     * Асинхронный вызов VK API.
     *
     * @param array $api_args   Аргументы VK API (метод + параметры).
     * @param array $params     Дополнительные параметры, необходимые для асинхронного вызова VK API.
     *
     * @return \VKAsync         Воркер, осуществляющий асинхронное выполнение запроса.
     */
    private function vkApiCallAsync($api_args, $params)
    {
        return new \VKAsync($this->vk, $this->job_id, $api_args, $params['result_array'], $params['linked_data'] ?? null);
    }

    /**
     * Запуск программы.
     * Помимо запуска здесь создаём Job'у, делаем замеры времени работы и записываем результаты в in-memory базу.
     */
    public function runProgram()
    {
        /*
         * Создаём Job'у для построения цепочек.
         * В этот момент происходит отвязывание скрипта от стандартного потока.
         * В поток возвращается идентификатор Job'ы.
         */
        $this->createJob();

        // Записываем 'начальный' промежуточный результат.
        $this->recordIntermediateResult($this->job_id, 0, array());

        $start_time = time();
        $urd = new Program(
            $this->program_params['user_source'],
            $this->program_params['user_target'],
            array($this, 'vkApiCall'),
            array(
                'mode' => $this->program_params['mode']
            )
        );
        $urd->run();
        $end_time = time() - $start_time;

        // Записываем окончательный результат.
        $this->recordResult($this->job_id, $urd->getChains(), $end_time);
    }
}