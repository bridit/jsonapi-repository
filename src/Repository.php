<?php

namespace Bridit\JsonApiRepository;

use Exception;
use Httpful\Mime;
use Httpful\Request;
use ReflectionClass;
use Httpful\Exception\ConnectionErrorException;
use Bridit\JsonApiDeserializer\JsonApiDeserializer;

/**
 * Class Repository
 * @package Bridit\JsonApiRepository
 * @method static getRepository(?string $uri = null, ?array $headers = null, ?bool $entityResponse = true)
 * @method static with($with)
 * @method static find($id)
 * @method static findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null)
 * @method static findOneBy(array $criteria, ?array $orderBy = null)
 * @method static findAll(?array $orderBy = null)
 * @method static create(array $params)
 * @method static update($id, array $params)
 * @method static delete($id)
 * @method static restore($id)
 */
class Repository
{

  /**
   * @var array
   */
  protected static $allowedMethods = ['getRepository', 'with', 'create', 'update', 'delete', 'restore', 'find', 'findBy', 'findOneBy', 'findAll'];

  /**
   * @var null|string
   */
  protected $uri;

  /**
   * @var null|array
   */
  protected $headers;

  /**
   * @var null|string|array
   */
  protected $with;

  /**
   * @var bool
   */
  protected $fullResponse = false;

  /**
   * @var mixed
   */
  protected $entityDeserializer;

  /**
   * @var bool
   */
  protected $entityResponse = false;

  /**
   * Response with parsed results
   * @var mixed
   */
  protected $response;

  /**
   * Repository constructor.
   */
  public function __construct()
  {
    $this->setRequestTemplate();

    $this->entityDeserializer = JsonApiDeserializer::class;
  }

  /**
   * @param $name
   * @param $arguments
   * @return mixed
   */
  public function __call($name, $arguments)
  {
    if (!in_array($name, self::$allowedMethods)) {
      throw new \BadMethodCallException("Method $name does not exists");
    }

    return call_user_func_array([$this, 'do' . ucfirst($name)], $arguments);
  }

  /**
   * @param $name
   * @param $arguments
   * @return mixed
   */
  public static function __callStatic($name, $arguments)
  {
    if (!in_array($name, self::$allowedMethods)) {
      throw new \BadMethodCallException("Method $name does not exists");
    }

    return call_user_func_array([new static(), $name], $arguments);
  }

  protected function setRequestTemplate()
  {
    $template = Request::init()
      ->expectsJson()
      ->sendsType(Mime::JSON);

    if ($this->getHeaders() !== null) {
      $template->addHeaders($this->getHeaders());
    }

    Request::ini($template);
  }

  /**
   * @return string
   */
  protected function getUri(): string
  {
    return $this->uri;
  }

  /**
   * @return array|null
   */
  protected function getHeaders(): ?array
  {
    return $this->headers;
  }

  /**
   * @return bool|null
   */
  public function getFullResponse(): ?bool
  {
    return $this->fullResponse;
  }

  /**
   * @param bool $fullResponse
   * @return void
   */
  public function setFullResponse(bool $fullResponse): void
  {
    $this->fullResponse = $fullResponse;
  }

  /**
   * @return self
   */
  public function withFullResponse(): self
  {
    $this->fullResponse = true;

    return $this;
  }

  /**
   * @return bool|null
   */
  public function getEntityResponse(): ?bool
  {
    return $this->entityResponse;
  }

  /**
   * @param bool $entityResponse
   * @return void
   */
  public function setEntityResponse(bool $entityResponse): void
  {
    $this->entityResponse = $entityResponse;
  }

  /**
   * @return self
   */
  public function asEntity(): self
  {
    $this->entityResponse = true;

    return $this;
  }

  /**
   * @return self
   */
  public function asJsonApi(): self
  {
    $this->entityResponse = false;

    return $this;
  }

  /**
   * @param $deserializer
   * @return $this
   */
  public function withDeserializer($deserializer): self
  {
    $this->entityDeserializer = $deserializer;

    return $this;
  }

  /**
   * @param string|null $uri
   * @param array|null $headers
   * @return $this
   */
  protected function doGetRepository(?string $uri = null, ?array $headers = null)
  {
    $this->uri = $uri;
    $this->headers = $headers;

    return $this;
  }

  /**
   * @param string|array $with
   * @return $this
   */
  protected function doWith($with)
  {
    $this->with = is_array($with) ? $with : explode(',', $with);

    return $this;
  }

  /**
   * @param string $method
   * @param string $uri
   * @param array $params
   * @return object|array|null
   * @throws ConnectionErrorException|Exception
   */
  protected function doRequest(string $method, string $uri, array $params = [])
  {

    $this->setRequestTemplate();

    switch (strtolower($method))
    {
      case 'get':
        $query = $this->with === null ? $params : array_merge(['include' => implode(',', $this->with)], $params);
        $this->response = Request::get(http_build_url($uri, ["query" => http_build_query($query)], HTTP_URL_JOIN_QUERY))->send();
        break;
      case 'post':
        $params = is_array($params) ? json_encode($params) : $params;
        $this->response = Request::post($uri, $params)->send();
        break;
      case 'put':
        $params = is_array($params) ? json_encode($params) : $params;
        $this->response = Request::put($uri, $params)->send();
        break;
      case 'patch':
        $params = is_array($params) ? json_encode($params) : $params;
        $this->response = Request::patch($uri, $params)->send();
        break;
      case 'delete':
        $this->response = Request::delete($uri)->send();
        break;
      default:
        $this->response = null;
        break;
    }

    return $this->getEntityResponse() ? $this->returnEntity() : $this->returnJsonApi();
  }

