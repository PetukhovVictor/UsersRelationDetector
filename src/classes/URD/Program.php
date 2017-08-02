<? namespace URD;

require_once __DIR__ . '/../API/VK.php';
require_once __DIR__ . '/../API/VKAsync.php';
require_once __DIR__ . '/../API/VKException.php';

require_once __DIR__ . '/../Utils.php';
require_once __DIR__ . '/../Loger.php';

require_once __DIR__ . '/Helpers.php';

/**
 * Class Program - построение цепочек друзей между двумя пользователями.
 *
 * @package URD
 */
class Program {
    /**
     * Минимальный интервал между вызовами методов VK API (поставлено исходя из ограничения: макс. 3 в секунду).
     *
     * @type int
     */
    const API_CALL_INTERVAL = 400000;

    /**
     * Максимальный размер 'порции' пользователей, информацию о которых можно получить через VK API.
     *
     * @type int
     */
    const USERS_CHUNK_SIZE = 300;

    /**
     * Максимальный размер 'порции' пользователей, общих друзей которых можно запросить через VK API.
     *
     * @type int
     */
    const FRIENDS_CHUNK_SIZE = 100;

    /**
     * Максимальное количество запросов к VK API, выполняемых в коде VKScript.
     *
     * @type int
     */
    const MAX_QUERIES_NUMBER = 25;

    /**
     * Путь к скрипту (VKScript), получающему списки общих друзей для заданого набора пользователей.
     *
     * @type string
     */
    const COMMON_FRIENDS_CHUNK_SCRIPT_PATH = __DIR__ . '/../../scripts/commonFriends.vks';

    /**
     * Мапа порядков цепочек и методов для построения цепочек этих порядков.
     *
     * @type [int => string]
     */
    static private $chain_orders = array(
        2 => 'buildSecondOrderChains',
        3 => 'buildThirdOrderChains',
        4 => 'buildFourthOrderChains',
        5 => 'buildFifthOrderChains',
        6 => 'buildSixthOrderChains'
    );

    /**
     * Ссылка на объект, предоставляющий функционал для работы с VK API.
     *
     * @type \VK
     */
    private $vk;

    /**
     * ID пользователя-источника (от которого нужно строить цепочку рукопожатий).
     *
     * @type int
     */
    private $user_source;

    /**
     * ID целевого пользователя (к которому нужно строить цепочку рукопожатий).
     *
     * @type int
     */
    private $user_target;

    /**
     * Проложенные от одного пользователя к другому цепочки из друзей (массив массивов ID пользователей).
     *
     * @type int[][]
     */
    private $chains;

    /**
     * Режим вывода цепочки (random_chain - вывести одну случайную цепочку, all_chains - вывести всевозможные цепочки).
     *
     * @type string
     */
    private $mode;

    /**
     * Длина цепочки из друзей, проложенной от одного пользователя к другому (количество рукопожатий).
     *
     * @type int
     */
    private $chain_length;

    /**
     * Конструктор.
     *
     * @param   \VK $vk_api_instance    Ссылка на объект, предоставляющий функционал для работы с VK API.
     * @param   int $user_source        ID пользователя-источника (от которого нужно строить цепочку рукопожатий).
     * @param   int $user_target        ID целевого пользователя (к которому нужно строить цепочку рукопожатий).
     * @param   array $option           Дополнительные опции.
     */
    public function __construct($user_source, $user_target, $vk_api_instance, $option = null)
    {
        $this->vk = $vk_api_instance;
        $this->user_source = $user_source;
        $this->user_target = $user_target;
        $this->mode = $option['mode'] ?? 'random_chain';
        $this->mutual_friends_vk_script = file_get_contents(self::COMMON_FRIENDS_CHUNK_SCRIPT_PATH);
    }

    /**
     * Метод-обертка для выполнения запросов к VK API.
     *
     * Число аргументов - произвольное, в соответствии с заданным методом VK API (аргументы транслируются в него).
     *
     * @throws \Exception
     *
     * @return mixed Результат выполнения заданного запроса к VK API.
     */
    private function API()
    {
        usleep(self::API_CALL_INTERVAL);
        $vk = $this->vk;
        $loger = new \Loger();
        $loger->start();
        $result = call_user_func_array(array($vk, 'api'), func_get_args());
        $loger->end()->print(func_get_args());

        \VK\VKException::checkResult($result);

        return $result['response'];
    }

