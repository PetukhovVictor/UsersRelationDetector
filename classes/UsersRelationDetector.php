<?

require('./API/VK.php');
require('./API/VKAsync.php');
require('./classes/Utils.php');

class UsersRelationDetector {
    /**
     * Минимальный интервал между вызовами методов VK API (поставлено исходя из ограничения: макс. 3 в секунду).
     *
     * @type int
     */
    const API_CALL_INTERVAL = 333333;

    /**
     * Размер 'порции' пользователей, которой дозволено оперировать при работе с получением информации о пользователях через VK API.
     *
     * @type int
     */
    const USERS_CHUNK_SIZE = 1000;

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
     * Ссылка на объект, предоставляющий функционал для работы с VK API.
     *
     * @type VK
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
     * Метод-хелпер для форматирования списка общих друзей в удобный для дальнейшей работы формат.
     *
     * Пример входного массива:
     *
     *  Array(
     *      [0] => Array(
     *          [id] => 110001
     *          [common_friends] => Array(
     *              [0] => Array(
     *                  [id] => 110002
     *                  [common_friends] => Array(
     *                      [0] => 110003
     *                      [1] => 110004
     *                      [2] => 110005
     *                      [3] => 110006
     *                  )
     *                  [common_count] => 4
     *              )
     *          )
     *      )
     *      [1] => Array(
     *          [id] => 110007
     *          [common_friends] => Array(
     *              [0] => Array(
     *                  [id] => 110008
     *                  [common_friends] => Array(
     *                      [0] => 110009
     *                      [1] => 1100010
     *                  )
     *                  [common_count] => 2
     *              )
     *              [1] => Array(
     *                  [id] => 1100011
     *                  [common_friends] => Array(
     *                      [0] => 1100012
     *                  )
     *                  [common_count] => 1
     *              )
     *          )
     *      )
     *  )
     *
     * Пример выходного массива:
     *
     *  Array(
     *      [110001] => Array(
     *          [110002] => Array(
     *              [0] => 110003
     *              [1] => 110004
     *              [2] => 110005
     *              [3] => 110006
     *          )
     *      )
     *      [110007] => Array(
     *          [110008] => Array(
     *              [0] => 110009
     *              [1] => 1100010
     *          )
     *          [1100011] => Array(
     *              [0] => 1100012
     *          )
     *      )
     *  )
     *
     * @param   array $common_friends Многоуровневый массив со списком общих друзей, возвращенный VK API.
     * @param   array $mutual_friends_formatted Форматируемый многуровневый массив со списком общих друзей, прокидываемый по рекурсии.
     *
     * @return  array Отформатированный на данной глубине рекурсии многуровневый массив со списком общих друзей.
     */
    static private function buildMultidimensionalFriendsMap($common_friends, $mutual_friends_formatted)
    {
        foreach ($common_friends as $common_friend) {
            if (is_array($common_friend)) {
                $mutual_friends_formatted['id' . $common_friend['id']] = self::buildMultidimensionalFriendsMap($common_friend['common_friends'], array());
            } else {
                array_push($mutual_friends_formatted, 'id' . $common_friend);
            }
        }
        return $mutual_friends_formatted;
    }

