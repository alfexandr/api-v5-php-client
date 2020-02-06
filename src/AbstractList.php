<?php

namespace SimaLand\API;

use GuzzleHttp\Psr7\Response;
use SimaLand\API\Rest\Client;
use SimaLand\API\Rest\Request;
use SimaLand\API\Rest\ResponseException;
use GuzzleHttp\Exception\RequestException;

/**
 * Абстрактный класс для загрузки данных сущности.
 *
 * Класс реализует интерфейс Iterator.
 *
 * @property $getParams GET параметры запроса.
 */
abstract class AbstractList extends BaseObject implements \Iterator
{
    /**
     * Кол-во потоков.
     *
     * @var int
     */
    public $countThreads = 5;

    /**
     * GET параметр отвечающий за поток.
     *
     * @var string
     */
    public $keyThreads = 'p';

    /**
     * GET параметры запроса.
     *
     * @var array
     */
    protected $_getParams = [];

    /**
     * Кол-во повторов обращение к ресурсу при ошибках.
     *
     * @var int
     */
    public $repeatTimeout = 30;

    /**
     * Время в секундак до следующего обращения к ресурсу.
     *
     * @var int
     */
    public $repeatCount = 30;


    /**
     * SimaLand кдиент для запросов.
     *
     * @var \SimaLand\API\Rest\Client
     */
    private $client;

    /**
     * Список запросов.
     *
     * @var Request[]
     */
    private $requests = [];

    /**
     * Список данных полученные по API.
     *
     * @var array
     */
    private $values = [];

    /**
     * Ключ текущей записи.
     *
     * @var int
     */
    private $key;

    /**
     * Текущая запись.
     *
     * @var mixed
     */
    private $current;

    /**
     * Кол-во итераций. В одной итерации может быть несколько обращений к API, см countThreads.
     *
     * @var int
     */
    private $countIteration = 0;

    /**
     * @param Client $client
     * @param array $options
     */
    public function __construct(Client $client, array $options = [])
    {
        $this->client = $client;
        parent::__construct($options);
    }

    /**
     * Получить наименование сущности.
     *
     * @return string
     */
    abstract public function getEntity();

    /**
     * Добавить get параметры.
     *
     * @param array $params
     * @return AbstractList
     */
    public function addGetParams(array $params)
    {
        $this->setGetParams(array_merge($this->_getParams, $params));
        return $this;
    }

    /**
     * Назначить следующую страницу запросу.
     *
     * @param Request $request
     */
    public function assignPage(Request &$request)
    {
        $currentPage = 1;
        if (!is_array($request->getParams)) {
            $request->getParams = (array)$request->getParams;
        }
        if (isset($request->getParams[$this->keyThreads])) {
            $currentPage = (int)$request->getParams[$this->keyThreads];
        }
        $request->getParams[$this->keyThreads] = $currentPage + $this->countThreads;
    }

    /**
     * Назначить номер потока для запроса.
     *
     * @param Request $request
     * @param int $number
     */
    public function assignThreadsNumber(Request &$request, $number = 0)
    {
        if (!is_array($request->getParams)) {
            $request->getParams = (array)$request->getParams;
        }
        if (!isset($request->getParams[$this->keyThreads])) {
            $request->getParams[$this->keyThreads] = 1;
        }
        $request->getParams[$this->keyThreads] += $number;
    }

    /**
     * Палучить набор данных сущности.
     *
     * @return Response[]
     * @throws \Exception
     */
    public function get()
    {
        return $this->client->batchQuery($this->getRequests());
    }

    /**
     * Установить запросы к API.
     *
     * @param Request[] $requests
     * @throws \Exception
     */
    public function setRequests(array $requests)
    {
        $this->requests = [];
        foreach ($requests as $request) {
            if (!$request instanceof Request) {
                throw new \Exception('Request must be implement "\SimaLand\API\Rest\Request"');
            }
            $this->requests[] = $request;
        }
    }