    /**
     * Метод-обертка для асинхронного выполнения запросов к VK API.
     *
     * @param   array       $api_args     Транслируемые в метод VK API аргументы.
     * @param   \Threaded   $result_array Объект разделяемой памяти, в который будет производиться запись результата запроса.
     * @param   array       $linked_data  Привязанные к запросу дополнительные данные.
     *
     * @return \VKAsync     Worker, представляющий из себя отдельный поток выполнения.
     */
    private function APIAsync($api_args, $result_array, $linked_data = null)
    {
        usleep(self::API_CALL_INTERVAL);
        return new \VKAsync($this->vk, $api_args, $result_array, $linked_data);
    }

    /**
     * Отсеивание удаленных и заблокированных пользователей.
     *
     * @param   array[int] $users   Массив с ID пользователей, который требуется отфильтровать.
     *
     * @return  array[int]          Отфильтрованный массив пользователей.
     */
    private function filteringDeletedUser($users)
    {
        $users_chunked = array_chunk($users, self::USERS_CHUNK_SIZE);

        $workers = array();
        $result_shared_object = new \Threaded();

        $users_filtered = array();
        foreach($users_chunked as $users_chunk) {
            $worker = $this->APIAsync(
                array('users.get', array('user_ids' => implode(',', $users_chunk))),
                $result_shared_object
            );
            array_push($workers, $worker);
        }

        \Utils::workersSync($workers);

        foreach ($result_shared_object as $result_chunk) {
            $users_filtered_full = array_filter($result_chunk->data, function($user) {
                return empty($user['deactivated']);
            });
            foreach ($users_filtered_full as &$user) {
                $user = $user['uid'];
            }
            $users_filtered = array_merge($users_filtered, $users_filtered_full);
        }

        return $users_filtered;
    }

    /**
     * Построение цепочек пользователей заданного порядка.
     *
     * @param int $user_source  ID пользователя-источника (от которого нужно строить цепочку рукопожатий).
     * @param int $user_target  ID целевого пользователя (к которому нужно строить цепочку рукопожатий).
     * @param int $order        Порядок (длина) цепочки.
     *
     * @return array            Массив построенных цепочек.
     */
    private function buildChains($user_source, $user_target, $order)
    {

        file_put_contents("log.txt", "------------ $order порядок ------------" . PHP_EOL . PHP_EOL, FILE_APPEND);

        /*
         * Цепочки второго порядка строятся синхронными запросами (это просто проверка общих друзей) -
         * поэтому запускаем метод и сразу же возвращаем результат.
         */
        if ($order == 2) {
            return $this->{self::$chain_orders[$order]}($user_source, $user_target);
        }

        $user_source_friends = $this->getFriends($user_source);
        $user_source_friends = $this->filteringDeletedUser($user_source_friends);
        $user_source_friends = array_chunk($user_source_friends, self::FRIENDS_CHUNK_SIZE);

        /*
         * Запросы для цепочек 3+ порядка выполняются асинхронно и параллельно -
         * поэтому подгатавливаем инфраструктуру для параллельного выполнения:
         * список воркеров и объект в разделяемой памяти.
         */
        $workers = array();
        $friends_shared_object = new \Threaded();

        $this->{self::$chain_orders[$order]}(
            $user_source_friends,
            $user_target,
            array('workers' => $workers, 'result_shared_object' => $friends_shared_object)
        );

        // Точка принятия консенсуса: синхронизируем воркеры.
        \Utils::workersSync($workers);

        // Осуществляем сбор результатов и приводим их к нужному виду.
        $friends_map = array();
        foreach ($friends_shared_object as $friends) {
            /*
             * Если результат (список друзей) должен был быть связан с другими пользователями
             * (то есть результат является только частью иерархии общих друзей - его необходимо соеденить с другой частью),
             * дописываем их в иерархию общих друзей.
             */
            $friends_hierarchy_chunk = !isset($friends->linked_data) ?
                $friends->data :
                Helpers::appendToCommonFriendsHierarchy($friends->data, $friends->linked_data);

            // Индексируем иерархию общих друзей.
            $friends_map_chunk = Helpers::indexingCommonFriends($friends_hierarchy_chunk, array());

            /*
             * Меняем местами последний уровень иерархичной мапы друзей и препоследний
             * (в виду специфики структуры объекта, отадаваемого VK API).
             */
            $friends_map_chunk = \Utils::swapLastDepthsMultidimensionalArray($friends_map_chunk, array());
            $friends_map = array_merge_recursive($friends_map, $friends_map_chunk);
        }

        // Линеаризуем иерархическую мапу друзей (преобразуем в массив цепочек).
        $chains = Helpers::linearizeCommonFriendsMap($friends_map)['chains'];

        // Добавляем в каждую цепочку пользователя-источника и целевого пользователя.
        $chains = Helpers::addEndpointUsers($chains, array($user_source), array($user_target));

        return $chains;
    }