    /**
     * Метод-хелпер для преобразования многомерного ассоциативного массива цепочек друзей в одномерный массив цепочек.
     *
     * Пример входного массива:
     *
     *  array(
     *      '1277081' => array(
     *          '183800139' => array(
     *              '64439049',
     *              '173811066',
     *              '138183334'
     *          ),
     *          '12352135' => array(
     *              '5234334',
     *              '12355111'
     *          )
     *      ),
     *      '1277081' => array(
     *          '6543213' => array(
     *              '7812345'
     *          )
     *      )
     *  )
     *
     * Пример выходного массива:
     *
     *  array(
     *      array(64439049,183800139,1277081),
     *      array(173811066,183800139,1277081),
     *      array(138183334,183800139,1277081),
     *      array(5234334,12352135,1277081),
     *      array(12355111,12352135,1277081),
     *      array(7812345,6543213,1277081)
     *  )
     *
     * @param array $array Исходный многомерный ассоциативный массив цепочек друзей.
     * @param array $chains Транслируемый по рекурсии массив цепочек друзей (в конечном счете - итоговый целевой массив).
     *
     * @return array(
     *      'chains' => array,
     *      'chains_offset' => int
     *  ) Массив цепочек друзей на определенной стадии готовности
     *  и смещение (для отсеивания уже полностью готовых (составленных) цепочек).
     */
    static private function getChainsByMultidimensionalFriendsMap($array, $chains = array()) {
        // Запоминаем кол-во уже готовых цепочек - они и будут являться смещением для 'вышестоящей рекурсии'.
        $chains_offset = count($chains);
        foreach ($array as $array_key => $child_element) {
            // Если элемент - массив, продолжаем идти вглубь по рекурсии.
            if (is_array($child_element)) {
                $chains_info = self::getChainsByMultidimensionalFriendsMap($child_element, $chains);
                $chains = $chains_info['chains'];
                $friend_id = $array_key;
                // После выхода из рекурсии дописываем 'промежуточного друга' к каждой цепочке согласно переданному смещению.
                for ($i = $chains_info['chains_offset']; $i < count($chains); $i++) {
                    array_push($chains[$i], $friend_id);
                }
                // Если элемент - не массив, создаём для каждого такого элемента новую цепочку
                // (это самый глубокий уровень рекурсии - здесь находятся 'endpoint-друзья').
            } else {
                $friend_id = $child_element;
                $chain = array($friend_id);
                array_push($chains, $chain);
            }
        }
        return array(
            'chains' => $chains,
            'chains_offset' => $chains_offset
        );
    }

    /**
     * Метод-хелпер для получения количества 'endpoint-друзей' в переданном многомерном ассоциативном массиве цепочек друзей.
     * Метод используется для подсчета суммарного числа найденных общих друзей.
     *
     * Пример входного массива:
     *
     *  array(
     *      '1277081' => array(
     *          '183800139' => array(
     *              '64439049',
     *              '173811066',
     *              '138183334'
     *          ),
     *          '12352135' => array(
     *              '5234334',
     *              '12355111'
     *          )
     *      ),
     *      '1277081' => array(
     *          '6543213' => array(
     *              '7812345'
     *          )
     *      )
     *  )
     *
     * Пример выходного значения:
     *
     *  6
     *
     * @param array $friends Исходный многомерный ассоциативный массив цепочек друзей.
     * @param int $number Транслируемое по рекурсии промежуточное число 'endpoint-друзей' и равное в конечном счете искомому числу.
     *
     * @return int Число 'endpoint-друзей'.
     */
    static private function getNumberEndpointFriends($friends, $number = 0) {
        if (!is_array($friends[0])) {
            return count($friends);
        }
        foreach ($friends as $array_key => $child_element) {
            if (is_array($child_element[0])) {
                $number += self::getNumberEndpointFriends($child_element);
            } else {
                $number += count($child_element);
            }
        }
        return $number;
    }

