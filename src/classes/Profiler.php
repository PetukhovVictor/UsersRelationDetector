<?

require_once __DIR__ . '/MemcacheConnector.php';

$hashes = array();

abstract class Profiler {
    const ARGS_SYMBOLS_LIMIT = 100;

    const SPREAD_SYMBOL = '...';

    const ARGS_SEPARATOR = ', ';

    const ARGS_KEY_VALUE_SEPARATOR = ' = ';

    static private function getTime() {
        return microtime(true);
    }

    static public function run($command)
    {
        $start_time = self::getTime();
        $result = $command();
        $end_time = self::getTime() - $start_time;
        // Используем PHP serializer (т. к. он же используется в memcached для нескалярных типов).
        $result_size = strlen(serialize($result['response']));

        return array(
            'result'    => $result,
            'metrics'   => array(
                'time'  => $end_time,
                'size'  => $result_size
            )
        );
    }

    static private function addMetricsData($current_metrics, $type, $metrics) {
        $default_metrics_data = array(
            'queries_count'     => 1,
            'times_sum'         => $metrics['time'],
            'sizes_sum'         => $metrics['size']
        );

        if (!$current_metrics) {
            $current_metrics = array($type => $default_metrics_data);
        } else if (!$current_metrics[$type]) {
            $current_metrics[$type] = $default_metrics_data;
        } else {
            $current_metrics[$type]['queries_count']++;
            $current_metrics[$type]['times_sum'] += $metrics['time'];
            $current_metrics[$type]['sizes_sum'] += $metrics['size'];
        }
        return $current_metrics;
    }

    static public function write($job_id, $type, $metrics, $mc = null)
    {
        if (!$mc) {
            $mc = (new MemcacheConnector())->getInstance();
        }
        do {
            $job_item_info = $mc->get("job_{$job_id}_result", null, Memcached::GET_EXTENDED);
            $job_data = $job_item_info['value'];
            $job_data['metrics'] = self::addMetricsData($job_data['metrics'], $type, $metrics);
            $mc->cas($job_item_info['cas'], "job_{$job_id}_result", $job_data);
        } while ($mc->getResultCode() != \Memcached::RES_SUCCESS);

        print_r($job_data['metrics']);
        echo 'Job: ' . $job_id;
        echo PHP_EOL;
    }

    static public function print($args = null, $end_time)
    {
        $log_args = array();

        if (!is_null($args)) {
            foreach ($args[1] as $param => $value) {
                $param_info = array(
                    $param, strlen($value) > self::ARGS_SYMBOLS_LIMIT ?
                        substr($value, 0, self::ARGS_SYMBOLS_LIMIT) . self::SPREAD_SYMBOL :
                        $value
                );
                array_push($log_args, implode(self::ARGS_KEY_VALUE_SEPARATOR, $param_info));
            }
            $log_args = implode(self::ARGS_SEPARATOR, $log_args);

            echo "{$args[0]}: $log_args" . PHP_EOL;
        }

        echo "Time: " . round($end_time % 60) . " s." . PHP_EOL . PHP_EOL;
    }
}