<?

require('classes/URD/Manager.php');

$urd_manager = new URD\Manager(array(
    'user_source'   => 0,
    'user_target'   => 0,
    'mode'          => 'all_chains'
));

$urd_manager->runProgram();