<? namespace URD;

require_once __DIR__ . '/../API/VK.php';
require_once __DIR__ . '/../API/VKAsync.php';

require_once __DIR__ . '/../Utils.php';

require_once __DIR__ . '/Helpers.php';

class Program {
    /**
     * Минимальный интервал между вызовами методов VK API (поставлено исходя из ограничения: макс. 3 в секунду).
     *
     * @type int
     */
    const API_CALL_INTERVAL = 400000;

    /**
     * Размер 'порции' пользователей, которой дозволено оперировать при работе с получением информации о пользователях через VK API.
     *
     * @type int
     */
    const USERS_CHUNK_SIZE = 300;

    /**
     * Размер 'порции' друзей, которой дозволено оперировать при работе с получением общих друзей через VK API.
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
     * Пусть к скрипту (VKScript), получающему списки общих друзей для заданого набора пользователей.
     *
     * @type string
     */
    const COMMON_FRIENDS_CHUNK_SCRIPT_PATH = __DIR__ . '/../../scripts/commonFriends.vks';

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
     * Проложенные от одного пользователя к другому цепочки из друзей (массив массивов id пользователей).
     *
     * @type int[][]
     */
    private $chains;

    /**
     * Режим вывода цепочки (random_chain - вывести одну случайную цепочку, full_chain - вывести всевозможные цепочки).
     *
     * @type string
     */
    private $mode;

    /**
     * Длина проложенной от одного пользователя к другому цепочки из друзей (количество рукопожатий).
     *
     * @type int
     */
    private $chain_length;

