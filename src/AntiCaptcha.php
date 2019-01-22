<?php

namespace AntiCaptcha;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Log\AbstractLogger;
use AntiCaptcha\Service\AbstractService;
use AntiCaptcha\Exception\AntiCaptchaException;
use AntiCaptcha\Exception\InvalidAntiCaptchaServiceException;

/**
 * Class AntiCaptcha
 * @package AntiCaptcha
 */
class AntiCaptcha
{
    /** @var AbstractService $service */
    protected $service;

    /** @var GuzzleClient $client */
    protected $client;

    /** @var AbstractLogger $logger */
    protected $logger;

    /**
     * @var bool
     */
    protected $debugMod = false;

    /** @var array */
    protected $options = [
        // задержка между опросами статуса капчи
        'timeout_ready' => 3,

        // время ожидания ввода капчи
        'timeout_max' => 120,
    ];

    /**
     * Constants list
     */
    const SERVICE_ANTICAPTCHA = 'AntiCaptcha';
    const SERVICE_ANTIGATE = 'Antigate';
    const SERVICE_CAPTCHABOT = 'Captchabot';
    const SERVICE_RUCAPTCHA = 'Rucaptcha';

    /**
     * Captcha service list
     *
     * @var array
     */
    protected static $serviceMap = [
        self::SERVICE_ANTICAPTCHA,
        self::SERVICE_ANTIGATE,
        self::SERVICE_CAPTCHABOT,
        self::SERVICE_RUCAPTCHA,
    ];

    /**
     * AntiCaptcha constructor.
     * @param string|AbstractService $service
     * @param array $options
     *
     * @throws AntiCaptchaException
     * @throws InvalidAntiCaptchaServiceException
     */
    public function __construct($service, array $options = [])
    {
        if (is_string($service)) {
            if (false === in_array($service, self::$serviceMap)) {
                throw new InvalidAntiCaptchaServiceException($service);
            }

            $serviceNamespace = '\\AntiCaptcha\\Service\\' . $service;
            $service = new $serviceNamespace;
        }

        if ($service) {
            $this->setService($service);

            if (!empty($options['api_key'])) {
                $this->getService()->setApiKey($options['api_key']);
                unset($options['api_key']);
            }
        }

        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }

        if (!empty($options['debug'])) {
            $this->debugMod = true;
            $this->setLogger(new Logger);
        }

        // set Http Client
        $this->setClient(new GuzzleClient);
    }


    /**
     * Anticaptcah service provider.
     * @param AbstractService $service
     *
     * @return $this
     */
    public function setService(AbstractService $service)
    {
        $this->service = $service;

        return $this;
    }


    /**
     * Method getService description.
     *
     * @return mixed
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * Set your custom Logger
     * @param AbstractLogger $logger
     *
     * @return AntiCaptcha
     */
    public function setLogger(AbstractLogger $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * HttpClient.
     * @param $client
     *
     * @return AntiCaptcha
     */
    public function setClient($client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @param $debugString
     */
    protected function debug($debugString)
    {
        if ($this->debugMod && $this->logger) {
            $this->logger->debug($debugString);
        }
    }

    /**
     * Method balance description.
     *
     * @return mixed
     * @throws AntiCaptchaException
     */
    public function balance()
    {
        $this->debug("check ballans ...");

        $url = $this->getService()->getApiUrl() . '/res.php';

        $this->debug('connect to: ' . $url);

        $request = $this->client->request('GET', $url, [
            'query' => [
                'key' => $this->getService()->getApiKey(),
                'action' => 'getbalance'
            ]
        ]);

        $body = $request->getBody();
        $this->debug('result: ' . $body);

        if (strpos($body, 'ERROR') !== false) {
            throw new AntiCaptchaException($body);
        }

        return $body;
    }


    /**
     * Method recognize description.
     * @param $image
     * @param null $url
     * @param array $params
     *
     * @return string|null
     * @throws AntiCaptchaException
     */
    public function recognize($image, $url = null, $params = [])
    {
        if (null !== $url) {
            $request = $this->client->request('GET', $url);
            $image = $request->getBody();
        }

        if (!empty($params)) {
            $this->getService()->setParams($params);
        }

        $captchaId = $this->sendImage($image);

        if (empty($captchaId)) {
            return null;
        }

        return $this->getResult($captchaId);
    }


    /**
     * Method sendImage description.
     * @param $image
     *
     * @return null
     * @throws AntiCaptchaException
     */
    protected function sendImage($image)
    {
        $requestFields = [
            'form_params' => [
                'key' => $this->getService()->getApiKey(),
                'method' => 'base64',
                'body' => base64_encode($image),
            ]
        ];

        foreach ($this->getService()->getParams() as $key => $val) {
            $requestFields['form_params'][$key] = (string)$val;
        }

        $url = $this->getService()->getApiUrl() . '/in.php';

        $result = $this->client->request('POST', $url, $requestFields);
        $body = $result->getBody();

        if (stripos($body, 'ERROR') !== false) {
            throw new AntiCaptchaException($body);
        }

        if (stripos($body, 'html') !== false) {
            throw new AntiCaptchaException('Anticaptcha server returned error!');
        }

        if (stripos($body, 'OK') !== false) {
            $ex = explode('|', $body);
            if (trim($ex[0]) == 'OK') {
                return !empty($ex[1]) ? $ex[1] : null;
            }
        }
    }


    /**
     * Method getResult description.
     * @param $captchaId
     *
     * @return string
     * @throws AntiCaptchaException
     */
    protected function getResult($captchaId)
    {
        $this->debug('captcha sent, got captcha ID: ' . $captchaId);

        // Delay, before first captcha check
        $this->debug('waiting for 10 seconds');
        sleep(10);

        $waitTime = 0;

        while (true) {
            $request = $this->client->request('GET', $this->getService()->getApiUrl() . '/res.php', [
                'query' => [
                    'key' => $this->getService()->getApiKey(),
                    'action' => 'get',
                    'id' => $captchaId,
                ]
            ]);

            $body = $request->getBody();

            if (strpos($body, 'ERROR') !== false) {
                throw new AntiCaptchaException("Anticaptcha server returned error: $body");
            }

            if ($body == "CAPCHA_NOT_READY") {
                $this->debug('captcha is not ready yet');

                $waitTime += $this->options['timeout_ready'];

                if ($waitTime > $this->options['timeout_max']) {
                    $this->debug('timelimit (' . $this->options['timeout_max'] . ') hit');
                    break;
                }

                $this->debug('waiting for ' . $this->options['timeout_ready'] . ' seconds');
                sleep($this->options['timeout_ready']);
            } else {
                $ex = explode('|', $body);

                if (trim($ex[0]) == 'OK') {
                    $this->debug('result: ' . $body);

                    return trim($ex[1]);
                }
            }
        }
    }
}