    /**
     * Построение цепочки второго порядка.
     *
     * @param array[int]    $user_source    ID пользователя-источника (от которого нужно строить цепочки рукопожатий).
     * @param int           $user_target    ID целевого пользователя (к которому нужно строить цепочку рукопожатий).
     *
     * @return array                        Массив построенных цепочек второго порядка.
     */
    private function buildSecondOrderChains($user_source, $user_target)
    {
        $common_friends = $this->getCommonFriends($user_source, $user_target);
        $chains = array();

        foreach ($common_friends as $mutual_friend) {
            $chain = Helpers::addEndpointUsers(array($mutual_friend), array($user_source), array($user_target));
            array_push($chains, $chain);
        }

        return $chains;
    }

    /**
     * Построение цепочки третьего порядка.
     *
     * @param array[int]    $user_source_friends    ID пользователей-источников (от которых нужно строить цепочки рукопожатий).
     * @param int           $user_target            ID целевого пользователя (к которому нужно строить цепочку рукопожатий).
     * @param array         $params                 Дополнительные параметры:
     *                                                  - array[Worker] workers                 Список воркеров,
     *                                                  - array         result_shared_object    Shared object для результатов запросов.
     */
    private function buildThirdOrderChains($user_source_friends, $user_target, $params)
    {
        foreach ($user_source_friends as $user_source_friends_chunk) {
            array_push(
                $params['workers'],
                $this->getCommonFriends($user_target, $user_source_friends_chunk, $params['result_shared_object'])
            );
        }
    }

    /**
     * Построение цепочки четвертого порядка.
     *
     * @param array[int]    $user_source_friends    ID пользователей-источников (от которых нужно строить цепочки рукопожатий).
     * @param int           $user_target            ID целевого пользователя (к которому нужно строить цепочку рукопожатий).
     * @param array         $params                 Дополнительные параметры:
     *                                                  - array[Worker] workers                 Список воркеров,
     *                                                  - array         result_shared_object    Shared object для результатов запросов,
     *                                                  - array         linked_data             Привязанные к запросу дополнительные данные.
     */
    private function buildFourthOrderChains($user_source_friends, $user_target, $params)
    {
        $user_target_friends = $this->getFriends($user_target);
        $user_target_friends = $this->filteringDeletedUser($user_target_friends);
        $user_target_friends = array_chunk($user_target_friends, self::MAX_QUERIES_NUMBER);

        foreach ($user_source_friends as $source_friend) {
            foreach ($user_target_friends as $target_friend) {
                $vk_script_code = \Utils::template($this->mutual_friends_vk_script, array(
                    'source_friends' => implode(',', $target_friend),
                    'target_friends' => implode(',', $source_friend)
                ));
                $linked_data = $params['linked_data'] ?? null;
                $worker = $this->APIAsync(
                    array('execute', array('code' => $vk_script_code)),
                    $params['result_shared_object'],
                    $linked_data
                );
                array_push($params['workers'], $worker);
            }
        }
    }

    /**
     * Построение цепочки пятого порядка.
     *
     * @param array[int]    $user_source_friends    ID пользователей-источников (от которых нужно строить цепочки рукопожатий).
     * @param int           $user_target            ID целевого пользователя (к которому нужно строить цепочку рукопожатий).
     * @param array         $original_params        Дополнительные параметры:
     *                                                  - array[Worker] workers                 Список воркеров,
     *                                                  - array         result_shared_object    Shared object для результатов запросов,
     *                                                  - array         linked_data             Привязанные к запросу дополнительные данные.
     */
    private function buildFifthOrderChains($user_source_friends, $user_target, $original_params)
    {
        $user_target_friends = $this->getFriends($user_target);
        $user_target_friends = $this->filteringDeletedUser($user_target_friends);

        foreach ($user_target_friends as $target_friend) {
            $params = $original_params;
            $params['linked_data'] = Helpers::appendItemInLinkedData($params['linked_data'] ?? null, $target_friend);
            $this->buildFourthOrderChains($user_source_friends, $target_friend, $params);
        }
    }

