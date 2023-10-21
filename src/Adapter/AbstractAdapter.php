<?php

/**
 * @package     Triangle OAuth Plugin
 * @link        https://github.com/Triangle-org/OAuth
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     GNU Affero General Public License, version 3
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace Triangle\OAuth\Adapter;

use ReflectionClass;
use support\Collection;
use support\http\Curl;
use support\http\HttpClientInterface;
use support\Log;
use support\Storage;
use Triangle\OAuth\Exception\HttpClientFailureException;
use Triangle\OAuth\Exception\HttpRequestFailedException;
use Triangle\OAuth\Exception\InvalidArgumentException;
use Triangle\OAuth\Exception\NotImplementedException;
use Triangle\OAuth\Model\Profile;

/**
 * Абстрактный адаптер
 */
abstract class AbstractAdapter implements AdapterInterface
{
    use DataStoreTrait;

    /**
     * ID Провайдера
     *
     * @var string
     */
    protected $providerId = '';

    /**
     * Конфигурация провайдера
     *
     * @var mixed
     */
    protected $config = [];

    /**
     * Параметры провайдера
     *
     * @var array
     */
    protected $params;

    /**
     * Callback URL
     *
     * @var string
     */
    protected $callback = '';

    /**
     * Хранилище
     *
     * @var Storage
     */
    public $storage;

    /**
     * HttpClient
     *
     * @var HttpClientInterface
     */
    public $httpClient;

    /**
     * Логер
     *
     * @var Log
     */
    public $logger;

    /**
     * Проверять ли коды HTTP ответов
     *
     * @var bool
     */
    protected $validateApiResponseHttpCode = true;

    /**
     * Конструктор всех адаптеров
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = new Collection($config);

        $this->providerId = (new ReflectionClass($this))->getShortName();

        $this->httpClient = new Curl();

        if ($this->config->exists('curl_options') && method_exists($this->httpClient, 'setCurlOptions')) {
            $this->httpClient->setCurlOptions($this->config->get('curl_options'));
        }

        $this->storage = new Storage();

        if ($this->config->exists('logger_channel')) {
            $this->logger = Log::channel($this->config->get('logger_channel'));
        } else {
            $this->logger = Log::channel();
        }

        $this->configure();

        $this->logger->debug(sprintf('Инициализация %s: ', get_class($this)));

        $this->initialize();
    }

    /**
     * Load adapter's configuration
     */
    abstract protected function configure();

    /**
     * Adapter initializer
     */
    abstract protected function initialize();

    /**
     * {@inheritdoc}
     */
    abstract public function isConnected(): bool;

    /**
     * {@inheritdoc}
     */
    public function apiRequest(string $url, string $method = 'GET', array $parameters = [], array $headers = [], bool $multipart = false): mixed
    {
        throw new NotImplementedException('Провайдер не поддерживает эту функцию');
    }

    /**
     * {@inheritdoc}
     */
    public function maintainToken()
    {
        // Для Facebook и Instagram
    }

    /**
     * {@inheritdoc}
     */
    public function getUserProfile(): Profile
    {
        throw new NotImplementedException('Провайдер не поддерживает эту функцию');
    }

    /**
     * {@inheritdoc}
     */
    public function getUserContacts(): array
    {
        throw new NotImplementedException('Провайдер не поддерживает эту функцию');
    }

    /**
     * {@inheritdoc}
     */
    public function getUserPages(): array
    {
        throw new NotImplementedException('Провайдер не поддерживает эту функцию');
    }

    /**
     * {@inheritdoc}
     */
    public function getUserActivity(string $stream): array
    {
        throw new NotImplementedException('Провайдер не поддерживает эту функцию');
    }

    /**
     * {@inheritdoc}
     */
    public function setUserStatus(array|string $status): mixed
    {
        throw new NotImplementedException('Провайдер не поддерживает эту функцию');
    }

    /**
     * {@inheritdoc}
     */
    public function setPageStatus(array|string $status, string $pageId): mixed
    {
        throw new NotImplementedException('Провайдер не поддерживает эту функцию');
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        $this->clearStoredData();
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(): array
    {
        $tokenNames = [
            'access_token',
            'access_token_secret',
            'token_type',
            'refresh_token',
            'expires_in',
            'expires_at',
        ];

        $tokens = [];

        foreach ($tokenNames as $name) {
            if ($this->getStoredData($name)) {
                $tokens[$name] = $this->getStoredData($name);
            }
        }

        return $tokens;
    }

    /**
     * {@inheritdoc}
     */
    public function setAccessToken(array $tokens = [])
    {
        $this->clearStoredData();

        foreach ($tokens as $token => $value) {
            $this->storeData($token, $value);
        }

        // Реинициализируем параметры токена
        $this->initialize();
    }

    /**
     * Установка callback URL
     *
     * @param string $callback
     *
     * @throws InvalidArgumentException
     */
    protected function setCallback($callback)
    {
        if (!filter_var($callback, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Требуется действительный URL-адрес обратного вызова');
        }

        $this->callback = $callback;
    }

    /**
     * Установка конечных точек API
     *
     * @param array $endpoints
     */
    protected function setApiEndpoints($endpoints = null)
    {
        if (empty($endpoints)) {
            return;
        }

        $this->apiBaseUrl = $endpoints['api_base_url'] ?: $this->apiBaseUrl;
        $this->authorizeUrl = $endpoints['authorize_url'] ?: $this->authorizeUrl;
        $this->accessTokenUrl = $endpoints['access_token_url'] ?: $this->accessTokenUrl;
    }


    /**
     * Validate signed API responses Http status code.
     *
     * Since the specifics of error responses is beyond the scope of RFC6749 and OAuth Core specifications,
     * OAuth will consider any HTTP status code that is different than '200 OK' as an ERROR.
     *
     * @param string $error String to pre append to message thrown in exception
     *
     * @throws HttpClientFailureException
     * @throws HttpRequestFailedException
     */
    protected function validateApiResponse($error = '')
    {
        $error .= !empty($error) ? '. ' : '';

        if ($this->httpClient->getResponseClientError()) {
            throw new HttpClientFailureException(
                $error . 'Ошибка HTTP: ' . $this->httpClient->getResponseClientError() . '.'
            );
        }

        // if validateApiResponseHttpCode is set to false, we by pass verification of http status code
        if (!$this->validateApiResponseHttpCode) {
            return;
        }

        $status = $this->httpClient->getResponseHttpCode();

        if ($status < 200 || $status > 299) {
            throw new HttpRequestFailedException(
                $error . 'Ошибка HTTP ' . $this->httpClient->getResponseHttpCode() .
                '. Ответ провайдера: ' . $this->httpClient->getResponseBody() . '.'
            );
        }
    }
}