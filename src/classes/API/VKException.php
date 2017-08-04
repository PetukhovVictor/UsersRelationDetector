<?php

/**
 * The exception class for VK library.
 * @author Vlad Pronsky <vladkens@yandex.ru>
 * @license https://raw.github.com/vladkens/VK/master/LICENSE MIT
 */

namespace VK;
 
class VKException extends \Exception {
    /**
     * Проверка результата выполнения запроса к VK API.
     *
     * @param   mixed $result  Результат выполнения запроса к VK API.
     *
     * @throws \Exception
     */
    static public function checkResult($result) {
        if (!isset($result['response']) && isset($result['error'])) {
            throw new self("Error code {$result['error']['error_code']}: {$result['error']['error_msg']}");
        } elseif (!isset($result['response'])) {
            throw new self("Unknown error.");
        }
    }
}
