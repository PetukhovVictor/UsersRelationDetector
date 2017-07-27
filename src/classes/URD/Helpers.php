<? namespace URD;

abstract class Helpers {
    /**
     * Метод-хелпер для индексации списков друзей.
     *
     * Пример входных данных:
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
     * Пример выходных данных:
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
     * @param   array $common_friends           Многоуровневый массив со списком общих друзей, возвращенный VK API.
     * @param   array $mutual_friends_formatted Форматируемый многуровневый массив со списком общих друзей, прокидываемый по рекурсии.
     *
     * @return  array                           Отформатированный на данной глубине рекурсии многуровневый массив со списком общих друзей.
     */
    static public function indexingCommonFriends($common_friends, $mutual_friends_formatted)
    {
        foreach ($common_friends as $common_friend) {
            if (is_array($common_friend)) {
                $mutual_friends_formatted['id' . $common_friend['id']] = self::indexingCommonFriends($common_friend['common_friends'], array());
            } else {
                array_push($mutual_friends_formatted, 'id' . $common_friend);
            }
        }
        return $mutual_friends_formatted;
    }

    /**
     * Метод-хелпер для линеаризации цепочек друзей.
     *
     * Пример входных данных:
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
     * Пример выходных данных:
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
     * @param   array $array    Исходный многомерный ассоциативный массив цепочек друзей.
     * @param   array $chains   Транслируемый по рекурсии массив цепочек друзей (в конечном счете - итоговый целевой массив).
     *
     * @return  array('chains' => array, 'chains_offset' => int)
     *                          Массив цепочек друзей на определенной стадии готовности
     *                          и смещение (для отсеивания уже полностью готовых (составленных) цепочек).
     */
    static public function linearizeCommonFriendsMap($array, $chains = array()) {
        // Запоминаем кол-во уже готовых цепочек - они и будут являться смещением для списка на более низкой глубине рекурсии.
        $chains_offset = count($chains);
        foreach ($array as $array_key => $child_element) {
            // Если элемент - массив, продолжаем идти вглубь по рекурсии.
            if (is_array($child_element)) {
                $chains_info = self::linearizeCommonFriendsMap($child_element, $chains);
                $chains = $chains_info['chains'];
                $friend_id = $array_key;
                // После выхода из рекурсии дописываем промежуточного друга к каждой цепочке согласно переданному смещению.
                for ($i = $chains_info['chains_offset']; $i < count($chains); $i++) {
                    array_push($chains[$i], $friend_id);
                }
                /*
                 * Если элемент - не массив, создаём для каждого такого элемента новую цепочку
                 * (это самый глубокий уровень рекурсии - здесь находятся endpoint-друзья).
                 */
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
     * Метод-хелпер для получения количества endpoint-друзей в переданном многомерном ассоциативном массиве цепочек друзей.
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
     * @param   array $friends   Исходный многомерный ассоциативный массив цепочек друзей.
     * @param   int $number      Транслируемое по рекурсии промежуточное число 'endpoint-друзей' и равное в конечном счете искомому числу.
     *
     * @return  int              Число 'endpoint-друзей'.
     */
    static public function getNumberEndpointFriends($friends, $number = 0) {
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
     * Дописывание пользователей к левой и правой частям найденных цепочкек (для дальнейшего вывода).
     *
     * @param   array $chains           Массив цепочек друзей.
     * @param   array $users_left_side  Пользователи, дописываемые к левой части цепочек.
     * @param   array $users_right_side Пользователи, дописываемые к правой части цепочек.
     *
     * @return  array                   Массив цепочек друзей с дописанными исходным и целевым пользователем.
     */
    static public function addEndpointUsers($chains, $users_left_side, $users_right_side) {
        foreach ($chains as &$chain) {
            foreach ($users_left_side as $user) {
                array_unshift($chain, 'id' . $user);
            }
            foreach ($users_right_side as $user) {
                array_push($chain, 'id' . $user);
            }
        }
        return $chains;
    }
}
