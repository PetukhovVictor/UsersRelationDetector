<?

abstract class Utils {
    static public function arrayFlatten($array) {
        if (!is_array($array)) {
            return false;
        }
        $result = array();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = array_merge($result, self::arrayFlatten($value));
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    static public function template($source_code, $vars = array()) {
        ob_start();
        extract($vars);
        eval(' ?>' . $source_code . '<?php ');
        $result = ob_get_contents();
        ob_end_clean();
        return $result;
    }

    static public function removeWrapArray($multidimensional_array) {
        return array_reduce(
            $multidimensional_array,
            function($chunk1, $chunk2) {
                return $chunk1 + $chunk2;
            },
            array()
        );
    }

    static public function swapLastDepthsMultidimensionalArray($array, $swapped_multidimensional_array, $level = 0) {
        foreach ($array as $key => $sub_array) {
            if (self::getArrayDepth($sub_array) > 2) {
                $swapped_multidimensional_array[$key] = self::swapLastDepthsMultidimensionalArray(
                    $sub_array,
                    array(),
                    $level + 1
                );
            } else {
                if (self::isMultidimensionalArray($sub_array)) {
                    $swapped_multidimensional_array[$key] = array();
                    foreach ($sub_array as $endpoint_key => $endpoint_array) {
                        foreach ($endpoint_array as $value) {
                            if (!isset($swapped_multidimensional_array[$key][$value])) {
                                $swapped_multidimensional_array[$key][$value] = array();
                            }
                            array_push($swapped_multidimensional_array[$key][$value], $endpoint_key);
                        }
                    }
                } else {
                    foreach ($sub_array as $value) {
                        if (!isset($swapped_multidimensional_array[$value])) {
                            $swapped_multidimensional_array[$value] = array();
                        }
                        array_push($swapped_multidimensional_array[$value], $key);
                    }
                }
            }
        }
        return $swapped_multidimensional_array;
    }

    static public function isMultidimensionalArray($array) {
        if (!is_array($array)) {
            return false;
        }
        foreach ($array as $element) {
            if (!is_array($element)) {
                return false;
            }
        }
        return true;
    }

    static public function getArrayDepth($array) {
        $max_depth = 1;

        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = self::getArrayDepth($value) + 1;
                if ($depth > $max_depth) {
                    $max_depth = $depth;
                }
            }
        }

        return $max_depth;
    }

    static public function workersSync($workers) {
        foreach ($workers as $worker) {
            $worker->join();
        }
    }
}