  /**
   * @throws \Bridit\JsonApiRepository\HttpException
   * @throws \ReflectionException
   */
  protected function throwException()
  {
    $hasValidationErrors = isset($this->response->body) && isset($this->response->body->errors);

    if (substr((string) $this->response->code, 0, 1) === '2' || $hasValidationErrors) {
      return;
    }

    throw new HttpException('', $this->response->code, $this->getException());
  }

  /**
   * @return Exception
   * @throws \ReflectionException
   */
  protected function getException(): \Exception
  {
    $e = new Exception($this->response->body->message, $this->response->code);
    $reflection = new ReflectionClass($e);

    $trace = json_decode(json_encode($this->response->body->trace), true);
    $prop = $reflection->getProperty('trace');
    $prop->setAccessible('true');
    $prop->setValue($e, $trace);
    $prop->setAccessible('false');

    return $e;
  }

  /**
   * @return mixed
   * @throws Exception
   */
  protected function returnJsonApi()
  {
    $this->throwException();

    return $this->getFullResponse() ?  $this->response : $this->response->body;
  }

  /**
   * @return mixed
   * @throws Exception
   */
  protected function returnEntity()
  {
    $this->throwException();

    if ($this->getFullResponse()) {
      return $this->response;
    }

    if (!is_object($this->response) || !property_exists($this->response, 'body') || !property_exists($this->response->body, 'data')) {
      return $this->response->body;
    }

    $response = (array)  $this->entityDeserializer::deserialize($this->response->body);

    return isset($response['id'])
      ? $this->getEntityAttributes($response)
      : array_map(function($item) {
        return $this->getEntityAttributes($item);
      }, $response);
  }

  protected function getEntityAttributes($entity)
  {
    $entity = (array) $entity;
    $relationships = isset($entity['relationships']) ? (array) $entity['relationships'] : [];
    $entity = array_merge(['id' => $entity['id']], (array) $entity['attributes']);

    if (!empty($relationships)) {
      $entity = array_merge($entity, ['relationships' => $relationships]);
    }

    return (object) $entity;
  }

  /**
   * @param array $params
   * @return object|null
   * @throws ConnectionErrorException
   */
  protected function doCreate(array $params)
  {
    return $this->doRequest('post', $this->getUri(), $params);
  }

  /**
   * @param $id
   * @param array $params
   * @return object|null
   * @throws ConnectionErrorException
   */
  protected function doUpdate($id, array $params)
  {
    $uri = $this->getUri(). '/' . $id;

    return $this->doRequest('put', $uri, $params);
  }

  /**
   * @param $id
   * @return object|null
   * @throws ConnectionErrorException
   */
  protected function doDelete($id)
  {
    $uri = $this->getUri() . '/' . $id;

    return $this->doRequest('delete', $uri);
  }

  /**
   * Finds an entity by its primary key/identifier.
   *
   * @param string|int|array $id
   * @return object|null
   * @throws ConnectionErrorException
   */
  protected function doFind($id)
  {
    if (is_array($id)) {
      return $this->doFindBy(['id' => $id]);
    }

    $uri = $this->getUri() . '/' . $id;

    return $this->doRequest('get', $uri, []);
  }

  /**
   * Finds entities by a set of criteria.
   *
   * @param array $criteria
   * @param array|null $orderBy
   * @param int|null $limit
   * @param int|null $offset
   * @return object|null
   * @throws ConnectionErrorException
   */
  protected function doFindBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null)
  {
    $query = [];

    if ($criteria !== null && $criteria !== []) {
      $query['filter'] = array_map(function ($item) {
        return is_array($item) ? implode(',', $item) : $item;
      }, $criteria);
    }

    if ($orderBy !== null) {

      $sort = [];
      foreach ($orderBy as $name => $direction)
      {
        $sort[] = strtolower($direction) === 'asc' ? $name : '-' . $name;
      }

      $query['sort'] = implode(',', $sort);
    }

    if ($limit !== null) {
      $query['page']['limit'] = $limit;
    }

    if ($offset !== null) {
      $query['page']['offset'] = $offset;
    }

    return $this->doRequest('get', $this->getUri(), $query);
  }

  /**
   * Finds a single entity by a set of criteria.
   *
   * @param array $criteria
   * @param array|null $orderBy
   * @return object|null
   * @throws ConnectionErrorException
   */
  protected function doFindOneBy(array $criteria, ?array $orderBy = null)
  {
    $response = $this->doFindBy($criteria, $orderBy, 1);

    if (is_object($response) && property_exists($response, 'errors')) {
      return $response;
    }

    if (is_object($response) && property_exists($response, 'data')) {
      return ['data' => is_array($response->data) && isset($response->data[0]) ? $response->data[0] : $response->data];
    }

    return isset($response[0]) ? $response[0] : $response;
  }

  /**
   * Finds all entities in the repository.
   *
   * @param array|null $orderBy
   * @return object|null
   * @throws ConnectionErrorException
   */
  protected function doFindAll(?array $orderBy = null)
  {
    return $this->doFindBy([], $orderBy);
  }

}
