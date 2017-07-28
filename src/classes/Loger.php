<?

$hashes = array();

class Loger {
    private $start_time;

    private $end_time;

    static private $args_symbols_limit = 100;

    static private $spread_symbol = '...';

    static private $args_separator = ', ';

    static private $args_key_value_separator = ' = ';

    public function start() {
        $this->start_time = time();
        return $this;
    }

    public function end() {
        $this->end_time = time() - $this->start_time;
        return $this;
    }

    public function print($args = null) {
        $log_args = array();

        if (!is_null($args)) {
            foreach ($args[1] as $param => $value) {
                $param_info = array(
                    $param, strlen($value) > self::$args_symbols_limit ?
                        substr($value, 0, self::$args_symbols_limit) . self::$spread_symbol :
                        $value
                );
                array_push($log_args, implode(self::$args_key_value_separator, $param_info));
            }
            $log_args = implode(self::$args_separator, $log_args);

            file_put_contents("log.txt", "{$args[0]}: $log_args" . PHP_EOL, FILE_APPEND);
        }

        file_put_contents("log.txt", md5(print_r($args, 1)) . PHP_EOL . PHP_EOL, FILE_APPEND);
        echo "Time: " . round($this->end_time % 60) . " s." . PHP_EOL;
    }
}