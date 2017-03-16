<?

class Utils {
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
}