    /**
     * Дописывание исходного и целевого пользователей к найденным цепочкам (для дальнейшего вывода).
     *
     * @param array $chains Массив цепочек друзей.
     * @param int $user_source Исходный пользователь.
     * @param int $user_target Целевой пользователь.
     *
     * @return array Массив цепочек друзей с дописанными исходным и целевым пользователем.
     */
    static private function appendTargetUsers($chains, $user_source, $user_target) {
        foreach ($chains as &$chain) {
            array_unshift($chain, 'id' . $user_source);
            array_push($chain, 'id' . $user_target);
        }
        return $chains;
    }

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
        $this->vk = new VK($app['id'], $app['secret'], $app['access_token']);
        $this->user_source = $user_source;
        $this->user_target = $user_target;
        $this->mode = $option['mode'] ?? 'random_chain';
        $this->mutual_friends_vk_script = file_get_contents($option['mutual_friends_script_path'] ?? 'mutualFriends.vks');
    }

    /**
     * Метод-обертка для выполнения запросов к VK API.
     *
     * Число аргументов - произвольное, в соответствии с заданным методом VK API (аргументы транслируются в него).
     *
     * @throws Exception
     *
     * @return  mixed Результат выполнения заданного запроса к VK API.
     */
    private function API()
    {
        usleep(self::API_CALL_INTERVAL);
        $vk = $this->vk;
        $result = call_user_func_array(array($vk, 'api'), func_get_args());

        if (!isset($result['response']) && isset($result['error'])) {
            throw new Exception("Error code {$result['error']['error_code']}: {$result['error']['error_msg']}");
        } elseif (!isset($result['response'])) {
            throw new Exception("Unknown error.");
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
     * @return VKAsync Worker, представляющий из себя отдельный поток выполнения.
     */
    private function APIAsync()
    {
        usleep(self::API_CALL_INTERVAL);
        $api_args = func_get_args();
        $result_array = array_pop($api_args);
        return new VKAsync($this->vk, $api_args, $result_array);
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
        $users_filtered = array();
        foreach($users_chunked as $users_chunk) {
            $users_info = $this->API('users.get', array(
                'user_ids' => implode(',', $users_chunk)
            ));
            $users_filtered_full = array_filter($users_info, function($user) {
                return empty($user['deactivated']);
            });
            foreach ($users_filtered_full as &$user) {
                $user = $user['uid'];
            }
            $users_filtered = array_merge($users_filtered, $users_filtered_full);
        }
        return $users_filtered;
    }

    private function buildChainsCommon($user_source, $users_target, $order) {
        $friends = $this->getFriends($user_source);
        $friends = $this->filteringDeletedUser($friends);
        $friends_chunked = array_chunk($friends, self::FRIENDS_CHUNK_SIZE);

        $workers = array();
        $result_stack = new Threaded();

        switch ($order) {
            case 3:
                $this->buildThirdOrderChains($friends_chunked, $workers, $result_stack, $users_target);
                break;
            case 4:
                $this->buildFourthOrderChains($friends_chunked, $workers, $result_stack, $users_target);
                break;
        }

        Utils::workersSync($workers);

        $multidimensional_mutual_friends = array();
        foreach ($result_stack as $result_chunk) {
            // Нормализуем многомерный ассоциативный массив, полученный от VK API (преобразуем в удобный вид).
            $result = self::buildMultidimensionalFriendsMap($result_chunk->data, array());
            // Меняем местами последний уровень массива и препоследний
            // (в виду специфики структуры объекта, отадаваемого VK API).
            $result = Utils::swapLastDepthsMultidimensionalArray($result, array());
            $multidimensional_mutual_friends = array_merge_recursive($multidimensional_mutual_friends, $result);
        }

        // Преобразуем многомерный ассоциативный массив цепочек друзей в массив цепочек (линеаризуем).
        $chains_info = self::getChainsByMultidimensionalFriendsMap($multidimensional_mutual_friends);
        $chains = $chains_info['chains'];
        // Добавляем в каждую цепочку пользователя-источника и целевого пользователя.
        $chains = self::appendTargetUsers($chains, $user_source, $users_target);
        return $chains;
    }

    /**
     * Построение цепочки третьего порядка.
     */
    private function buildThirdOrderChains($friends_chunked, &$workers, $result_stack, $users_target)
    {
        foreach ($friends_chunked as $friends_chunk) {
            array_push(
                $workers,
                $this->getCommonFriends($users_target, $friends_chunk, $result_stack)
            );
        }
    }

    /**
     * Построение цепочки четвертого порядка.
     */
    private function buildFourthOrderChains($friends_chunked, &$workers, $result_stack, $users_target) {
        $friends2 = $this->getFriends($users_target);
        $friends2 = $this->filteringDeletedUser($friends2);
        $friends2_chunked = array_chunk($friends2, self::MAX_QUERIES_NUMBER);

        foreach ($friends_chunked as $friends1) {
            foreach ($friends2_chunked as $friends2) {
                $vk_script_code = Utils::template($this->mutual_friends_vk_script, array(
                    'source_friends'    => implode(',', $friends2),
                    'target_friends'    => implode(',', $friends1)
                ));
                array_push(
                    $workers,
                    $this->APIAsync('execute', array('code' => $vk_script_code), $result_stack)
                );
            }
        }
    }

    /**
     * Получение друзей заданного пользователя.
     *
     * @param   int $user_id   ID пользователя, друзей которого необходимо получить.
     *
     * @throws Exception
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
     * @param   Threaded $result_array          Объект разделяемой памяти для записи в него списка общих друзей.
     *                                          В случае, если $result_array не задан, запрос выполняется синхронно.
     *
     * @return VKAsync|mixed Worker, представляющий из себя отдельный поток выполнения, либо результат выполнения запроса
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
            return $this->APIAsync('friends.getMutual', array(
                'source_uid' => $user_source,
                $target_property_name => $users_target
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
    }
}