    /**
     * Получить запросы к API.
     *
     * @return array|Rest\Request[]
     */
    public function getRequests()
    {
        if (empty($this->requests)) {
            $requests = [];
            if (!is_null($this->keyThreads) && $this->countThreads > 1) {
                for ($i = 0; $i < $this->countThreads; $i++) {
                    $requests[$i] = new Request([
                        'entity' => $this->getEntity(),
                        'getParams' => $this->_getParams,
                    ]);
                    $this->assignThreadsNumber($requests[$i], $i);
                }
            } else {
                $requests[] = new Request([
                    'entity' => $this->getEntity(),
                    'getParams' => $this->_getParams,
                ]);
            }
            $this->requests = $requests;
        }
        return $this->requests;
    }

    /**
     * @inheritdoc
     */
    public function current()
    {
        return $this->current;
    }

    /**
     * @inheritdoc
     */
    public function next()
    {
        if (empty($this->values)) {
            $this->getData();
        }
        $this->current = array_shift($this->values);
    }

    /**
     * @inheritdoc
     */
    public function key()
    {
        return $this->key++;
    }

    /**
     * @inheritdoc
     */
    public function valid()
    {
        return !empty($this->current);
    }

    /**
     * @inheritdoc
     */
    public function rewind()
    {
        $this->values = [];
        $this->current = null;
        $this->key = 0;
        $this->next();
    }

    /**
     * Обработка ответов от API.
     *
     * @param Response[] $responses
     * @throws ResponseException
     */
    private function processingResponses(array $responses)
    {
        foreach ($responses as $response) {
            $statusCode = $response->getStatusCode();
            if (($statusCode < 200 || $statusCode >= 300) && $statusCode != 404) {
                throw new ResponseException($response);
            }
        }
    }

    /**
     * Получение ответов от API
     *
     * @return \GuzzleHttp\Psr7\Response[]
     * @throws \Exception
     */
    private function getResponses()
    {
        $i = 0;
        $responses = [];
        $logger = $this->getLogger();
        do {
            $e = null;
            if ($i > 0) {
                $logger->info("Wait time {$this->repeatTimeout} second to the next request");
                sleep($this->repeatTimeout);
                $attempt = $i + 1;
                $logger->info("Attempt {$attempt} of {$this->repeatCount}");
            }
            try {
                $responses = $this->get();
                $this->processingResponses($responses);
            } catch (\Exception $e) {
                if (
                    ($e instanceof RequestException) ||
                    ($e instanceof ResponseException)
                ) {
                    $logger->warning($e->getMessage(), ['code' => $e->getCode()]);
                } else {
                    throw $e;
                }
            }
            $i++;
        } while ($i <= $this->repeatCount && !is_null($e));
        if ($e) {
            $logger->error($e->getMessage(), ['code' => $e->getCode()]);
            throw new Exception($e->getMessage(), $e->getCode(), $e);
        }
        return $responses;
    }

    /**
     * Получить тело ответа от API.
     *
     * @param Response $response
     * @return bool
     */
    private function getBody(Response $response)
    {
        $body = json_decode($response->getBody(), true);
        if (!$body || $response->getStatusCode() != 200) {
            return false;
        }
        return $body;
    }

    /**
     * Получить набор данных от API.
     *
     * @throws Exception
     */
    private function getData()
    {
        $this->countIteration++;
        $responses = $this->getResponses();
        $requests = $this->getRequests();
        foreach ($responses as $key => $response) {
            $body = $this->getBody($response);
            if (!$body) {
                unset($requests[$key]);
                continue;
            }

            $this->values = array_merge($this->values, $body);
            $this->assignPage($requests[$key]);
        }
        $this->setRequests($requests);
    }

    /**
     * Установить GET параметры запроса.
     *
     * @param array $value
     */
    public function setGetParams(array $value)
    {
        $this->_getParams = $value;
    }

    /**
     * Получить GET параметры запроса.
     *
     * @return array
     */
    public function getGetParams()
    {
        return $this->_getParams;
    }

    /**
     * Установить кол-во итераций.
     *
     * @param int $value
     */
    public function setCountIteration($value)
    {
        $this->countIteration = (int)$value;
    }

    /**
     * Получить кол-во итераций.
     *
     * @return int
     */
    public function getCountIteration()
    {
        return $this->countIteration;
    }
}
