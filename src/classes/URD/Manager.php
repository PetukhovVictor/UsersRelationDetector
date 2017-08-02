<? namespace URD;

require_once __DIR__ . '/../Utils.php';
require_once __DIR__ . '/../Jobber.php';

require_once __DIR__ . '/Program.php';

/**
 * Class Manager - класс для управления работой программы URD (абстракция над URD).
 *
 * @package URD
 */
final class Manager extends \Jobber {
    /**
     * Идентификатор приложения ВКонтакте, через которое будут осуществляться запросы к API.
     */
    const APP_ID = 0;

    /**
     * Secret приложения ВКонтакте, через которое будут осуществляться запросы к API.
     */
    const APP_SECRET = '';

    /**
     * Ссылка на объект, предоставляющий функционал для работы с VK API.
     *
     * @type \VK
     */
    private $vk;

    /**
     * Ассоциативный массив с параметрами программы.
     *
     * @type [string => mixed]
     */
    private $program_params;

    /**
     * Конструктор.
     *
     * @param   string              $access_token   Токен для доступа к VK API.
     * @param   [string => mixed]   $program_params Ассоциативный массив с параметрами программы.
     */
    public function __construct($access_token, $program_params)
    {
        $this->program_params = $program_params;
        $this->vk = new \VK(self::APP_ID, self::APP_SECRET, $access_token);
        parent::__construct();
    }

    /**
     * Запуск программы.
     * Помимо запуска здесь создаём Job'у, делаем замеры времени работы и записываем результаты в in-memory базу.
     */
    public function runProgram()
    {
        /*
         * Создаём Job'у для построения цепочек.
         * В этот момент происходит отвязывание скрипта от стандартного потока.
         * В поток возвращается идентификатор Job'ы.
         */
        $this->createJob();

        // Записываем 'начальный' промежуточный результат.
        $this->recordIntermediateResult($this->job_id, 0, array());

        $start_time = time();
        $urd = new Program(
            $this->program_params['user_source'],
            $this->program_params['user_target'],
            $this->vk,
            array(
                'mode' => $this->program_params['mode']
            )
        );
        $urd->run();
        $end_time = time() - $start_time;

        // Записываем окончательный результат.
        $this->recordResult($this->job_id, $urd->getChains(), $end_time);
    }
}