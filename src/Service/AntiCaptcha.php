<?php

namespace AntiCaptcha\Service;

/**
 * Class AntiCaptcha
 * @package AntiCaptcha\Service
 */
class AntiCaptcha extends AbstractService
{
    /** @var string $apiUrl */
    protected $apiUrl = 'http://anti-captcha.com';

    /**
     * Method getParams description.
     *
     * @return array
     */
    public function getParams()
    {
        return array_merge($this->params, [
            'soft_id' => 791
        ]);
    }
}