    /**
     * Построение цепочки шестого порядка.
     *
     * @param array[int]    $user_source_friends    ID пользователей-источников (от которых нужно строить цепочки рукопожатий).
     * @param int           $user_target            ID целевого пользователя (к которому нужно строить цепочку рукопожатий).
     * @param array         $params                 Дополнительные параметры:
     *                                                  - array[Worker] workers                 Список воркеров,
     *                                                  - array         result_shared_object    Shared object для результатов запросов.
     */
    private function buildSixthOrderChains($user_source_friends, $user_target, $params)
    {
        $user_target_friends = $this->getFriends($user_target);
        $user_target_friends = $this->filteringDeletedUser($user_target_friends);

        foreach ($user_target_friends as $target_friend) {
            $params['linked_data'] = Helpers::appendItemInLinkedData(null, $target_friend);
            $this->buildFifthOrderChains($user_source_friends, $target_friend, $params);
        }
    }

    /**
     * Получение друзей заданного пользователя.
     *
     * @param   int $user_id    ID пользователя, друзей которого необходимо получить.
     *
     * @return  array[int]      Список ID пользователей-друзей указанного пользователя.
     */
    private function getFriends($user_id)
    {
        return $this->API('friends.get', array(
            'user_id' => $user_id
        ));
    }

    /**
     * Проверка наличия дружбы между двумя пользователями.
     *
     * @param   int $user_id1   ID 1-го пользователя.
     * @param   int $user_id2   ID 2-го пользователя.
     *
     * @return  boolean         Флаг, показывающий, являются ли пользователи друзьями.
     */
    private function isFriends($user_id1, $user_id2)
    {
        $friends = $this->getFriends($user_id1);
        return array_search($user_id2, $friends) !== false;
    }

    /**
     * Получение списка общих друзей между пользователем и списком других заданных пользователей.
     *
     * @param   int                 $user_source    ID пользователя-источника для получения списка общих друзей.
     * @param   int | array[int]    $users_target   ID пользователя или массив ID пользователей,
     *                                              общих друзей заданного пользователя с которым(и) нужно получить.
     * @param   \Threaded           $result_array   Объект разделяемой памяти для записи в него списка общих друзей.
     *                                              В случае, если $result_array не задан, запрос выполняется синхронно.
     *
     * @return  \VKAsync | mixed                    Worker, представляющий из себя отдельный поток выполнения,
     *                                              либо результат выполнения запроса (если запрос выполнялся синхронно).
     */
    private function getCommonFriends($user_source, $users_target, $result_array = null)
    {
        $is_multiple = is_array($users_target);
        $target_property_name = $is_multiple ? 'target_uids' : 'target_uid';
        if ($is_multiple) {
            $users_target = implode(',', $users_target);
        }

        if ($result_array === null) {
            return $this->API('friends.getMutual', array(
                'source_uid' => $user_source,
                $target_property_name => $users_target
            ));
        } else {
            return $this->APIAsync(array(
                'friends.getMutual',
                array(
                    'source_uid' => $user_source,
                    $target_property_name => $users_target
                )
            ), $result_array);
        }
    }

    /**
     * Установка цепочки.
     *
     * @param   array[array[string]] $users    Массив цепочек друзей.
     */
    private function setChains($users)
    {
        $this->chains = $users;
        $this->chain_length = count($users) - 1;
    }

    /**
     * Получение цепочки.
     *
     * @return array[array[string]]    Массив цепочек друзей.
     */
    public function getChains()
    {
        return $this->chains ?? array();
    }

    /**
     * Получение длины цепочки.
     *
     * @return int    Длина цепочки друзей.
     */
    public function getChainLength()
    {
        return $this->chain_length ?? 0;
    }

    /**
     * Построение цепочек друзей
     * (по нарастающей: построение цепочки более высокого порядка при ненахождении цепочек более низкого порядка).
     */
    public function run()
    {
        // Цепочка нулевого порядка: проверяем, совпадает ли пользователь-источник и целевой пользователь.
        if ($this->user_source === $this->user_target) {
            $this->setChains(array(array($this->user_source)));
            return;
        }

        // Цепочка первого порядка: проверяем, являются ли пользователь-источник и целевой пользователь друзьями.
        if ($this->isFriends($this->user_source, $this->user_target)) {
            $this->setChains(array(array($this->user_source, $this->user_target)));
            return;
        }

        // Попытка построения цепочек второго и выше порядков.
        foreach (self::$chain_orders as $order => $_) {
            $chains = $this->buildChains($this->user_source, $this->user_target, $order);
            if (count($chains) != 0) {
                if ($this->mode == 'random_chain') {
                    $chains = array($chains[array_rand($chains)]);
                }
                $this->setChains($chains);
                return;
            }
        }
    }
}