    /**
     * Конструктор.
     *
     * @param   array $app          Данные VK-приложения, которое будет использоваться для запросов к API.
     * @param   int $user_source    ID пользователя-источника (от которого нужно строить цепочку рукопожатий).
     * @param   int $user_target    ID целевого пользователя (к которому нужно строить цепочку рукопожатий).
     * @param   array $option       Дополнительные опции.
     */
    public function __construct($user_source, $user_target, $app, $option = null)
    {
        $this->vk = new \VK($app['id'], $app['secret'], $app['access_token']);
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
     * @return  mixed Результат выполнения заданного запроса к VK API.
     */
    private function API()
    {
        usleep(self::API_CALL_INTERVAL);
        $vk = $this->vk;
        $result = call_user_func_array(array($vk, 'api'), func_get_args());

        if (!isset($result['response']) && isset($result['error'])) {
            throw new \Exception("Error code {$result['error']['error_code']}: {$result['error']['error_msg']}");
        } elseif (!isset($result['response'])) {
            print_r(func_get_args());
            throw new \Exception("Unknown error.");
        }

        return $result['response'];
    }

    /**
     * Метод-обертка для асинхронного выполнения запросов к VK API.
     *
     * Число аргументов - произвольное, в соответствии с заданным методом VK API (аргументы транслируются в него),
     * за исключением последнего аргумента - он должен являться объектов Threaded, представляющий из себя разделяемую память,
     * в него будет записываться результат выполнения запроса.
     *
     * @return \VKAsync Worker, представляющий из себя отдельный поток выполнения.
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
     * @return  array[int] Отфильтрованный массив пользователей.
     */
    private function filteringDeletedUser($users)
    {
        $users_chunked = array_chunk($users, self::USERS_CHUNK_SIZE);

        $workers = array();
        $result_stack = new \Threaded();

        $users_filtered = array();
        foreach($users_chunked as $users_chunk) {
            $worker = $this->APIAsync(
                array('users.get', array('user_ids' => implode(',', $users_chunk))),
                $result_stack
            );
            array_push($workers, $worker);
        }

        \Utils::workersSync($workers);

        foreach ($result_stack as $result_chunk) {
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

    private function buildChainsCommon($user_source, $user_target, $order) {
        $user_source_friends = $this->getFriends($user_source);
        $user_source_friends = $this->filteringDeletedUser($user_source_friends);
        $user_source_friends = array_chunk($user_source_friends, self::FRIENDS_CHUNK_SIZE);

        $workers = array();
        $result_stack = new \Threaded();

        switch ($order) {
            case 3:
                $this->buildThirdOrderChains(
                    $user_source_friends,
                    $user_target,
                    array('workers' => $workers, 'result_stack' => $result_stack)
                );
                break;
            case 4:
                $this->buildFourthOrderChains(
                    $user_source_friends,
                    $user_target,
                    array('workers' => $workers, 'result_stack' => $result_stack)
                );
                break;
            case 5:
                $this->buildFifthOrderChains(
                    $user_source_friends,
                    $user_target,
                    array('workers' => $workers, 'result_stack' => $result_stack)
                );
                break;
        }

        \Utils::workersSync($workers);

        $multidimensional_mutual_friends = array();
        foreach ($result_stack as $result_chunk) {
            // Если результат (список друзей) должен был быть связан с другим другом,
            // дописываем его ID, создая ещё один уровень вложенности.
            $result_chunk = !isset($result_chunk->linked_data) ? $result_chunk->data : array(
                array(
                    'id' => $result_chunk->linked_data['friend_id'],
                    'common_friends' => $result_chunk->data
                )
            );
            // Нормализуем многомерный ассоциативный массив, полученный от VK API (преобразуем в удобный вид).
            $result = Helpers::buildMultidimensionalFriendsMap($result_chunk, array());
            // Меняем местами последний уровень массива и препоследний
            // (в виду специфики структуры объекта, отадаваемого VK API).
            $result = \Utils::swapLastDepthsMultidimensionalArray($result, array());
            $multidimensional_mutual_friends = array_merge_recursive($multidimensional_mutual_friends, $result);
        }

        // Преобразуем многомерный ассоциативный массив цепочек друзей в массив цепочек (линеаризуем).
        $chains_info = Helpers::getChainsByMultidimensionalFriendsMap($multidimensional_mutual_friends);
        $chains = $chains_info['chains'];
        // Добавляем в каждую цепочку пользователя-источника и целевого пользователя.
        $chains = Helpers::addEndpointUsers($chains, array($user_source), array($user_target));
        return $chains;
    }

    /**
     * Построение цепочки третьего порядка.
     */
    private function buildThirdOrderChains($user_source_friends, $user_target, $params)
    {
        foreach ($user_source_friends as $user_source_friends_chunk) {
            array_push(
                $params['workers'],
                $this->getCommonFriends($user_target, $user_source_friends_chunk, $params['result_stack'])
            );
        }
    }

    /**
     * Построение цепочки четвертого порядка.
     */
    private function buildFourthOrderChains($user_source_friends, $user_target, $params) {
        $user_target_friends = $this->getFriends($user_target);
        $user_target_friends = $this->filteringDeletedUser($user_target_friends);
        $user_target_friends = array_chunk($user_target_friends, self::MAX_QUERIES_NUMBER);

        $params['need_set_linked_data'] = $params['need_set_linked_data'] ?? false;

        foreach ($user_source_friends as $source_friend) {
            foreach ($user_target_friends as $target_friend) {
                $vk_script_code = \Utils::template($this->mutual_friends_vk_script, array(
                    'source_friends' => implode(',', $target_friend),
                    'target_friends' => implode(',', $source_friend)
                ));
                $linked_data = $params['need_set_linked_data'] ? array('friend_id' => $user_target) : null;
                $worker = $this->APIAsync(
                    array('execute', array('code' => $vk_script_code)),
                    $params['result_stack'],
                    $linked_data
                );
                array_push($params['workers'], $worker);
            }
        }
    }

    /**
     * Построение цепочки пятого порядка.
     */
    private function buildFifthOrderChains($user_source_friends, $user_target, $params) {
        $user_target_friends = $this->getFriends($user_target);
        $user_target_friends = $this->filteringDeletedUser($user_target_friends);

        $params['need_set_linked_data'] = true;

        foreach ($user_target_friends as $target_friend) {
            $this->buildFourthOrderChains($user_source_friends, $target_friend, $params);
        }
    }

    /**
     * Получение друзей заданного пользователя.
     *
     * @param   int $user_id   ID пользователя, друзей которого необходимо получить.
     *
     * @return  array[int] Список ID пользователей-друзей указанного пользователя.
     */
    private function getFriends($user_id)
    {
        return $this->API('friends.get', array(
            'user_id' => $user_id
        ));
    }

    /**
     * Проверка на то, являются ли два заданных пользователя друзьями.
     *
     * @param   int $user_id1   ID 1-го пользователя.
     * @param   int $user_id2   ID 2-го пользователя.
     *
     * @return  boolean Флаг, показывающий, являются ли пользователи друзьями.
     */
    private function isFriends($user_id1, $user_id2)
    {
        $friends = $this->getFriends($user_id1);
        return array_search($user_id2, $friends) !== false;
    }

    /**
     * Получение списка общих друзей между пользователем и списком других заданных пользователей.
     *
     * @param   int $user_source                ID пользователя-объекта для получения списка общих друзей.
     * @param   int | array[int] $users_target  ID пользователя или массив ID пользователей,
     *                                          общих друзей заданного пользователя с которым(и) нужно получить.
     * @param   \Threaded $result_array         Объект разделяемой памяти для записи в него списка общих друзей.
     *                                          В случае, если $result_array не задан, запрос выполняется синхронно.
     *
     * @return \VKAsync|mixed Worker, представляющий из себя отдельный поток выполнения, либо результат выполнения запроса
     *  (если запрос выполнялся синхронно).
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
        return $this->chains;
    }

    /**
     * Получение длины цепочки.
     *
     * @return int    Длина цепочки друзей.
     */
    public function getChainLength()
    {
        return $this->chain_length;
    }

    /**
     * Построение цепочек друзей (по нарастающей: построение цепочки более высокого порядка при ненахождении цепочек более низкого порядка).
     */
    public function buildChains()
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

        // Цепочка второго порядка: проверяем, имеют ли пользователь-источник и целевой пользователь общих друзей.
        $mutual_friends = $this->getCommonFriends($this->user_source, $this->user_target);
        if (count($mutual_friends) != 0) {
            $chains = array();
            if ($this->mode == 'random_chain') {
                $random_mutual_friend = array_rand($mutual_friends);
                array_push($chains, array($this->user_source, $mutual_friends[$random_mutual_friend], $this->user_target));
            } else {
                foreach ($mutual_friends as $mutual_friend) {
                    array_push($chains, array($this->user_source, $mutual_friend, $this->user_target));
                }
            }
            $this->setChains($chains);
            return;
        }

        // Цепочка третьего порядка: проверяем, имеют ли друзья пользователя-источника общих друзей с целевым пользователем.
        $third_order_chain = $this->buildChainsCommon($this->user_source, $this->user_target, 3);
        if (count($third_order_chain) != 0) {
            if ($this->mode == 'random_chain') {
                $third_order_chain = $third_order_chain[array_rand($third_order_chain)];
                $this->setChains(array($third_order_chain));
            } else {
                $this->setChains($third_order_chain);
            }
            return;
        }

        // Цепочка четвертого порядка: проверяем, имеют ли друзья пользователя-источника общих друзей с друзьями целевого пользователя.
        $fourth_order_chain = $this->buildChainsCommon($this->user_source, $this->user_target, 4);
        if (count($fourth_order_chain) != 0) {
            if ($this->mode == 'random_chain') {
                $fourth_order_chain = $fourth_order_chain[array_rand($fourth_order_chain)];
                $this->setChains(array($fourth_order_chain));
            } else {
                $this->setChains($fourth_order_chain);
            }
            return;
        }

        // Цепочка пятого порядка: проверяем, имеют ли друзья друзей пользователя-источника общих друзей с друзьями целевого пользователя.
        $fifth_order_chain = $this->buildChainsCommon($this->user_source, $this->user_target, 5);
        if (count($fifth_order_chain) != 0) {
            if ($this->mode == 'random_chain') {
                $fifth_order_chain = $fifth_order_chain[array_rand($fifth_order_chain)];
                $this->setChains(array($fifth_order_chain));
            } else {
                $this->setChains($fifth_order_chain);
            }
            return;
        }
    }
}